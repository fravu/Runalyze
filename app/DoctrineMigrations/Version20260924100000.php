<?php

namespace Runalyze\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tables for climbs (climb score), segments and segment efforts
 */
class Version20260924100000 extends AbstractMigration implements ContainerAwareInterface
{
    /** @var ContainerInterface|null */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * CREATE TABLE commits implicitly in MySQL
     */
    public function isTransactional() : bool
    {
        return false;
    }

    /**
     * @param Schema $schema
     */
    public function up(Schema $schema) : void
    {
        $prefix = $this->container->getParameter('database_prefix');

        foreach (self::createStatements($prefix) as $sql) {
            $this->addSql($sql);
        }
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

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema) : void
    {
        $prefix = $this->container->getParameter('database_prefix');

        $this->addSql('DROP TABLE IF EXISTS `'.$prefix.'segment_effort`');
        $this->addSql('DROP TABLE IF EXISTS `'.$prefix.'segment`');
        $this->addSql('DROP TABLE IF EXISTS `'.$prefix.'climb_scan`');
        $this->addSql('DROP TABLE IF EXISTS `'.$prefix.'climb`');
    }
}
