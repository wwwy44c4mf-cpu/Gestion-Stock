<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Marquer les comptes réservés au développement pour les refuser hors dev'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        // Never remove the protection while a development account still exists.
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM users WHERE development_only) THEN RAISE EXCEPTION 'Retour arrière refusé : compte de développement présent'; END IF; END $$");
        $this->addSql('ALTER TABLE users DROP COLUMN development_only');
    }
}
