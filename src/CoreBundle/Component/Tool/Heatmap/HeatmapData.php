<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Heatmap;

use Doctrine\DBAL\Connection;
use Runalyze\Calculation\Route\GeohashLine;

/**
 * Compact route data for the heatmap.
 *
 * Every route is reduced to geohashes of precision 8 (about 38 x 19 m) without
 * consecutive duplicates. The browser decodes them itself. The result is cached
 * as file until activities of the account change.
 */
class HeatmapData
{
    const PRECISION = 8;
    const CHUNK_SIZE = 100;

    /** @var Connection */
    protected $Connection;

    /** @var string */
    protected $Prefix;

    /** @var string */
    protected $CacheDir;

    /**
     * @param Connection $connection
     * @param string $prefix
     * @param string $cacheDir
     */
    public function __construct(Connection $connection, $prefix, $cacheDir)
    {
        $this->Connection = $connection;
        $this->Prefix = $prefix;
        $this->CacheDir = $cacheDir;
    }

    /**
     * @param int $accountId
     * @return string json
     */
    public function json($accountId)
    {
        $state = $this->Connection->fetchAssoc(
            'SELECT COUNT(*) AS `num`, MAX(t.`id`) AS `maxid`, SUM(t.`routeid`) AS `routes`, SUM(t.`sportid`) AS `sports`
             FROM `'.$this->Prefix.'training` t WHERE t.`accountid` = ? AND t.`routeid` IS NOT NULL',
            [(int)$accountId]
        );
        $file = $this->CacheDir.'/heatmap-'.(int)$accountId.'-'.md5(json_encode($state)).'.json';

        if (is_file($file)) {
            return file_get_contents($file);
        }

        $json = json_encode($this->build($accountId));

        if (!is_dir($this->CacheDir)) {
            @mkdir($this->CacheDir, 0775, true);
        }

        foreach (glob($this->CacheDir.'/heatmap-'.(int)$accountId.'-*.json') ?: [] as $old) {
            @unlink($old);
        }

        @file_put_contents($file, $json);

        return $json;
    }

    /**
     * @param int $accountId
     * @return array
     */
    public function build($accountId)
    {
        $sports = [];

        foreach ($this->Connection->fetchAll(
            'SELECT s.`id`, s.`name`, s.`img`, COUNT(t.`id`) AS `num`
             FROM `'.$this->Prefix.'sport` s
             JOIN `'.$this->Prefix.'training` t ON t.`sportid` = s.`id`
             JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
             WHERE s.`accountid` = ? AND r.`geohashes` IS NOT NULL AND r.`geohashes` != ""
             GROUP BY s.`id` ORDER BY `num` DESC',
            [(int)$accountId]
        ) as $row) {
            $sports[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'icon' => $row['img'], 'count' => (int)$row['num']];
        }

        $ids = array_map(function ($row) {
            return (int)$row['id'];
        }, $this->Connection->fetchAll(
            'SELECT t.`id` FROM `'.$this->Prefix.'training` t JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
             WHERE t.`accountid` = ? AND r.`geohashes` IS NOT NULL AND r.`geohashes` != "" ORDER BY t.`time`',
            [(int)$accountId]
        ));

        $activities = [];

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $rows = $this->Connection->fetchAll(
                'SELECT t.`id`, t.`sportid`, t.`time`, t.`distance`, t.`title`, r.`geohashes`
                 FROM `'.$this->Prefix.'training` t JOIN `'.$this->Prefix.'route` r ON r.`id` = t.`routeid`
                 WHERE t.`id` IN ('.implode(',', $chunk).')'
            );

            foreach ($rows as $row) {
                $points = $this->compress($row['geohashes']);

                if ('' !== $points) {
                    $activities[] = [
                        'id' => (int)$row['id'],
                        's' => (int)$row['sportid'],
                        't' => (int)$row['time'],
                        'd' => (float)$row['distance'],
                        'n' => mb_substr((string)$row['title'], 0, 60),
                        'p' => $points,
                    ];
                }
            }
        }

        usort($activities, function ($a, $b) {
            return $a['t'] - $b['t'];
        });

        return ['precision' => self::PRECISION, 'sports' => $sports, 'activities' => $activities];
    }

    /**
     * @param string $dbValue
     * @return string concatenated geohashes of fixed length, gaps as '-'
     */
    private function compress($dbValue)
    {
        $result = '';
        $last = '';

        foreach (GeohashLine::extend(explode('|', $dbValue)) as $hash) {
            $short = substr($hash, 0, self::PRECISION);

            if ('7zzzzzzz' === $short || 's0000000' === $short) {
                if ('-' !== $last && '' !== $last) {
                    $result .= '-';
                    $last = '-';
                }

                continue;
            }

            if ($short !== $last) {
                $result .= $short;
                $last = $short;
            }
        }

        return rtrim($result, '-');
    }
}
