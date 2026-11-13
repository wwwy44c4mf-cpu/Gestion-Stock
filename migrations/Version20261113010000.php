<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261113010000 extends AbstractMigration
{
    public function getDescription(): string {return 'Maquette CA : commandes, réintégrations sans origine, outillage individualisé et tourets';}
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stores ADD ca_enabled boolean NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE categories ADD tracks_reels boolean NOT NULL DEFAULT false');
        $this->addSql('CREATE TABLE stock_orders(article_id uuid NOT NULL REFERENCES articles(id),store_id uuid NOT NULL REFERENCES stores(id),ordered_by uuid NOT NULL REFERENCES users(id),ordered_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(article_id,store_id))');
        $this->addSql('CREATE TABLE unlinked_returns(movement_id uuid PRIMARY KEY REFERENCES stock_movements(id),actor_id uuid NOT NULL REFERENCES users(id),request_key varchar(160) NOT NULL,fingerprint char(64) NOT NULL,UNIQUE(actor_id,request_key))');
        $this->addSql("CREATE TABLE tool_items(id uuid PRIMARY KEY DEFAULT gen_random_uuid(),store_id uuid NOT NULL REFERENCES stores(id),name varchar(160) NOT NULL CHECK(btrim(name)<>''),reference varchar(80) NOT NULL CHECK(btrim(reference)<>''),active boolean NOT NULL DEFAULT true,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),UNIQUE(store_id,reference))");
        $this->addSql('CREATE TABLE tool_loans(id uuid PRIMARY KEY DEFAULT gen_random_uuid(),tool_id uuid NOT NULL REFERENCES tool_items(id),store_id uuid NOT NULL REFERENCES stores(id),borrower_id uuid NOT NULL REFERENCES users(id),borrowed_at timestamptz NOT NULL DEFAULT clock_timestamp(),returned_at timestamptz,returned_by uuid REFERENCES users(id),CHECK((returned_at IS NULL)=(returned_by IS NULL)))');
        $this->addSql('CREATE UNIQUE INDEX tool_one_active_loan ON tool_loans(tool_id) WHERE returned_at IS NULL');
        $this->addSql('CREATE TABLE ca_requests(actor_id uuid NOT NULL REFERENCES users(id),request_key varchar(160) NOT NULL,fingerprint char(64) NOT NULL,kind varchar(40) NOT NULL,result jsonb NOT NULL,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(actor_id,request_key))');
        $this->addSql("CREATE TABLE cable_reels(id uuid PRIMARY KEY DEFAULT gen_random_uuid(),article_id uuid NOT NULL REFERENCES articles(id),store_id uuid NOT NULL REFERENCES stores(id),reference varchar(80) NOT NULL CHECK(btrim(reference)<>''),initial_length bigint NOT NULL CHECK(initial_length BETWEEN 1 AND 9007199254740991),remaining_length bigint NOT NULL CHECK(remaining_length>=0 AND remaining_length<=initial_length),version bigint NOT NULL DEFAULT 1,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),UNIQUE(reference))");
        $this->addSql("CREATE TABLE cable_reel_movements(id uuid PRIMARY KEY DEFAULT gen_random_uuid(),reel_id uuid NOT NULL REFERENCES cable_reels(id),actor_id uuid NOT NULL REFERENCES users(id),type varchar(16) NOT NULL CHECK(type IN ('receipt','use')),length bigint NOT NULL CHECK(length BETWEEN 1 AND 9007199254740991),created_at timestamptz NOT NULL DEFAULT clock_timestamp())");
        $this->privacyAccountFunction(true);
        $this->addSql('CREATE TRIGGER cable_movements_immutable BEFORE UPDATE OR DELETE ON cable_reel_movements FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
    }
    private function privacyAccountFunction(bool $tools): void
    {
        $sql=explode('-- migrate:statement',file_get_contents(__DIR__.'/sql/017_privacy_finalize.sql'))[0];
        $sql=str_replace('CREATE FUNCTION','CREATE OR REPLACE FUNCTION',$sql);
        if($tools) {$sql=str_replace('p.proposed_by=u.id))','p.proposed_by=u.id) AND NOT EXISTS(SELECT 1 FROM tool_loans l WHERE l.borrower_id=u.id AND l.returned_at IS NULL))',$sql);}
        $this->addSql("DO \$guard\$ BEGIN IF to_regprocedure('privacy_account_due(uuid)') IS NOT NULL THEN EXECUTE \$definition\$".$sql."\$definition\$; END IF; END \$guard\$");
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM tool_items) OR EXISTS(SELECT 1 FROM cable_reels) OR EXISTS(SELECT 1 FROM unlinked_returns) OR EXISTS(SELECT 1 FROM stock_orders) THEN RAISE EXCEPTION 'Retour arrière refusé : données de la maquette présentes'; END IF; END $$");
        $this->privacyAccountFunction(false);
        foreach(['cable_reel_movements','cable_reels','ca_requests','tool_loans','tool_items','unlinked_returns','stock_orders'] as $table) {$this->addSql('DROP TABLE '.$table);}
        $this->addSql('ALTER TABLE categories DROP COLUMN tracks_reels');$this->addSql('ALTER TABLE stores DROP COLUMN ca_enabled');
    }
}
