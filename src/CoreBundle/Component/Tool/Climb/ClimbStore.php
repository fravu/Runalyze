<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Climb;

use Doctrine\DBAL\Connection;
use Runalyze\Bundle\CoreBundle\Component\Tool\Geo\GeoUtil;
use Runalyze\Profile\Sport\SportProfile;

/**
 * Stores detected climbs and groups identical climbs of different activities
 */
class ClimbStore
{
    /** @var float start and end of two climbs must be closer than this to count as the same climb */
    const SAME_CLIMB_RADIUS_M = 75.0;

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
     * @return int number of activities with gps and elevation that still need to be analysed
     */
    public function countPending($accountId)
    {
        return (int)$this->Connection->fetchColumn(
            'SELECT COUNT(*) '.$this->pendingFromWhere(),
            [(int)$accountId, ClimbDetector::VERSION]
        );
    }

    /**
     * Analyse pending activities until the time budget is used up
     *
     * @param int $accountId
     * @param float $timeBudget seconds
     * @return int number of analysed activities
     */
    public function scanPending($accountId, $timeBudget = 15.0)
    {
        $startTime = microtime(true);
        $this->removeOrphans($accountId);
        $cyclingSports = $this->cyclingSportIds($accountId);
        $done = 0;

        do {
            $ids = $this->Connection->fetchAll(
                'SELECT t.`id` '.$this->pendingFromWhere().' ORDER BY t.`id` LIMIT 50',
                [(int)$accountId, ClimbDetector::VERSION]
            );

            foreach ($ids as $row) {
                $this->scanActivity((int)$row['id'], $accountId, $cyclingSports);
                ++$done;

                if (microtime(true) - $startTime > $timeBudget) {
                    return $done;
                }
            }
        } while (!empty($ids));

        return $done;
    }

    /**
     * @param int $accountId
     */
    public function reset($accountId)
    {
        $this->Connection->executeUpdate('DELETE FROM `'.$this->Prefix.'climb` WHERE `accountid` = ?', [(int)$accountId]);
        $this->Connection->executeUpdate('DELETE FROM `'.$this->Prefix.'climb_scan` WHERE `accountid` = ?', [(int)$accountId]);
    }

    /**
     * @param int $activityId
     * @param int $accountId
     * @param int[] $cyclingSports
     */
    public function scanActivity($activityId, $accountId, array $cyclingSports)
    {
        $this->Connection->executeUpdate('DELETE FROM `'.$this->Prefix.'climb` WHERE `activityid` = ? AND `accountid` = ?', [$activityId, (int)$accountId]);

        $track = GeoUtil::loadTrack($this->Connection, $this->Prefix, $activityId, $accountId);

        if (null !== $track && !empty($track['elev'])) {
            $climbs = (new ClimbDetector())->detect($track['dist'], $track['elev'], in_array($track['sportid'], $cyclingSports));

            foreach ($climbs as $climb) {
                $this->insertClimb($climb, $track, $activityId, $accountId);
            }
        }

        $this->Connection->executeUpdate(
            'REPLACE INTO `'.$this->Prefix.'climb_scan` (`activityid`, `accountid`, `version`) VALUES (?, ?, ?)',
            [$activityId, (int)$accountId, ClimbDetector::VERSION]
        );
    }

    /**
     * @param int $accountId
     * @param array $filter ['sport' => int|null, 'year' => int|null, 'category' => string|null]
     * @return array[] groups, sorted by score
     */
    public function groups($accountId, array $filter = [])
    {
        $where = 'c.`accountid` = ?';
        $params = [(int)$accountId];

        if (!empty($filter['sport'])) {
            $where .= ' AND c.`sportid` = ?';
            $params[] = (int)$filter['sport'];
        }

        if (!empty($filter['year'])) {
            $where .= ' AND c.`time` BETWEEN UNIX_TIMESTAMP(?) AND UNIX_TIMESTAMP(?) - 1';
            $params[] = (int)$filter['year'].'-01-01';
            $params[] = ((int)$filter['year'] + 1).'-01-01';
        }

        $climbs = $this->Connection->fetchAll(
            'SELECT c.*, t.`title`, r.`name` AS `routename`, s.`name` AS `sportname`, s.`img` AS `sporticon`
             FROM `'.$this->Prefix.'climb` c
             JOIN `'.$this->Prefix.'training` t ON t.`id` = c.`activityid`
             LEFT JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
             LEFT JOIN `'.$this->Prefix.'sport` s ON s.`id` = c.`sportid`
             WHERE '.$where.'
             ORDER BY c.`time` DESC',
            $params
        );

        $groups = $this->groupClimbs($climbs);

        if (!empty($filter['category'])) {
            $groups = array_values(array_filter($groups, function ($group) use ($filter) {
                return $group['category'] == $filter['category'];
            }));
        }

        return $groups;
    }

