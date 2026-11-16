<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261116010000 extends AbstractMigration
{
    public function getDescription(): string {return 'Retrait local des références à zéro sans effacement du journal';}
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_levels ADD withdrawn boolean NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE stock_levels ADD CONSTRAINT withdrawn_stock_empty CHECK (NOT withdrawn OR quantity=0)');
        $this->addSql('CREATE FUNCTION restore_received_stock() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.quantity>0 THEN NEW.withdrawn:=false; END IF; RETURN NEW; END $$');
        $this->addSql('CREATE TRIGGER restore_received_stock BEFORE INSERT OR UPDATE OF quantity ON stock_levels FOR EACH ROW EXECUTE FUNCTION restore_received_stock()');
        $this->addSql(<<<'SQL'
DO $migration$ DECLARE definition text; BEGIN
 definition:=pg_get_functiondef('reconcile_stock_alert()'::regprocedure);
 IF strpos(definition,'IF NEW.critical_threshold IS NOT NULL')=0 THEN RAISE EXCEPTION 'Unexpected alert reconciliation definition'; END IF;
 definition:=replace(definition,'IF NEW.critical_threshold IS NOT NULL','IF NOT NEW.withdrawn AND NEW.critical_threshold IS NOT NULL');
 definition:=replace(definition,'ELSIF NEW.low_threshold IS NOT NULL','ELSIF NOT NEW.withdrawn AND NEW.low_threshold IS NOT NULL');
 EXECUTE definition;
END $migration$
SQL);
        $this->addSql('DROP TRIGGER stock_alert_reconciliation ON stock_levels');
        $this->addSql('CREATE TRIGGER stock_alert_reconciliation AFTER INSERT OR UPDATE OF quantity,low_threshold,critical_threshold,withdrawn ON stock_levels FOR EACH ROW EXECUTE FUNCTION reconcile_stock_alert()');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM stock_levels WHERE withdrawn) THEN RAISE EXCEPTION 'Retour arrière refusé : références retirées présentes'; END IF; END $$");
        $this->addSql("DO $$ BEGIN EXECUTE replace(replace(pg_get_functiondef('reconcile_stock_alert()'::regprocedure),'IF NOT NEW.withdrawn AND NEW.critical_threshold IS NOT NULL','IF NEW.critical_threshold IS NOT NULL'),'ELSIF NOT NEW.withdrawn AND NEW.low_threshold IS NOT NULL','ELSIF NEW.low_threshold IS NOT NULL'); END $$");
        $this->addSql('DROP TRIGGER stock_alert_reconciliation ON stock_levels');
        $this->addSql('CREATE TRIGGER stock_alert_reconciliation AFTER INSERT OR UPDATE OF quantity,low_threshold,critical_threshold ON stock_levels FOR EACH ROW EXECUTE FUNCTION reconcile_stock_alert()');
        $this->addSql('DROP TRIGGER restore_received_stock ON stock_levels');
        $this->addSql('DROP FUNCTION restore_received_stock()');
        $this->addSql('ALTER TABLE stock_levels DROP COLUMN withdrawn');
    }
}
