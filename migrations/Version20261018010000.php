<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SOC-02 : schéma métier, journal immuable, quantités entières et réconciliation';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL requis.');
        $this->queueFile('001_schema.sql');
    }

    public function down(Schema $schema): void
    {
        $this->queueFile('001_rollback.sql');
    }

    private function queueFile(string $name): void
    {
        $sql = file_get_contents(__DIR__.'/sql/'.$name);
        if ($sql === false) {
            throw new \RuntimeException('Fichier de migration introuvable : '.$name);
        }
        // Séparateur explicite pour préserver les points-virgules des fonctions SQL.
        foreach (explode('-- migrate:statement', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->addSql(trim($statement));
            }
        }
    }
}