    /**
     * @param int $accountId
     * @param int $climbId any climb of the group
     * @return array|null
     */
    public function groupFor($accountId, $climbId)
    {
        $climb = $this->Connection->fetchAssoc(
            'SELECT `sportid` FROM `'.$this->Prefix.'climb` WHERE `id` = ? AND `accountid` = ?',
            [(int)$climbId, (int)$accountId]
        );

        if (false === $climb) {
            return null;
        }

        foreach ($this->groups($accountId) as $group) {
            foreach ($group['climbs'] as $member) {
                if ($member['id'] == $climbId) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * @param int $accountId
     * @param int $climbId
     * @return array|false
     */
    public function find($accountId, $climbId)
    {
        return $this->Connection->fetchAssoc(
            'SELECT * FROM `'.$this->Prefix.'climb` WHERE `id` = ? AND `accountid` = ?',
            [(int)$climbId, (int)$accountId]
        );
    }

    /**
     * @param int $accountId
     * @return int[]
     */
    public function cyclingSportIds($accountId)
    {
        $rows = $this->Connection->fetchAll(
            'SELECT `id` FROM `'.$this->Prefix.'sport` WHERE `accountid` = ? AND `internal_sport_id` IN (?, ?)',
            [(int)$accountId, SportProfile::CYCLING, SportProfile::E_MTB_MOUNTAIN]
        );

        return array_map(function ($row) { return (int)$row['id']; }, $rows);
    }

    /**
     * @return string
     */
    private function pendingFromWhere()
    {
        return 'FROM `'.$this->Prefix.'training` t
            JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
            LEFT JOIN `'.$this->Prefix.'climb_scan` cs ON cs.`activityid` = t.`id`
            WHERE t.`accountid` = ?
              AND r.`geohashes` IS NOT NULL AND r.`geohashes` != ""
              AND (r.`elevations_original` IS NOT NULL OR r.`elevations_corrected` IS NOT NULL)
              AND (cs.`activityid` IS NULL OR cs.`version` < ?)';
    }

    /**
     * @param int $accountId
     */
    private function removeOrphans($accountId)
    {
        $this->Connection->executeUpdate(
            'DELETE c FROM `'.$this->Prefix.'climb` c LEFT JOIN `'.$this->Prefix.'training` t ON t.`id` = c.`activityid`
             WHERE c.`accountid` = ? AND t.`id` IS NULL',
            [(int)$accountId]
        );
        $this->Connection->executeUpdate(
            'DELETE cs FROM `'.$this->Prefix.'climb_scan` cs LEFT JOIN `'.$this->Prefix.'training` t ON t.`id` = cs.`activityid`
             WHERE cs.`accountid` = ? AND t.`id` IS NULL',
            [(int)$accountId]
        );
    }

    private function insertClimb(array $climb, array $track, $activityId, $accountId)
    {
        $from = $climb['start'];
        $to = $climb['end'];
        $start = $this->firstCoordinate($track, $from, $to);
        $end = $this->firstCoordinate($track, $to, $from);

        if (null === $start || null === $end) {
            return;
        }

        $duration = null;
        $vam = null;
        $avgHr = null;

        if (!empty($track['time']) && $track['time'][$to] > $track['time'][$from]) {
            $duration = (int)round($track['time'][$to] - $track['time'][$from]);
            $vam = min(65535, (int)round($climb['gain'] / $duration * 3600));
        }

        if (!empty($track['hr'])) {
            $slice = array_filter(array_slice($track['hr'], $from, $to - $from + 1));
            $avgHr = empty($slice) ? null : (int)round(array_sum($slice) / count($slice));
        }

        $this->Connection->insert($this->Prefix.'climb', [
            'accountid' => (int)$accountId,
            'activityid' => (int)$activityId,
            'sportid' => $track['sportid'],
            'time' => $track['start'] + (empty($track['time']) ? 0 : (int)$track['time'][$from]),
            'start_index' => $from,
            'end_index' => $to,
            'start_lat' => $start[0],
            'start_lng' => $start[1],
            'end_lat' => $end[0],
            'end_lng' => $end[1],
            'distance' => $climb['distance'],
            'gain' => min(65535, $climb['gain']),
            'avg_grade' => $climb['avg_grade'],
            'max_grade' => min(999, $climb['max_grade']),
            'score' => $climb['score'],
            'category' => $climb['category'],
            'duration' => $duration,
            'vam' => $vam,
            'avg_hr' => $avgHr,
        ]);
    }

    /**
     * First valid coordinate from $index towards $towards
     *
     * @return float[]|null
     */
    private function firstCoordinate(array $track, $index, $towards)
    {
        $step = $towards >= $index ? 1 : -1;

        for ($i = $index; $step > 0 ? $i <= $towards : $i >= $towards; $i += $step) {
            if (null !== $track['lat'][$i]) {
                return [$track['lat'][$i], $track['lng'][$i]];
            }
        }

        return null;
    }

    /**
     * Group climbs whose start and end points are close (same sport not required)
     *
     * @param array[] $climbs
     * @return array[]
     */
    private function groupClimbs(array $climbs)
    {
        $groups = [];
        $buckets = [];

        foreach ($climbs as $climb) {
            $key = round($climb['start_lat'], 2).'_'.round($climb['start_lng'], 2);
            $found = null;

            foreach ($this->neighbourKeys($climb['start_lat'], $climb['start_lng']) as $neighbourKey) {
                if (!isset($buckets[$neighbourKey])) {
                    continue;
                }

                foreach ($buckets[$neighbourKey] as $groupIndex) {
                    $ref = $groups[$groupIndex]['climbs'][0];

                    if (
                        GeoUtil::quickDistance($ref['start_lat'], $ref['start_lng'], $climb['start_lat'], $climb['start_lng']) < self::SAME_CLIMB_RADIUS_M &&
                        GeoUtil::quickDistance($ref['end_lat'], $ref['end_lng'], $climb['end_lat'], $climb['end_lng']) < self::SAME_CLIMB_RADIUS_M
                    ) {
                        $found = $groupIndex;
                        break 2;
                    }
                }
            }

            if (null === $found) {
                $groups[] = ['climbs' => []];
                $found = count($groups) - 1;
                $buckets[$key][] = $found;
            }

            $groups[$found]['climbs'][] = $climb;
        }

        foreach ($groups as &$group) {
            $group = $this->summarizeGroup($group['climbs']);
        }

        usort($groups, function ($a, $b) {
            return $b['score'] - $a['score'];
        });

        return $groups;
    }

    /**
     * @return string[]
     */
    private function neighbourKeys($lat, $lng)
    {
        $keys = [];

        foreach ([-0.01, 0, 0.01] as $dLat) {
            foreach ([-0.01, 0, 0.01] as $dLng) {
                $keys[] = round($lat + $dLat, 2).'_'.round($lng + $dLng, 2);
            }
        }

        return $keys;
    }

    /**
     * @param array[] $climbs newest first
     * @return array
     */
    private function summarizeGroup(array $climbs)
    {
        $reference = $climbs[0];
        $best = null;
        $names = [];
        $sports = [];

        foreach ($climbs as $climb) {
            if (null !== $climb['duration'] && (null === $best || $climb['duration'] < $best['duration'])) {
                $best = $climb;
            }

            $name = trim((string)$climb['routename']) !== '' ? trim($climb['routename']) : trim((string)$climb['title']);

            if ('' !== $name) {
                $names[$name] = isset($names[$name]) ? $names[$name] + 1 : 1;
            }

            $sports[$climb['sportid']] = ['name' => $climb['sportname'], 'icon' => $climb['sporticon']];
        }

        arsort($names);

        return [
            'id' => (int)$reference['id'],
            'name' => empty($names) ? '' : key($names),
            'category' => $reference['category'],
            'distance' => (float)$reference['distance'],
            'gain' => (int)$reference['gain'],
            'avg_grade' => (float)$reference['avg_grade'],
            'max_grade' => (float)$reference['max_grade'],
            'score' => (int)$reference['score'],
            'best_duration' => null === $best ? null : (int)$best['duration'],
            'best_vam' => null === $best ? null : (int)$best['vam'],
            'count' => count($climbs),
            'last' => (int)$climbs[0]['time'],
            'sports' => $sports,
            'climbs' => $climbs,
        ];
    }
}
