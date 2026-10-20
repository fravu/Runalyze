<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Insights;

use Doctrine\DBAL\Connection;

/**
 * Best average values over fixed durations (power, heart rate, pace) from trackdata.
 *
 * Trackdata is stored as pipe separated series: `time` (cumulative seconds), `distance` (cumulative km),
 * `heartrate`, `power`. The series are resampled to one value per second.
 */
class PerformanceCurve
{
    const METRIC_POWER = 'power';
    const METRIC_HEART_RATE = 'heartrate';
    const METRIC_PACE = 'pace';

    /** @var int[] seconds */
    const DURATIONS = [1, 5, 10, 30, 60, 120, 300, 600, 1200, 1800, 3600];

    /** @var int average speed (km/h) above which an activity is not used for the pace curve */
    const MAX_PLAUSIBLE_RUNNING_SPEED_KMH = 25;

    /** @var float highest speed (km/h) that counts for the pace curve */
    const MAX_PACE_SPEED_KMH = 30.0;

    /** @var int shortest window (seconds) for the pace curve, GPS jitter dominates below */
    const MIN_PACE_DURATION = 30;

    /** @var int maximum number of activities per calculation */
    const MAX_ACTIVITIES = 120;

    /** @var Connection */
    private $Connection;

    /** @var string */
    private $Prefix;

    /**
     * @param Connection $connection
     * @param string $prefix
     */
    public function __construct(Connection $connection, $prefix)
    {
        $this->Connection = $connection;
        $this->Prefix = $prefix;
    }

    /**
     * @param int $accountId
     * @param int $sportId 0 for all sports
     * @param int $days look back this many days, 0 for all time
     * @param string $metric
     * @param float $timeBudget seconds
     * @return array ['rows' => array[], 'activities' => int, 'used' => int, 'truncated' => bool]
     */
    public function calculate($accountId, $sportId, $days, $metric, $timeBudget = 25.0)
    {
        if (!in_array($metric, [self::METRIC_POWER, self::METRIC_HEART_RATE, self::METRIC_PACE], true)) {
            throw new \InvalidArgumentException('Unknown metric');
        }

        $start = microtime(true);
        $column = self::METRIC_PACE === $metric ? 'distance' : $metric;
        $params = [$accountId];
        $where = 't.`accountid` = ? AND tr.`'.$column.'` IS NOT NULL AND tr.`'.$column.'` != \'\'';

        if (self::METRIC_POWER === $metric) {
            // calculated power (from speed and elevation) is not a measurement
            $where .= ' AND t.`is_power_calculated` = 0';
        } elseif (self::METRIC_PACE === $metric) {
            // same plausibility limit as for the marathon shape: faster "runs" are other activities
            $where .= ' AND t.`s` > 0 AND t.`distance` / (t.`s` / 3600) <= '.self::MAX_PLAUSIBLE_RUNNING_SPEED_KMH;
        }

        if ($sportId > 0) {
            $where .= ' AND t.`sportid` = ?';
            $params[] = $sportId;
        }

        if ($days > 0) {
            $where .= ' AND t.`time` >= ?';
            $params[] = time() - $days * 86400;
        }

        $total = (int)$this->Connection->fetchColumn(
            'SELECT COUNT(*) FROM `'.$this->Prefix.'training` t
             JOIN `'.$this->Prefix.'trackdata` tr ON tr.`activityid` = t.`id` WHERE '.$where,
            $params
        );

        $ids = $this->Connection->fetchAll(
            'SELECT t.`id` FROM `'.$this->Prefix.'training` t
             JOIN `'.$this->Prefix.'trackdata` tr ON tr.`activityid` = t.`id`
             WHERE '.$where.' ORDER BY t.`time` DESC LIMIT '.self::MAX_ACTIVITIES,
            $params
        );

        $best = [];
        $used = 0;
        $truncated = $total > self::MAX_ACTIVITIES;

        foreach ($ids as $idRow) {
            if (microtime(true) - $start > $timeBudget) {
                $truncated = true;
                break;
            }

            $row = $this->Connection->fetchAssoc(
                'SELECT t.`id`, t.`time` AS `start`, t.`title`, tr.`time` AS `ttime`, tr.`'.$column.'` AS `series`
                 FROM `'.$this->Prefix.'training` t
                 JOIN `'.$this->Prefix.'trackdata` tr ON tr.`activityid` = t.`id` WHERE t.`id` = ?',
                [$idRow['id']]
            );

            if (!$row) {
                continue;
            }

            $perSecond = $this->resample($row['ttime'], $row['series'], self::METRIC_PACE === $metric, $metric);

            if (null === $perSecond) {
                continue;
            }

            ++$used;

            foreach (self::DURATIONS as $duration) {
                if (self::METRIC_PACE === $metric && $duration < self::MIN_PACE_DURATION) {
                    continue;
                }

                $value = self::METRIC_PACE === $metric
                    ? $this->bestDistanceWindow($perSecond, $duration)
                    : $this->bestAverageWindow($perSecond, $duration);

                if (null === $value) {
                    continue;
                }

                if (!isset($best[$duration]) || $value > $best[$duration]['value']) {
                    $best[$duration] = [
                        'value' => $value,
                        'id' => (int)$row['id'],
                        'start' => (int)$row['start'],
                        'title' => $row['title'],
                    ];
                }
            }
        }

        $rows = [];

        foreach (self::DURATIONS as $duration) {
            if (!isset($best[$duration])) {
                continue;
            }

            $value = $best[$duration]['value'];
            $rows[] = [
                'duration' => $duration,
                'value' => self::METRIC_PACE === $metric ? 3600.0 / max($value, 0.001) : $value,
                'speed' => self::METRIC_PACE === $metric ? $value : null,
                'activity' => $best[$duration]['id'],
                'date' => $best[$duration]['start'],
                'title' => $best[$duration]['title'],
            ];
        }

        return ['rows' => $rows, 'activities' => $total, 'used' => $used, 'truncated' => $truncated];
    }

