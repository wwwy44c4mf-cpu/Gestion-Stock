<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string { return 'ART-01 : créations idempotentes du catalogue et blocage des consommations inactives'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE catalog_creation_requests (
    actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    resource_type varchar(16) NOT NULL CHECK (resource_type IN ('article','category')),
    request_key varchar(160) NOT NULL CHECK (request_key ~ '^[A-Za-z0-9._:-]{8,160}$'),
    request_fingerprint varchar(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    article_id uuid REFERENCES articles(id) ON DELETE RESTRICT,
    category_id uuid REFERENCES categories(id) ON DELETE RESTRICT,
    response jsonb NOT NULL CHECK (jsonb_typeof(response) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (actor_id,resource_type,request_key),
    CHECK ((resource_type = 'article' AND article_id IS NOT NULL AND category_id IS NULL) OR (resource_type = 'category' AND category_id IS NOT NULL AND article_id IS NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION require_consumable_article() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE available boolean;
BEGIN
    SELECT active INTO available FROM articles WHERE id = NEW.article_id FOR SHARE;
    IF NOT FOUND OR NOT available THEN
        RAISE EXCEPTION 'Article inactif : nouvelle consommation interdite' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql("CREATE TRIGGER movements_active_article BEFORE INSERT ON stock_movements FOR EACH ROW WHEN (NEW.type = 'consumption') EXECUTE FUNCTION require_consumable_article()");
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM catalog_creation_requests) OR EXISTS (SELECT 1 FROM articles WHERE NOT active) THEN RAISE EXCEPTION 'Retour arrière refusé : créations de catalogue ou articles inactifs présents'; END IF; END $$");
        $this->addSql('DROP TRIGGER movements_active_article ON stock_movements');
        $this->addSql('DROP FUNCTION require_consumable_article()');
        $this->addSql('DROP TABLE catalog_creation_requests');
    }
}
