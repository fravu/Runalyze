<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Segment;

use Doctrine\DBAL\Connection;
use Runalyze\Bundle\CoreBundle\Component\Tool\Geo\GeoUtil;

/**
 * Segments: a part of a route that is compared across all activities passing it.
 *
 * An activity passes a segment if it comes closer than START_END_RADIUS_M to its
 * start, later to its end (after 70-150 % of the segment's length) and if at least
 * MIN_COVERAGE of the segment's points are within COVERAGE_RADIUS_M of the activity
 * in between.
 */
class SegmentStore
{
    const START_END_RADIUS_M = 25.0;
    const COVERAGE_RADIUS_M = 30.0;
    const MIN_COVERAGE = 0.9;
    const POLYLINE_SPACING_M = 15.0;
    const BBOX_MARGIN_DEG = 0.001;

    /** @var Connection */
    protected $Connection;

    /** @var string */
    protected $Prefix;

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
     * @param int $activityId
     * @param int $from index
     * @param int $to index
     * @param string $name
     * @param bool $onlyThisSport
     * @return int|null id of the new segment
     */
    public function create($accountId, $activityId, $from, $to, $name, $onlyThisSport)
    {
        $track = GeoUtil::loadTrack($this->Connection, $this->Prefix, $activityId, $accountId);

        if (null === $track) {
            return null;
        }

        $num = count($track['lat']);
        $from = max(0, min($num - 1, (int)$from));
        $to = max(0, min($num - 1, (int)$to));

        if ($to <= $from) {
            return null;
        }

        $polyline = [];
        $last = null;

        for ($i = $from; $i <= $to; ++$i) {
            if (null === $track['lat'][$i]) {
                continue;
            }

            $point = [round($track['lat'][$i], 6), round($track['lng'][$i], 6)];

            if (null === $last || GeoUtil::quickDistance($last[0], $last[1], $point[0], $point[1]) >= self::POLYLINE_SPACING_M || $i == $to) {
                $polyline[] = $point;
                $last = $point;
            }
        }

        if (count($polyline) < 2) {
            return null;
        }

        $lats = array_column($polyline, 0);
        $lngs = array_column($polyline, 1);
        $distance = $track['dist'][$to] - $track['dist'][$from];

        if ($distance < 0.05) {
            return null;
        }

        $profile = [];
        $gain = 0;

        if (!empty($track['elev'])) {
            foreach (GeoUtil::sampleIndices($from, $to, 200) as $i) {
                $profile[] = [round($track['dist'][$i] - $track['dist'][$from], 3), round($track['elev'][$i])];
            }

            $gain = (int)round($track['elev'][$to] - $track['elev'][$from]);
        }

        $this->Connection->insert($this->Prefix.'segment', [
            'accountid' => (int)$accountId,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : $track['title'], 0, 100),
            'sportid' => $onlyThisSport ? $track['sportid'] : null,
            'distance' => round($distance, 3),
            'gain' => $gain,
            'avg_grade' => round($gain / ($distance * 1000) * 100, 1),
            'start_lat' => $polyline[0][0],
            'start_lng' => $polyline[0][1],
            'end_lat' => end($polyline)[0],
            'end_lng' => end($polyline)[1],
            'min_lat' => min($lats),
            'min_lng' => min($lngs),
            'max_lat' => max($lats),
            'max_lng' => max($lngs),
            'polyline' => json_encode($polyline),
            'profile' => json_encode($profile),
            'created' => time(),
            'scanned_until' => 0,
        ]);

