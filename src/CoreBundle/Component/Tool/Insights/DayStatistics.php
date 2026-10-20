<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Insights;

use Doctrine\DBAL\Connection;

/**
 * Per-day statistics: streaks, Eddington number and daily distances for moving averages.
 *
 * All values are based on the local date of the activity (start time + timezone offset),
 * summed per day. Distances are kilometers.
 */
class DayStatistics
{
    const KM_PER_MILE = 1.609344;

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
     * @return array[] ['date' => 'Y-m-d', 'km' => float, 'seconds' => int, 'num' => int], ascending by date
     */
    public function days($accountId, $sportId = 0)
    {
        $sql = 'SELECT DATE(DATE_ADD(\'1970-01-01\', INTERVAL (t.`time` + COALESCE(t.`timezone_offset`, 0) * 60) SECOND)) AS `d`,
                    SUM(t.`distance`) AS `km`, SUM(t.`s`) AS `secs`, COUNT(*) AS `num`
                FROM `'.$this->Prefix.'training` t
                WHERE t.`accountid` = ?'.($sportId > 0 ? ' AND t.`sportid` = ?' : '').'
                GROUP BY `d` ORDER BY `d`';
        $rows = $this->Connection->fetchAll($sql, $sportId > 0 ? [$accountId, $sportId] : [$accountId]);
        $days = [];

        foreach ($rows as $row) {
            $days[] = [
                'date' => $row['d'],
                'km' => (float)$row['km'],
                'seconds' => (int)$row['secs'],
                'num' => (int)$row['num'],
            ];
        }

        return $days;
    }

    /**
     * @param string $date Y-m-d
     * @return int days since 1970-01-01
     */
    public static function dayNumber($date)
    {
        return (int)floor(strtotime($date.' 00:00:00 UTC') / 86400);
    }

    /**
     * Consecutive days with at least $minKm kilometers (0 = any activity).
     *
     * @param array[] $days from days()
     * @param float $minKm
     * @param string|null $today Y-m-d, to decide whether the last streak is still running
     * @return array ['streaks' => array[], 'longest' => array|null, 'current' => array|null, 'activeDays' => int]
     */
    public function streaks(array $days, $minKm = 0.0, $today = null)
    {
        $today = $today ?: date('Y-m-d');
        $streaks = [];
        $current = null;
        $activeDays = 0;

        foreach ($days as $day) {
            if ($day['km'] < $minKm || ($minKm <= 0.0 && $day['num'] < 1)) {
                continue;
            }

            ++$activeDays;
            $number = self::dayNumber($day['date']);

            if (null !== $current && $number === $current['endNumber'] + 1) {
                $current['end'] = $day['date'];
                $current['endNumber'] = $number;
                ++$current['days'];
                $current['km'] += $day['km'];
                $current['seconds'] += $day['seconds'];
                continue;
            }

            if (null !== $current) {
                $streaks[] = $current;
            }

            $current = [
                'start' => $day['date'], 'end' => $day['date'], 'endNumber' => $number,
                'days' => 1, 'km' => $day['km'], 'seconds' => $day['seconds'],
            ];
        }

        if (null !== $current) {
            $streaks[] = $current;
        }

        $todayNumber = self::dayNumber($today);
        $running = null;

        if (!empty($streaks)) {
            $last = end($streaks);

            if ($last['endNumber'] >= $todayNumber - 1) {
                $running = $last;
            }
        }

        $longest = null;

        foreach ($streaks as $streak) {
            if (null === $longest || $streak['days'] > $longest['days']) {
                $longest = $streak;
            }
        }

        $sorted = $streaks;
        usort($sorted, function ($a, $b) {
            return $b['days'] <=> $a['days'] ?: strcmp($b['end'], $a['end']);
        });

        return [
            'streaks' => array_slice($sorted, 0, 15),
            'longest' => $longest,
            'current' => $running,
            'activeDays' => $activeDays,
        ];
    }

    /**
     * The Eddington number E is the largest number such that E days with at least E units have been covered.
     *
     * @param array[] $days from days()
     * @param bool $miles use miles instead of kilometers
     * @return array ['number' => int, 'next' => int, 'missing' => int, 'history' => array[], 'counts' => array[]]
     */
    public function eddington(array $days, $miles = false)
    {
        $divisor = $miles ? self::KM_PER_MILE : 1.0;
        $values = [];

        foreach ($days as $day) {
            $values[] = $day['km'] / $divisor;
        }

        $number = self::eddingtonOf($values);
        $next = $number + 1;
        $reaching = 0;

        foreach ($values as $value) {
            if ($value >= $next) {
                ++$reaching;
            }
        }

        // Value of E at the end of each year
        $history = [];
        $byYear = [];

        foreach ($days as $i => $day) {
            $byYear[substr($day['date'], 0, 4)][] = $values[$i];
        }

        $cumulative = [];

        foreach ($byYear as $year => $yearValues) {
            $cumulative = array_merge($cumulative, $yearValues);
            $history[] = ['year' => (int)$year, 'number' => self::eddingtonOf($cumulative)];
        }

        $counts = [];

        for ($n = max(1, $number - 4); $n <= $next + 5; ++$n) {
            $count = 0;

            foreach ($values as $value) {
                if ($value >= $n) {
                    ++$count;
                }
            }

            $counts[] = ['n' => $n, 'days' => $count];
        }

        return [
            'number' => $number,
            'next' => $next,
            'missing' => max(0, $next - $reaching),
            'history' => $history,
            'counts' => $counts,
            'totalDays' => count($values),
        ];
    }

    /**
     * @param float[] $values
     * @return int
     */
    public static function eddingtonOf(array $values)
    {
        rsort($values, SORT_NUMERIC);
        $number = 0;

        foreach ($values as $i => $value) {
            if ($value >= $i + 1) {
                $number = $i + 1;
            } else {
                break;
            }
        }

        return $number;
    }

    /**
     * Series for the moving average chart: one entry per day from the first to the last activity.
     *
     * @param array[] $days from days()
     * @return array ['start' => 'Y-m-d', 'km' => float[]]
     */
    public function dailySeries(array $days)
    {
        if (empty($days)) {
            return ['start' => null, 'km' => []];
        }

        $first = self::dayNumber($days[0]['date']);
        $last = max(self::dayNumber(end($days)['date']), self::dayNumber(date('Y-m-d')));
        $series = array_fill(0, $last - $first + 1, 0.0);

        foreach ($days as $day) {
            $series[self::dayNumber($day['date']) - $first] = round($day['km'], 3);
        }

        return ['start' => $days[0]['date'], 'km' => $series];
    }
}
