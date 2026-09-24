<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\RouteAnalysis;

use Doctrine\DBAL\Connection;

/**
 * Tables for climbs and segments (see migration Version20260924100000).
 *
 * The tools create missing tables themselves, so they also work if the
 * migration has not been run.
 */
class RouteAnalysisSchema
{
    /** @var bool[] */
    private static $Checked = [];

    /**
     * @param Connection $connection
     * @param string $prefix
     */
    public static function ensureTables(Connection $connection, $prefix)
    {
        if (isset(self::$Checked[$prefix])) {
            return;
        }

        $existing = $connection->fetchAll('SHOW TABLES LIKE '.$connection->quote(str_replace('_', '\\_', $prefix).'%'));
        $tables = array_map('current', $existing);

        foreach (['climb', 'climb_scan', 'segment', 'segment_effort'] as $table) {
            if (!in_array($prefix.$table, $tables)) {
                foreach (self::createStatements($prefix) as $sql) {
                    $connection->exec($sql);
                }

                break;
            }
        }

        self::$Checked[$prefix] = true;
    }

    /**
     * @param string $prefix
     * @return string[]
     */
    public static function createStatements($prefix)
    {
        return [
            'CREATE TABLE IF NOT EXISTS `'.$prefix.'climb` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accountid` INT UNSIGNED NOT NULL,
                `activityid` INT UNSIGNED NOT NULL,
                `sportid` INT UNSIGNED NOT NULL,
                `time` INT UNSIGNED NOT NULL,
                `start_index` INT UNSIGNED NOT NULL,
                `end_index` INT UNSIGNED NOT NULL,
                `start_lat` DOUBLE NOT NULL,
                `start_lng` DOUBLE NOT NULL,
                `end_lat` DOUBLE NOT NULL,
                `end_lng` DOUBLE NOT NULL,
                `distance` DECIMAL(7,3) UNSIGNED NOT NULL,
                `gain` SMALLINT UNSIGNED NOT NULL,
                `avg_grade` DECIMAL(4,1) NOT NULL,
                `max_grade` DECIMAL(4,1) NOT NULL,
                `score` INT UNSIGNED NOT NULL,
                `category` VARCHAR(2) NOT NULL,
                `duration` INT UNSIGNED DEFAULT NULL,
                `vam` SMALLINT UNSIGNED DEFAULT NULL,
                `avg_hr` SMALLINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `accountid` (`accountid`, `sportid`),
                KEY `activityid` (`activityid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'CREATE TABLE IF NOT EXISTS `'.$prefix.'climb_scan` (
                `activityid` INT UNSIGNED NOT NULL,
                `accountid` INT UNSIGNED NOT NULL,
                `version` TINYINT UNSIGNED NOT NULL,
                PRIMARY KEY (`activityid`),
                KEY `accountid` (`accountid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'CREATE TABLE IF NOT EXISTS `'.$prefix.'segment` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accountid` INT UNSIGNED NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `sportid` INT UNSIGNED DEFAULT NULL,
                `distance` DECIMAL(7,3) UNSIGNED NOT NULL,
                `gain` SMALLINT NOT NULL,
                `avg_grade` DECIMAL(4,1) NOT NULL,
                `start_lat` DOUBLE NOT NULL,
                `start_lng` DOUBLE NOT NULL,
                `end_lat` DOUBLE NOT NULL,
                `end_lng` DOUBLE NOT NULL,
                `min_lat` DOUBLE NOT NULL,
                `min_lng` DOUBLE NOT NULL,
                `max_lat` DOUBLE NOT NULL,
                `max_lng` DOUBLE NOT NULL,
                `polyline` MEDIUMTEXT NOT NULL,
                `profile` MEDIUMTEXT NOT NULL,
                `created` INT UNSIGNED NOT NULL,
                `scanned_until` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `accountid` (`accountid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
            'CREATE TABLE IF NOT EXISTS `'.$prefix.'segment_effort` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `segmentid` INT UNSIGNED NOT NULL,
                `accountid` INT UNSIGNED NOT NULL,
                `activityid` INT UNSIGNED NOT NULL,
                `time` INT UNSIGNED NOT NULL,
                `duration` INT UNSIGNED DEFAULT NULL,
                `start_index` INT UNSIGNED NOT NULL,
                `end_index` INT UNSIGNED NOT NULL,
                `avg_hr` SMALLINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `effort` (`segmentid`, `activityid`, `start_index`),
                KEY `activityid` (`activityid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
        ];
    }

}
