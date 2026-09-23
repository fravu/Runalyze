<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Geo;

use Runalyze\Calculation\Route\GeohashLine;

/**
 * Small, fast helpers for route analysis (heatmap, climbs, segments).
 *
 * League\Geotools is too slow for decoding thousands of routes point by point,
 * so geohash decoding is done here directly.
 */
class GeoUtil
{
    const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    /** @var float */
    const EARTH_RADIUS_M = 6371000.0;

    /** @var array|null */
    private static $Base32Map = null;

    /**
     * @param string $geohash
     * @return float[] [lat, lng] (center of the cell)
     */
    public static function decode($geohash)
    {
        if (null === self::$Base32Map) {
            self::$Base32Map = array_flip(str_split(self::BASE32));
        }

        $latMin = -90.0; $latMax = 90.0;
        $lngMin = -180.0; $lngMax = 180.0;
        $isLng = true;
        $len = strlen($geohash);

        for ($i = 0; $i < $len; ++$i) {
            $value = isset(self::$Base32Map[$geohash[$i]]) ? self::$Base32Map[$geohash[$i]] : 0;

            for ($bit = 4; $bit >= 0; --$bit) {
                $isSet = ($value >> $bit) & 1;

                if ($isLng) {
                    $mid = ($lngMin + $lngMax) / 2;
                    if ($isSet) { $lngMin = $mid; } else { $lngMax = $mid; }
                } else {
                    $mid = ($latMin + $latMax) / 2;
                    if ($isSet) { $latMin = $mid; } else { $latMax = $mid; }
                }

                $isLng = !$isLng;
            }
        }

        return [($latMin + $latMax) / 2, ($lngMin + $lngMax) / 2];
    }

    /**
     * @param string|null $dbValue shortened geohashes as stored in `route`.`geohashes`
     * @return string[] full geohashes
     */
    public static function geohashesFromDatabase($dbValue)
    {
        if (null === $dbValue || '' === trim($dbValue)) {
            return [];
        }

        return GeohashLine::extend(explode('|', $dbValue));
    }

    /**
     * @param string $geohash
     * @return bool true for placeholders of missing gps points (0/0 or the '7zzz...' filler)
     */
    public static function isEmptyGeohash($geohash)
    {
        return '' === $geohash || 's0000000' === substr($geohash, 0, 8) || '7zzzzzzz' === substr($geohash, 0, 8);
    }

    /**
     * @return float distance in meters
     */
    public static function distance($lat1, $lng1, $lat2, $lng2)
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);

        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Fast approximation for short distances (equirectangular), good enough below a few km
     *
     * @return float distance in meters
     */
    public static function quickDistance($lat1, $lng1, $lat2, $lng2)
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return sqrt($x * $x + $y * $y) * self::EARTH_RADIUS_M;
    }

    /**
     * @param string|null $pipeString e.g. `route`.`elevations_corrected` or `trackdata`.`distance`
     * @return float[]
     */
    public static function floatsFromDatabase($pipeString)
    {
        if (null === $pipeString || '' === $pipeString) {
            return [];
        }

        return array_map('floatval', explode('|', $pipeString));
    }

    /**
     * Load everything needed to analyse one activity's track
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param string $prefix
     * @param int $activityId
     * @param int $accountId
     * @return array|null ['lat' => [], 'lng' => [], 'dist' => [] (km), 'elev' => [], 'time' => [], 'hr' => [], 'sportid' => int, 'start' => int, 'title' => string]
     */
    public static function loadTrack($connection, $prefix, $activityId, $accountId)
    {
        $row = $connection->fetchAssoc(
            'SELECT t.`id`, t.`sportid`, t.`time`, t.`title`, r.`name` AS `routename`, r.`geohashes`,
                    COALESCE(NULLIF(r.`elevations_corrected`, ""), r.`elevations_original`) AS `elevations`,
                    d.`distance` AS `dist`, d.`time` AS `tracktime`, d.`heartrate`
             FROM `'.$prefix.'training` t
             JOIN `'.$prefix.'route` r ON r.`id` = t.`routeid`
             LEFT JOIN `'.$prefix.'trackdata` d ON d.`activityid` = t.`id`
             WHERE t.`id` = ? AND t.`accountid` = ?',
            [(int)$activityId, (int)$accountId]
        );

        if (false === $row || null === $row['geohashes'] || '' === $row['geohashes']) {
            return null;
        }

        $geohashes = self::geohashesFromDatabase($row['geohashes']);
        $num = count($geohashes);
        $lat = [];
        $lng = [];

        foreach ($geohashes as $hash) {
            if (self::isEmptyGeohash($hash)) {
                $lat[] = null;
                $lng[] = null;
            } else {
                list($la, $ln) = self::decode($hash);
                $lat[] = $la;
                $lng[] = $ln;
            }
        }

        $dist = self::floatsFromDatabase($row['dist']);

        if (count($dist) != $num) {
            $dist = self::cumulativeDistance($lat, $lng);
        }

        $elev = self::floatsFromDatabase($row['elevations']);
        $time = self::floatsFromDatabase($row['tracktime']);
        $hr = self::floatsFromDatabase($row['heartrate']);

        return [
            'lat' => $lat,
            'lng' => $lng,
            'dist' => $dist,
            'elev' => count($elev) == $num ? $elev : [],
            'time' => count($time) == $num ? $time : [],
            'hr' => count($hr) == $num ? $hr : [],
            'sportid' => (int)$row['sportid'],
            'start' => (int)$row['time'],
            'title' => trim((string)$row['routename']) !== '' ? trim($row['routename']) : trim((string)$row['title']),
        ];
    }

    /**
     * @param array $lat
     * @param array $lng
     * @return float[] cumulative distance in km
     */
    public static function cumulativeDistance(array $lat, array $lng)
    {
        $dist = [];
        $sum = 0.0;
        $lastLat = null;
        $lastLng = null;

        foreach ($lat as $i => $la) {
            if (null !== $la && null !== $lastLat) {
                $sum += self::quickDistance($lastLat, $lastLng, $la, $lng[$i]) / 1000;
            }

            if (null !== $la) {
                $lastLat = $la;
                $lastLng = $lng[$i];
            }

            $dist[] = $sum;
        }

        return $dist;
    }

    /**
     * Reduce a series to at most $max points (keeps first and last)
     *
     * @param int $from
     * @param int $to
     * @param int $max
     * @return int[] indices
     */
    public static function sampleIndices($from, $to, $max)
    {
        $count = $to - $from + 1;

        if ($count <= $max) {
            return range($from, $to);
        }

        $step = ($count - 1) / ($max - 1);
        $indices = [];

        for ($k = 0; $k < $max; ++$k) {
            $indices[] = $from + (int)round($k * $step);
        }

        return array_values(array_unique($indices));
    }
}