    /**
     * @param string $timeString pipe separated cumulative seconds
     * @param string $seriesString pipe separated values
     * @param bool $interpolate linear interpolation (cumulative values) instead of carrying values forward
     * @param string $metric implausible values (device errors) are ignored and replaced by the previous value
     * @return float[]|null one value per second, starting at second 0
     */
    private function resample($timeString, $seriesString, $interpolate, $metric)
    {
        $time = explode('|', (string)$timeString);
        $series = explode('|', (string)$seriesString);
        $count = min(count($time), count($series));

        if ($count < 10) {
            return null;
        }

        $seconds = (int)$time[$count - 1];

        if ($seconds < 2 || $seconds > 86400) {
            return null;
        }

        $result = array_fill(0, $seconds + 1, 0.0);
        $index = 0;
        $last = null;

        for ($s = 0; $s <= $seconds; ++$s) {
            while ($index + 1 < $count && (int)$time[$index + 1] <= $s) {
                ++$index;
            }

            if ($interpolate) {
                $t0 = (int)$time[$index];
                $v0 = (float)$series[$index];

                if ($index + 1 < $count && (int)$time[$index + 1] > $t0) {
                    $t1 = (int)$time[$index + 1];
                    $v1 = (float)$series[$index + 1];
                    $result[$s] = $v0 + ($v1 - $v0) * ($s - $t0) / ($t1 - $t0);
                } else {
                    $result[$s] = $v0;
                }
            } else {
                $value = $series[$index];

                if ('' !== $value && null !== $value && self::isPlausible((float)$value, $metric)) {
                    $last = (float)$value;
                }

                $result[$s] = null === $last ? 0.0 : $last;
            }
        }

        return $result;
    }

    /**
     * @param float $value
     * @param string $metric
     * @return bool
     */
    private static function isPlausible($value, $metric)
    {
        if (self::METRIC_POWER === $metric) {
            return $value >= 0.0 && $value <= 2500.0;
        }

        if (self::METRIC_HEART_RATE === $metric) {
            return $value >= 30.0 && $value <= 250.0;
        }

        return true;
    }

    /**
     * @param float[] $values one value per second
     * @param int $duration
     * @return float|null highest average over $duration seconds
     */
    private function bestAverageWindow(array $values, $duration)
    {
        $n = count($values);

        if ($n < $duration) {
            return null;
        }

        $sum = 0.0;

        for ($i = 0; $i < $duration; ++$i) {
            $sum += $values[$i];
        }

        $max = $sum;

        for ($i = $duration; $i < $n; ++$i) {
            $sum += $values[$i] - $values[$i - $duration];

            if ($sum > $max) {
                $max = $sum;
            }
        }

        return $max / $duration;
    }

    /**
     * @param float[] $distance cumulative kilometers per second
     * @param int $duration
     * @return float|null highest speed in km/h over $duration seconds
     */
    private function bestDistanceWindow(array $distance, $duration)
    {
        $n = count($distance);

        if ($n <= $duration) {
            return null;
        }

        $max = 0.0;

        for ($i = $duration; $i < $n; ++$i) {
            $delta = $distance[$i] - $distance[$i - $duration];

            // jumps in the cumulative distance (GPS errors) would give unrealistic speeds, 30 km/h is above sustainable running speed
            if ($delta > $max && $delta / $duration * 3600.0 <= self::MAX_PACE_SPEED_KMH) {
                $max = $delta;
            }
        }

        return $max > 0.0 ? $max / $duration * 3600.0 : null;
    }
}
