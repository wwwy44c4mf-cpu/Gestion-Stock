<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030020000 extends AbstractMigration
{
    public function getDescription(): string {return 'ADMIN-04 : lectures administratives et suppressions protégées des comptes et magasins';}
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD deleted_at timestamptz,ADD CONSTRAINT users_deleted_identity CHECK(deleted_at IS NULL OR (NOT active AND anonymized_at IS NOT NULL))');
        $this->addSql('ALTER TABLE stores ADD deleted_at timestamptz,ADD CONSTRAINT stores_deleted_inactive CHECK(deleted_at IS NULL OR NOT active)');
        $this->addSql("CREATE TABLE administration_reads(user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,kind varchar(24) NOT NULL CHECK(kind IN ('account_request','article_proposal')),target_id uuid NOT NULL,read_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(user_id,kind,target_id))");
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM users WHERE deleted_at IS NOT NULL) OR EXISTS(SELECT 1 FROM stores WHERE deleted_at IS NOT NULL) THEN RAISE EXCEPTION 'Retour arrière refusé : suppressions présentes'; END IF; END $$");
        $this->addSql('DROP TABLE administration_reads');$this->addSql('ALTER TABLE stores DROP CONSTRAINT stores_deleted_inactive,DROP COLUMN deleted_at');$this->addSql('ALTER TABLE users DROP CONSTRAINT users_deleted_identity,DROP COLUMN deleted_at');
    }
}
