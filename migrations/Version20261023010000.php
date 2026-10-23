<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Codes de lecture multiples par article et index global unique'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE articles ADD scan_codes jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(scan_codes)='array' AND jsonb_array_length(scan_codes)<=50)");
        // The JSON is the versioned catalogue value; this derived table enforces
        // uniqueness across every article and provides exact, case-sensitive lookup.
        $this->addSql('CREATE TABLE article_scan_codes (code text COLLATE "C" PRIMARY KEY, article_id uuid NOT NULL REFERENCES articles(id) ON DELETE RESTRICT)');
        $this->addSql('CREATE INDEX article_scan_codes_article_idx ON article_scan_codes(article_id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION sync_article_scan_codes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.scan_codes) item WHERE jsonb_typeof(item)<>'string')
 OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(NEW.scan_codes) value WHERE length(value) NOT BETWEEN 1 AND 512 OR value<>btrim(value) OR value ~ '[[:cntrl:]]')
 OR (SELECT count(*)<>count(DISTINCT value COLLATE "C") FROM jsonb_array_elements_text(NEW.scan_codes) value) THEN
  RAISE EXCEPTION 'invalid_article_scan_codes' USING ERRCODE='23514';
 END IF;
 IF TG_OP='UPDATE' AND NEW.scan_codes IS DISTINCT FROM OLD.scan_codes AND NEW.version<>OLD.version+1 THEN
  RAISE EXCEPTION 'scan_codes_require_article_version' USING ERRCODE='23514';
 END IF;
 DELETE FROM article_scan_codes WHERE article_id=NEW.id;
 INSERT INTO article_scan_codes(code,article_id) SELECT value,NEW.id FROM jsonb_array_elements_text(NEW.scan_codes) value;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER articles_scan_codes AFTER INSERT OR UPDATE OF scan_codes ON articles FOR EACH ROW EXECUTE FUNCTION sync_article_scan_codes()');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM articles WHERE scan_codes<>'[]'::jsonb) OR EXISTS(SELECT 1 FROM article_proposals WHERE payload ? 'scan_codes') OR EXISTS(SELECT 1 FROM catalog_creation_requests WHERE response->'data' ? 'scan_codes') THEN RAISE EXCEPTION 'Retour arrière refusé : codes de lecture présents'; END IF; END $$");
        $this->addSql('DROP TRIGGER articles_scan_codes ON articles');
        $this->addSql('DROP FUNCTION sync_article_scan_codes()');
        $this->addSql('DROP TABLE article_scan_codes');
        $this->addSql('ALTER TABLE articles DROP scan_codes');
    }
}
