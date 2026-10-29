<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;

/** Bring narrowly scoped legacy fixtures up to the catalogue removal schema. */
final class CatalogDeletionSchema
{
    public static function apply(Connection $db): void
    {
        require_once dirname(__DIR__).'/migrations/Version20261029010000.php';
        $migration = new \DoctrineMigrations\Version20261029010000($db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
    }
}