        return (int)$this->Connection->lastInsertId();
    }

    /**
     * @param int $accountId
     * @param int $segmentId
     * @return array|false
     */
    public function find($accountId, $segmentId)
    {
        return $this->Connection->fetchAssoc(
            'SELECT * FROM `'.$this->Prefix.'segment` WHERE `id` = ? AND `accountid` = ?',
            [(int)$segmentId, (int)$accountId]
        );
    }

    /**
     * @param int $accountId
     * @return array[]
     */
    public function all($accountId)
    {
        return $this->Connection->fetchAll(
            'SELECT s.`id`, s.`name`, s.`sportid`, s.`distance`, s.`gain`, s.`avg_grade`, sp.`name` AS `sportname`, sp.`img` AS `sporticon`,
                    COUNT(e.`id`) AS `efforts`, MIN(e.`duration`) AS `best`, MAX(e.`time`) AS `last`
             FROM `'.$this->Prefix.'segment` s
             LEFT JOIN `'.$this->Prefix.'sport` sp ON sp.`id` = s.`sportid`
             LEFT JOIN `'.$this->Prefix.'segment_effort` e ON e.`segmentid` = s.`id`
                 AND EXISTS (SELECT 1 FROM `'.$this->Prefix.'training` t WHERE t.`id` = e.`activityid`)
             WHERE s.`accountid` = ?
             GROUP BY s.`id`
             ORDER BY s.`name`',
            [(int)$accountId]
        );
    }

    /**
     * @param int $accountId
     * @param int $segmentId
     * @return array[] fastest first
     */
    public function efforts($accountId, $segmentId)
    {
        return $this->Connection->fetchAll(
            'SELECT e.*, t.`sportid`, sp.`name` AS `sportname`, sp.`img` AS `sporticon`
             FROM `'.$this->Prefix.'segment_effort` e
             JOIN `'.$this->Prefix.'training` t ON t.`id` = e.`activityid`
             LEFT JOIN `'.$this->Prefix.'sport` sp ON sp.`id` = t.`sportid`
             WHERE e.`segmentid` = ? AND e.`accountid` = ?
             ORDER BY e.`duration` IS NULL, e.`duration`, e.`time`',
            [(int)$segmentId, (int)$accountId]
        );
    }

    /**
     * @param int $accountId
     * @param int $segmentId
     */
    public function delete($accountId, $segmentId)
    {
        $this->Connection->executeUpdate('DELETE FROM `'.$this->Prefix.'segment_effort` WHERE `segmentid` = ? AND `accountid` = ?', [(int)$segmentId, (int)$accountId]);
        $this->Connection->executeUpdate('DELETE FROM `'.$this->Prefix.'segment` WHERE `id` = ? AND `accountid` = ?', [(int)$segmentId, (int)$accountId]);
    }

    /**
     * @param int $accountId
     * @param int $segmentId
     * @param string $name
     */
    public function rename($accountId, $segmentId, $name)
    {
        if (trim($name) !== '') {
            $this->Connection->update($this->Prefix.'segment', ['name' => mb_substr(trim($name), 0, 100)], ['id' => (int)$segmentId, 'accountid' => (int)$accountId]);
        }
    }

    /**
     * @param int $accountId
     * @return int number of activities not yet checked against all segments
     */
    public function countPending($accountId)
    {
        $min = $this->Connection->fetchColumn('SELECT MIN(`scanned_until`) FROM `'.$this->Prefix.'segment` WHERE `accountid` = ?', [(int)$accountId]);

        if (null === $min || false === $min) {
            return 0;
        }

        return (int)$this->Connection->fetchColumn(
            'SELECT COUNT(*) FROM `'.$this->Prefix.'training` t JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
             WHERE t.`accountid` = ? AND t.`id` > ? AND r.`geohashes` IS NOT NULL AND r.`geohashes` != ""',
            [(int)$accountId, (int)$min]
        );
    }

    /**
     * Check new activities against all segments until the time budget is used up
     *
     * @param int $accountId
     * @param float $timeBudget seconds
     * @return bool true if everything is done
     */
    public function scan($accountId, $timeBudget = 15.0)
    {
        $startTime = microtime(true);
        $segments = $this->Connection->fetchAll('SELECT * FROM `'.$this->Prefix.'segment` WHERE `accountid` = ? ORDER BY `scanned_until`', [(int)$accountId]);

        foreach ($segments as $segment) {
            $remainingBudget = $timeBudget - (microtime(true) - $startTime);

            if ($remainingBudget <= 0 || !$this->scanSegment($accountId, $segment, $remainingBudget)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $accountId
     * @param int $segmentId
     */
    public function resetScan($accountId, $segmentId)
    {
        $this->Connection->executeUpdate('DELETE FROM `'.$this->Prefix.'segment_effort` WHERE `segmentid` = ? AND `accountid` = ?', [(int)$segmentId, (int)$accountId]);
        $this->Connection->update($this->Prefix.'segment', ['scanned_until' => 0], ['id' => (int)$segmentId, 'accountid' => (int)$accountId]);
    }

    /**
     * @return bool true if all activities have been checked
     */
    private function scanSegment($accountId, array $segment, $timeBudget)
    {
        $startTime = microtime(true);
        $params = [(int)$accountId, (int)$segment['scanned_until']];
        $sportCondition = '';

        if (null !== $segment['sportid']) {
            $sportCondition = ' AND t.`sportid` = ?';
            $params[] = (int)$segment['sportid'];
        }

        $rows = $this->Connection->fetchAll(
            'SELECT t.`id`, r.`min`, r.`max`
             FROM `'.$this->Prefix.'training` t JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
             WHERE t.`accountid` = ? AND t.`id` > ? AND r.`geohashes` IS NOT NULL AND r.`geohashes` != ""'.$sportCondition.'
             ORDER BY t.`id`',
            $params
        );

        $scannedUntil = (int)$segment['scanned_until'];
        $polyline = json_decode($segment['polyline'], true);
        $complete = true;

        foreach ($rows as $row) {
            if ($this->boundingBoxesIntersect($segment, $row['min'], $row['max'])) {
                $this->matchActivity($accountId, $segment, $polyline, (int)$row['id']);
            }

            $scannedUntil = (int)$row['id'];

            if (microtime(true) - $startTime > $timeBudget) {
                $complete = $scannedUntil == (int)end($rows)['id'];
                break;
            }
        }

        $maxId = (int)$this->Connection->fetchColumn('SELECT MAX(`id`) FROM `'.$this->Prefix.'training` WHERE `accountid` = ?', [(int)$accountId]);
        $this->Connection->update($this->Prefix.'segment', ['scanned_until' => $complete ? max($scannedUntil, $maxId) : $scannedUntil], ['id' => (int)$segment['id']]);

        return $complete;
    }

    /**
     * @param array $segment
     * @param string|null $minHash
     * @param string|null $maxHash
     * @return bool
     */
    private function boundingBoxesIntersect(array $segment, $minHash, $maxHash)
    {
        if (null === $minHash || null === $maxHash || '' === $minHash) {
            return true;
        }

        list($minLat, $minLng) = GeoUtil::decode($minHash);
        list($maxLat, $maxLng) = GeoUtil::decode($maxHash);
        $m = self::BBOX_MARGIN_DEG;

        return !($maxLat < $segment['min_lat'] - $m || $minLat > $segment['max_lat'] + $m || $maxLng < $segment['min_lng'] - $m || $minLng > $segment['max_lng'] + $m);
    }

    private function matchActivity($accountId, array $segment, array $polyline, $activityId)
    {
        $track = GeoUtil::loadTrack($this->Connection, $this->Prefix, $activityId, $accountId);

        if (null === $track) {
            return;
        }

        foreach ($this->findPasses($track, $segment, $polyline) as $pass) {
            list($from, $to) = $pass;
            $duration = null;
            $avgHr = null;

            if (!empty($track['time']) && $track['time'][$to] > $track['time'][$from]) {
                $duration = (int)round($track['time'][$to] - $track['time'][$from]);
            }

            if (!empty($track['hr'])) {
                $slice = array_filter(array_slice($track['hr'], $from, $to - $from + 1));
                $avgHr = empty($slice) ? null : (int)round(array_sum($slice) / count($slice));
            }

            $this->Connection->executeUpdate(
                'INSERT IGNORE INTO `'.$this->Prefix.'segment_effort`
                 (`segmentid`, `accountid`, `activityid`, `time`, `duration`, `start_index`, `end_index`, `avg_hr`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [(int)$segment['id'], (int)$accountId, $activityId, $track['start'] + (empty($track['time']) ? 0 : (int)$track['time'][$from]), $duration, $from, $to, $avgHr]
            );
        }
    }

    /**
     * @param array $track
     * @param array $segment
     * @param array $polyline [[lat, lng], ...]
     * @return array[] [[fromIndex, toIndex], ...]
     */
    public function findPasses(array $track, array $segment, array $polyline)
    {
        $lat = $track['lat'];
        $lng = $track['lng'];
        $dist = $track['dist'];
        $num = count($lat);
        $length = (float)$segment['distance'];
        $sLat = (float)$segment['start_lat']; $sLng = (float)$segment['start_lng'];
        $eLat = (float)$segment['end_lat']; $eLng = (float)$segment['end_lng'];
        $r = self::START_END_RADIUS_M;
        $passes = [];
        $i = 0;

        $distTo = function ($k, $pLat, $pLng) use ($lat, $lng) {
            return null === $lat[$k] ? INF : GeoUtil::quickDistance($lat[$k], $lng[$k], $pLat, $pLng);
        };

        while ($i < $num) {
            if (null === $lat[$i] || abs($lat[$i] - $sLat) > 0.001 || $distTo($i, $sLat, $sLng) > $r) {
                ++$i;
                continue;
            }

            $best = $i;

            while ($i + 1 < $num && $distTo($i + 1, $sLat, $sLng) <= $r) {
                ++$i;

                if ($distTo($i, $sLat, $sLng) < $distTo($best, $sLat, $sLng)) {
                    $best = $i;
                }
            }

            $end = null;

            for ($j = $best + 1; $j < $num && $dist[$j] - $dist[$best] <= $length * 1.5 + 0.1; ++$j) {
                if ($dist[$j] - $dist[$best] >= $length * 0.7 && $distTo($j, $eLat, $eLng) <= $r) {
                    $end = $j;

                    while ($j + 1 < $num && $distTo($j + 1, $eLat, $eLng) <= $r) {
                        ++$j;

                        if ($distTo($j, $eLat, $eLng) < $distTo($end, $eLat, $eLng)) {
                            $end = $j;
                        }
                    }

                    break;
                }
            }

            if (null !== $end && $this->coverage($lat, $lng, $best, $end, $polyline) >= self::MIN_COVERAGE) {
                $passes[] = [$best, $end];
                $i = $end + 1;
            } else {
                ++$i;
            }
        }

        return $passes;
    }

    /**
     * @return float share of polyline points close to the activity between $from and $to
     */
    private function coverage(array $lat, array $lng, $from, $to, array $polyline)
    {
        $hits = 0;
        $k = $from;

        foreach ($polyline as $point) {
            $found = false;

            for ($m = max($from, $k - 30); $m <= $to; ++$m) {
                if (null !== $lat[$m] && GeoUtil::quickDistance($lat[$m], $lng[$m], $point[0], $point[1]) <= self::COVERAGE_RADIUS_M) {
                    $found = true;
                    $k = $m;
                    break;
                }
            }

            if ($found) {
                ++$hits;
            }
        }

        return $hits / max(1, count($polyline));
    }
}
