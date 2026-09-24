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

        foreach (\Runalyze\Bundle\CoreBundle\Component\Tool\RouteAnalysis\RouteAnalysisSchema::createStatements($prefix) as $sql) {
            $this->addSql($sql);
        }
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
