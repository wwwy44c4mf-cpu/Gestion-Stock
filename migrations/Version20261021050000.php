<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021050000 extends AbstractMigration
{
    public function getDescription(): string {return 'MAIL-03 : lots quotidiens persistants et alertes par récapitulatif';}
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_summary_batches (local_date date PRIMARY KEY,generated_at timestamptz NOT NULL,recipient_count integer NOT NULL CHECK(recipient_count>=0),item_count bigint NOT NULL CHECK(item_count>=0))');
        $this->addSql("ALTER TABLE daily_notification_runs ADD batch_date date REFERENCES daily_summary_batches(local_date) ON DELETE RESTRICT,ADD email_fingerprint char(64),ADD CONSTRAINT daily_batch_date CHECK(batch_date IS NULL OR (batch_date=local_date AND email_fingerprint IS NOT NULL))");
        $this->addSql('CREATE TABLE daily_summary_items (run_id uuid NOT NULL REFERENCES daily_notification_runs(id) ON DELETE RESTRICT,alert_id uuid NOT NULL REFERENCES stock_alerts(id) ON DELETE RESTRICT,PRIMARY KEY(run_id,alert_id))');
        $this->addSql('CREATE INDEX daily_summary_items_alert_idx ON daily_summary_items(alert_id)');
        foreach(['daily_summary_batches','daily_notification_runs','daily_summary_items'] as $table) {$this->addSql('CREATE TRIGGER '.$table.'_immutable BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');}
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM daily_summary_batches) OR EXISTS(SELECT 1 FROM daily_summary_items) THEN RAISE EXCEPTION 'Retour arrière refusé : récapitulatifs quotidiens présents'; END IF; END $$");
        $this->addSql('DROP TRIGGER daily_notification_runs_immutable ON daily_notification_runs');
        $this->addSql('DROP TABLE daily_summary_items');$this->addSql('ALTER TABLE daily_notification_runs DROP CONSTRAINT daily_batch_date,DROP COLUMN batch_date,DROP COLUMN email_fingerprint');$this->addSql('DROP TABLE daily_summary_batches');
    }
}
