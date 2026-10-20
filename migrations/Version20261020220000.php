<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020220000 extends AbstractMigration
{
    public function getDescription(): string { return 'INV-01 : requêtes idempotentes et protection du cycle de vie des inventaires'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE inventory_requests (
    actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_key varchar(160) NOT NULL,
    request_fingerprint varchar(64) NOT NULL,
    inventory_id uuid NOT NULL REFERENCES inventories(id) ON DELETE RESTRICT,
    response jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (actor_id,request_key)
)
SQL);
        $this->addSql('CREATE TRIGGER inventory_requests_immutable BEFORE UPDATE OR DELETE ON inventory_requests FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION guard_inventory_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Un inventaire est conservé pour la traçabilité' USING ERRCODE = '23514'; END IF;
    IF OLD.status IN ('closed','cancelled') THEN RAISE EXCEPTION 'Inventaire terminé immuable' USING ERRCODE = '23514'; END IF;
    IF (NEW.id,NEW.store_id,NEW.created_by,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.store_id,OLD.created_by,OLD.created_at)
       OR (OLD.snapshot_at IS NOT NULL AND NEW.snapshot_at IS DISTINCT FROM OLD.snapshot_at)
       OR NOT ((OLD.status = 'draft' AND NEW.status IN ('in_progress','cancelled'))
            OR (OLD.status = 'in_progress' AND NEW.status IN ('pending_validation','cancelled'))
            OR (OLD.status = 'pending_validation' AND NEW.status IN ('closed','cancelled')))
    THEN RAISE EXCEPTION 'Transition d’inventaire invalide' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER inventories_transition_guard BEFORE UPDATE OR DELETE ON inventories FOR EACH ROW EXECUTE FUNCTION guard_inventory_transition()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION guard_inventory_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE state text;
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Une ligne d’inventaire est conservée' USING ERRCODE = '23514'; END IF;
    SELECT status INTO state FROM inventories WHERE id = NEW.inventory_id FOR SHARE;
    IF state IS DISTINCT FROM 'in_progress' THEN RAISE EXCEPTION 'Comptage autorisé uniquement pendant un inventaire en cours' USING ERRCODE = '23514'; END IF;
    IF TG_OP = 'UPDATE' AND (NEW.inventory_id,NEW.article_id,NEW.theoretical_qty) IS DISTINCT FROM (OLD.inventory_id,OLD.article_id,OLD.theoretical_qty)
    THEN RAISE EXCEPTION 'Référence d’inventaire immuable' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER inventory_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_lines FOR EACH ROW EXECUTE FUNCTION guard_inventory_line()');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM inventory_requests) OR EXISTS (SELECT 1 FROM inventories) THEN RAISE EXCEPTION 'Retour arrière refusé : inventaires présents'; END IF; END $$");
        $this->addSql('DROP TRIGGER inventory_lines_guard ON inventory_lines');$this->addSql('DROP FUNCTION guard_inventory_line()');
        $this->addSql('DROP TRIGGER inventories_transition_guard ON inventories');$this->addSql('DROP FUNCTION guard_inventory_transition()');
        $this->addSql('DROP TABLE inventory_requests');
    }
}
