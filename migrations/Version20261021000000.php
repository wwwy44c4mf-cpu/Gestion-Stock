<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021000000 extends AbstractMigration
{
    public function getDescription(): string {return 'INV-03 : unicité et cohérence des ajustements et des clôtures';}
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX movements_inventory_article_unique ON stock_movements (inventory_id,article_id) WHERE inventory_id IS NOT NULL');
        $this->addSql(<<<'SQL'
CREATE FUNCTION guard_inventory_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE campaign inventories%ROWTYPE; line inventory_lines%ROWTYPE;
BEGIN
    IF NEW.inventory_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO campaign FROM inventories WHERE id=NEW.inventory_id FOR SHARE;
    SELECT * INTO line FROM inventory_lines WHERE inventory_id=NEW.inventory_id AND article_id=NEW.article_id;
    IF campaign.status IS DISTINCT FROM 'pending_validation' OR line.counted_qty IS NULL OR line.delta=0
       OR NEW.quantity IS DISTINCT FROM abs(line.delta)
       OR (line.delta<0 AND (NEW.source_store_id IS DISTINCT FROM campaign.store_id OR NEW.destination_store_id IS NOT NULL))
       OR (line.delta>0 AND (NEW.destination_store_id IS DISTINCT FROM campaign.store_id OR NEW.source_store_id IS NOT NULL))
    THEN RAISE EXCEPTION 'Ajustement d’inventaire incohérent' USING ERRCODE='23514'; END IF;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER movements_inventory_guard BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION guard_inventory_adjustment()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION guard_inventory_closure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status='closed' AND OLD.status IS DISTINCT FROM 'closed' THEN
        IF EXISTS (SELECT 1 FROM inventory_lines l LEFT JOIN stock_levels sl ON sl.article_id=l.article_id AND sl.store_id=NEW.store_id
                   WHERE l.inventory_id=NEW.id AND (l.counted_qty IS NULL OR coalesce(sl.quantity,0)<>l.counted_qty
                       OR (l.delta<>0 AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.inventory_id=l.inventory_id AND m.article_id=l.article_id))))
        THEN RAISE EXCEPTION 'Clôture incomplète ou soldes incohérents' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER inventories_closure_guard BEFORE UPDATE ON inventories FOR EACH ROW EXECUTE FUNCTION guard_inventory_closure()');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM stock_movements WHERE inventory_id IS NOT NULL) OR EXISTS (SELECT 1 FROM inventories WHERE status='closed') THEN RAISE EXCEPTION 'Retour arrière refusé : clôtures d’inventaire présentes'; END IF; END $$");
        $this->addSql('DROP TRIGGER inventories_closure_guard ON inventories');$this->addSql('DROP FUNCTION guard_inventory_closure()');
        $this->addSql('DROP TRIGGER movements_inventory_guard ON stock_movements');$this->addSql('DROP FUNCTION guard_inventory_adjustment()');$this->addSql('DROP INDEX movements_inventory_article_unique');
    }
}
