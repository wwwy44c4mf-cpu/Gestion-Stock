<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029010000 extends AbstractMigration
{
    public function getDescription(): string { return 'CAT-02 : retirer un article du catalogue sans effacer ses stocks ni son historique'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE articles ADD deleted_at timestamptz, ADD CONSTRAINT deleted_article_inactive CHECK (deleted_at IS NULL OR NOT active)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM articles WHERE deleted_at IS NOT NULL) THEN RAISE EXCEPTION 'Retour arrière refusé : articles supprimés du catalogue présents'; END IF; END $$");
        $this->addSql('ALTER TABLE articles DROP CONSTRAINT deleted_article_inactive, DROP COLUMN deleted_at');
    }
}
