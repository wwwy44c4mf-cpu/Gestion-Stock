<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261112010000 extends AbstractMigration
{
    public function getDescription(): string {return 'CAS-01 : casage facultatif unique par article et magasin, indépendant des quantités';}
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE article_locations(article_id uuid NOT NULL REFERENCES articles(id) ON DELETE RESTRICT,store_id uuid NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,location varchar(160),version bigint NOT NULL DEFAULT 1 CHECK(version BETWEEN 1 AND 9007199254740991),updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(article_id,store_id),CHECK(location IS NULL OR (btrim(location)<>'' AND location !~ '[[:cntrl:]]')))");
        $this->addSql('CREATE INDEX article_locations_store ON article_locations(store_id,article_id)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM article_locations) THEN RAISE EXCEPTION 'Retour arrière refusé : casages présents'; END IF; END $$");
        $this->addSql('DROP TABLE article_locations');
    }
}
