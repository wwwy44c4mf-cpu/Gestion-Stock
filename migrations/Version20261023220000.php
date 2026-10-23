<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Retours répartis dans le magasin de dépôt et magasin favori personnel'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD favorite_store_id uuid REFERENCES stores(id) ON DELETE RESTRICT');
        // The existing offline retention script also clears this new preference.
        $this->addSql("CREATE FUNCTION clear_anonymous_favorite() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.anonymized_at IS NOT NULL THEN NEW.favorite_store_id=NULL; END IF; RETURN NEW; END $$");
        $this->addSql('CREATE TRIGGER users_clear_favorite BEFORE INSERT OR UPDATE ON users FOR EACH ROW EXECUTE FUNCTION clear_anonymous_favorite()');
        // Groups hold quantities only: names and beneficiary links stay in the existing
        // immutable movements, whose retention and correction rules already apply.
        $this->addSql('CREATE TABLE stock_return_groups (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), article_id uuid NOT NULL REFERENCES articles(id), store_id uuid NOT NULL REFERENCES stores(id), quantity bigint NOT NULL CHECK(quantity BETWEEN 1 AND 9007199254740991), created_at timestamptz NOT NULL DEFAULT clock_timestamp())');
        $this->addSql('ALTER TABLE stock_movements ADD return_group_id uuid REFERENCES stock_return_groups(id) ON DELETE RESTRICT, ADD CHECK(return_group_id IS NULL OR type=\'return\')');
        $this->addSql('CREATE INDEX movements_return_group_idx ON stock_movements(return_group_id) WHERE return_group_id IS NOT NULL');
        $this->addSql('CREATE TRIGGER return_groups_immutable BEFORE UPDATE OR DELETE ON stock_return_groups FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION validate_return_group() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE batch stock_return_groups%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='stock_return_groups' THEN SELECT * INTO batch FROM stock_return_groups WHERE id=NEW.id;
 ELSE SELECT * INTO batch FROM stock_return_groups WHERE id=NEW.return_group_id; END IF;
 IF batch.id IS NOT NULL AND (
  NOT EXISTS(SELECT 1 FROM stock_movements WHERE return_group_id=batch.id)
  OR (SELECT coalesce(sum(quantity),0) FROM stock_movements WHERE return_group_id=batch.id)<>batch.quantity
  OR EXISTS(SELECT 1 FROM stock_movements WHERE return_group_id=batch.id AND (article_id<>batch.article_id OR destination_store_id<>batch.store_id OR type<>'return'))
  OR (SELECT count(DISTINCT c.consumer_id)>1 OR count(DISTINCT r.author_id)>1 FROM stock_movements r JOIN stock_movements c ON c.id=r.related_movement_id WHERE r.return_group_id=batch.id)
 ) THEN RAISE EXCEPTION 'invalid_return_group' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE CONSTRAINT TRIGGER return_group_complete AFTER INSERT ON stock_return_groups DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_return_group()');
        $this->addSql('CREATE CONSTRAINT TRIGGER return_movement_group AFTER INSERT ON stock_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.return_group_id IS NOT NULL) EXECUTE FUNCTION validate_return_group()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_related_movement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original stock_movements%ROWTYPE;
BEGIN
 IF NEW.related_movement_id IS NOT NULL THEN
  SELECT * INTO original FROM stock_movements WHERE id=NEW.related_movement_id;
  IF NOT FOUND OR original.article_id<>NEW.article_id THEN RAISE EXCEPTION 'Opération d’origine absente ou article différent' USING ERRCODE='23514'; END IF;
  IF NEW.type='return' AND original.type<>'consumption' THEN RAISE EXCEPTION 'Un retour doit viser une consommation' USING ERRCODE='23514'; END IF;
  IF NEW.type='reversal' AND (NEW.source_store_id IS DISTINCT FROM original.destination_store_id OR NEW.destination_store_id IS DISTINCT FROM original.source_store_id OR NEW.quantity<>original.quantity) THEN RAISE EXCEPTION 'Un contre-mouvement doit compenser exactement l’origine' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM stock_return_groups) OR EXISTS(SELECT 1 FROM users WHERE favorite_store_id IS NOT NULL) OR EXISTS(SELECT 1 FROM stock_movements r JOIN stock_movements c ON c.id=r.related_movement_id WHERE r.type='return' AND r.destination_store_id<>c.source_store_id) THEN RAISE EXCEPTION 'Retour arrière refusé : retours inter-magasins ou préférences présents'; END IF; END $$");
        $this->addSql('DROP TRIGGER return_movement_group ON stock_movements');
        $this->addSql('DROP TRIGGER return_group_complete ON stock_return_groups');
        $this->addSql('DROP FUNCTION validate_return_group()');
        $this->addSql('ALTER TABLE stock_movements DROP COLUMN return_group_id');
        $this->addSql('DROP TABLE stock_return_groups');
        $this->addSql('DROP TRIGGER users_clear_favorite ON users');
        $this->addSql('DROP FUNCTION clear_anonymous_favorite()');
        $this->addSql('ALTER TABLE users DROP COLUMN favorite_store_id');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_related_movement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original stock_movements%ROWTYPE;
BEGIN
 IF NEW.related_movement_id IS NOT NULL THEN
  SELECT * INTO original FROM stock_movements WHERE id=NEW.related_movement_id;
  IF NOT FOUND OR original.article_id<>NEW.article_id THEN RAISE EXCEPTION 'Opération d’origine absente ou article différent' USING ERRCODE='23514'; END IF;
  IF NEW.type='return' AND (original.type<>'consumption' OR NEW.destination_store_id IS DISTINCT FROM original.source_store_id) THEN RAISE EXCEPTION 'Un retour doit viser la consommation et son magasin d’origine' USING ERRCODE='23514'; END IF;
  IF NEW.type='reversal' AND (NEW.source_store_id IS DISTINCT FROM original.destination_store_id OR NEW.destination_store_id IS DISTINCT FROM original.source_store_id OR NEW.quantity<>original.quantity) THEN RAISE EXCEPTION 'Un contre-mouvement doit compenser exactement l’origine' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
    }
}
