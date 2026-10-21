<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Insights;

use Doctrine\DBAL\Connection;

/**
 * Finds activities with a suspicious cadence (halved or doubled) and corrects them.
 *
 * Cadence is stored as the value reported by the device (for running per leg, so a typical value is about 80
 * for 160 steps per minute). A wrong factor affects the activity value, the trackdata series and the
 * stride length that was derived from the cadence.
 */
class CadenceCorrection
{
    /** @var int */
    const MAX_CANDIDATES = 400;

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
     * @param int $sportId
     * @param int $low activities with a cadence from 1 to this value are candidates for multiplying by 2
     * @param int $high activities with at least this cadence are candidates for dividing by 2
     * @return array[] 'id', 'time', 'title', 'distance', 'cadence', 'stride_length', 'direction' (2|0.5)
     */
    public function candidates($accountId, $sportId, $low, $high)
    {
        $rows = $this->Connection->fetchAll(
            'SELECT t.`id`, t.`time`, t.`title`, t.`distance`, t.`cadence`, t.`stride_length`
             FROM `'.$this->Prefix.'training` t
             WHERE t.`accountid` = ? AND t.`sportid` = ? AND t.`cadence` > 0
               AND (t.`cadence` <= ? OR t.`cadence` >= ?)
             ORDER BY t.`time` DESC LIMIT '.self::MAX_CANDIDATES,
            [$accountId, $sportId, $low, $high]
        );

        foreach ($rows as &$row) {
            $row['direction'] = (int)$row['cadence'] <= $low ? 2 : 0.5;
        }

        return $rows;
    }

    /**
     * @param int $accountId
     * @param int[] $ids
     * @param float $factor 2 or 0.5
     * @return int number of changed activities
     */
    public function apply($accountId, array $ids, $factor)
    {
        if (!in_array((float)$factor, [2.0, 0.5], true)) {
            throw new \InvalidArgumentException('Factor has to be 2 or 0.5');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return 0;
        }

        return $this->Connection->transactional(function (Connection $connection) use ($accountId, $ids, $factor) {
            $changed = 0;

            foreach ($ids as $id) {
                $owned = $connection->fetchColumn(
                    'SELECT COUNT(*) FROM `'.$this->Prefix.'training` WHERE `id` = ? AND `accountid` = ? AND `cadence` > 0',
                    [$id, $accountId]
                );

                if (!$owned) {
                    continue;
                }

                // stride length per step is the inverse of the step frequency; capped by its column (tinyint)
                $connection->executeUpdate(
                    'UPDATE `'.$this->Prefix.'training`
                     SET `cadence` = ROUND(`cadence` * ?),
                         `stride_length` = IF(`stride_length` > 0, LEAST(255, ROUND(`stride_length` / ?)), `stride_length`),
                         `edited` = UNIX_TIMESTAMP()
                     WHERE `id` = ? AND `accountid` = ?',
                    [$factor, $factor, $id, $accountId]
                );

                $series = $connection->fetchColumn(
                    'SELECT `cadence` FROM `'.$this->Prefix.'trackdata` WHERE `activityid` = ? AND `accountid` = ?',
                    [$id, $accountId]
                );

                if (is_string($series) && '' !== $series) {
                    $connection->executeUpdate(
                        'UPDATE `'.$this->Prefix.'trackdata` SET `cadence` = ? WHERE `activityid` = ? AND `accountid` = ?',
                        [self::scaleSeries($series, $factor), $id, $accountId]
                    );
                }

                ++$changed;
            }

            return $changed;
        });
    }

    /**
     * @param string $series pipe separated numbers, empty entries are kept
     * @param float $factor
     * @return string
     */
    public static function scaleSeries($series, $factor)
    {
        $values = explode('|', $series);

        foreach ($values as $i => $value) {
            if ('' !== $value && is_numeric($value)) {
                $values[$i] = (string)(int)round($value * $factor);
            }
        }

        return implode('|', $values);
    }
}
