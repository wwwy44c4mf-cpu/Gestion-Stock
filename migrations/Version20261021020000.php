<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021020000 extends AbstractMigration
{
    public function getDescription(): string {return 'ALT-02 : cycle atomique des alertes, transitions immuables et notifications persistantes';}
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE stock_alert_transitions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 article_id uuid NOT NULL,store_id uuid NOT NULL,
 from_level varchar(16) NOT NULL CHECK(from_level IN ('normal','low','critical')),
 to_level varchar(16) NOT NULL CHECK(to_level IN ('normal','low','critical')),
 previous_alert_id uuid REFERENCES stock_alerts(id) ON DELETE RESTRICT,
 alert_id uuid REFERENCES stock_alerts(id) ON DELETE RESTRICT,
 quantity stock_quantity NOT NULL CHECK(quantity>=0),
 low_threshold stock_quantity,critical_threshold stock_quantity,
 cause varchar(16) NOT NULL CHECK(cause IN ('quantity','thresholds','initialization')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(article_id,store_id) REFERENCES stock_levels(article_id,store_id) ON DELETE RESTRICT,
 CHECK(from_level<>to_level),
 CHECK((from_level='normal')=(previous_alert_id IS NULL)),
 CHECK((to_level='normal')=(alert_id IS NULL))
)
SQL);
        $this->addSql('CREATE INDEX alert_transitions_store_date_idx ON stock_alert_transitions(store_id,created_at,id)');
        $this->addSql('CREATE TRIGGER alert_transitions_immutable BEFORE UPDATE OR DELETE ON stock_alert_transitions FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION protect_alert_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='resolved' THEN
  RAISE EXCEPTION 'Historique des alertes immuable' USING ERRCODE='23514';
 END IF;
 IF NEW.article_id IS DISTINCT FROM OLD.article_id OR NEW.store_id IS DISTINCT FROM OLD.store_id
    OR NEW.level IS DISTINCT FROM OLD.level OR NEW.opened_at IS DISTINCT FROM OLD.opened_at THEN
  RAISE EXCEPTION 'Identité de l’alerte immuable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER alerts_history_guard BEFORE UPDATE OR DELETE ON stock_alerts FOR EACH ROW EXECUTE FUNCTION protect_alert_history()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION reconcile_stock_alert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous stock_alerts%ROWTYPE; previous_level text; next_level text; next_threshold stock_quantity;
 next_alert uuid; transition_id uuid; instant timestamptz; source text; details jsonb;
BEGIN
 -- The INSERT/UPDATE already owns the balance row: competing writes to this pair serialize here.
 SELECT * INTO previous FROM stock_alerts WHERE article_id=NEW.article_id AND store_id=NEW.store_id AND status='active' FOR UPDATE;
 previous_level:=coalesce(previous.level,'normal');
 IF NEW.critical_threshold IS NOT NULL AND NEW.quantity<=NEW.critical_threshold THEN
  next_level:='critical';next_threshold:=NEW.critical_threshold;
 ELSIF NEW.low_threshold IS NOT NULL AND NEW.quantity<=NEW.low_threshold THEN
  next_level:='low';next_threshold:=NEW.low_threshold;
 ELSE next_level:='normal';next_threshold:=NULL;
 END IF;
 IF next_level=previous_level THEN
  IF previous.id IS NOT NULL AND previous.threshold IS DISTINCT FROM next_threshold THEN
   UPDATE stock_alerts SET threshold=next_threshold WHERE id=previous.id;
  END IF;
  RETURN NEW;
 END IF;
 instant:=greatest(clock_timestamp(),coalesce(previous.opened_at,'-infinity'::timestamptz));
 IF TG_OP='INSERT' THEN source:='initialization';
 ELSIF NEW.low_threshold IS DISTINCT FROM OLD.low_threshold OR NEW.critical_threshold IS DISTINCT FROM OLD.critical_threshold THEN source:='thresholds';
 ELSIF NEW.quantity IS DISTINCT FROM OLD.quantity THEN source:='quantity';
 ELSE source:='initialization'; END IF;
 IF previous.id IS NOT NULL THEN UPDATE stock_alerts SET status='resolved',closed_at=instant WHERE id=previous.id; END IF;
 IF next_level<>'normal' THEN
  INSERT INTO stock_alerts(article_id,store_id,level,threshold,opened_at) VALUES(NEW.article_id,NEW.store_id,next_level,next_threshold,instant) RETURNING id INTO next_alert;
 END IF;
 INSERT INTO stock_alert_transitions(article_id,store_id,from_level,to_level,previous_alert_id,alert_id,quantity,low_threshold,critical_threshold,cause,created_at)
 VALUES(NEW.article_id,NEW.store_id,previous_level,next_level,previous.id,next_alert,NEW.quantity,NEW.low_threshold,NEW.critical_threshold,source,instant) RETURNING id INTO transition_id;
 -- Resolutions remain in the durable history. Only entry into low/critical emits a stock email.
 IF next_alert IS NOT NULL THEN
  SELECT jsonb_build_object('article',regexp_replace(a.name,'[[:cntrl:]]',' ','g'),'store',regexp_replace(s.name,'[[:cntrl:]]',' ','g'),'quantity',NEW.quantity,'threshold',next_threshold,
    'occurred_at',to_char(instant AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
    'path','/stocks/'||NEW.store_id::text||'/'||NEW.article_id::text)
  INTO details FROM articles a CROSS JOIN stores s WHERE a.id=NEW.article_id AND s.id=NEW.store_id;
  INSERT INTO notification_events(event_key,type,recipient_id,store_id,alert_id,payload)
  SELECT 'stock-alert:'||transition_id::text||':'||u.id::text,'stock_'||next_level,u.id,NEW.store_id,next_alert,details
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.active AND NOT u.development_only AND u.email IS NOT NULL
    AND EXISTS(SELECT 1 FROM stores s WHERE s.id=NEW.store_id AND s.active)
    AND (r.code='ADMIN' OR (r.code='MANAGER' AND EXISTS(SELECT 1 FROM user_stores us WHERE us.user_id=u.id AND us.store_id=NEW.store_id)))
  ON CONFLICT(event_key) DO NOTHING;
 END IF;
 RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER stock_alert_reconciliation AFTER INSERT OR UPDATE OF quantity,low_threshold,critical_threshold ON stock_levels FOR EACH ROW EXECUTE FUNCTION reconcile_stock_alert()');
        // Reconcile existing configured balances without rewriting quantities, versions or the movement ledger.
        $this->addSql('UPDATE stock_levels SET quantity=quantity');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM stock_alert_transitions) THEN RAISE EXCEPTION 'Retour arrière refusé : transitions d’alertes présentes'; END IF; END $$");
        $this->addSql('DROP TRIGGER stock_alert_reconciliation ON stock_levels');$this->addSql('DROP FUNCTION reconcile_stock_alert()');
        $this->addSql('DROP TRIGGER alerts_history_guard ON stock_alerts');$this->addSql('DROP FUNCTION protect_alert_history()');
        $this->addSql('DROP TABLE stock_alert_transitions');
    }
}
