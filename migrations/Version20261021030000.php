<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021030000 extends AbstractMigration
{
    public function getDescription(): string {return 'ALT-03 : préférences explicites et résolution des destinataires autorisés';}
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert_recipient_configurations (store_id uuid PRIMARY KEY REFERENCES stores(id) ON DELETE RESTRICT,use_defaults boolean NOT NULL DEFAULT true,version bigint NOT NULL DEFAULT 1 CHECK(version>0))');
        $this->addSql('INSERT INTO alert_recipient_configurations(store_id,use_defaults) SELECT DISTINCT store_id,false FROM alert_recipients');
        $this->addSql(<<<'SQL'
CREATE FUNCTION effective_alert_recipients(target_store uuid,target_type text) RETURNS TABLE(user_id uuid) LANGUAGE sql STABLE AS $$
 SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
 WHERE target_type IN ('low','critical','daily_summary') AND u.active AND u.anonymized_at IS NULL AND NOT u.development_only AND u.email IS NOT NULL
 AND EXISTS(SELECT 1 FROM stores s WHERE s.id=target_store AND s.active)
 AND (r.code='ADMIN' OR (r.code='MANAGER' AND EXISTS(SELECT 1 FROM user_stores us WHERE us.user_id=u.id AND us.store_id=target_store)))
 AND (coalesce((SELECT c.use_defaults FROM alert_recipient_configurations c WHERE c.store_id=target_store),true)
      OR EXISTS(SELECT 1 FROM alert_recipients ar WHERE ar.store_id=target_store AND ar.user_id=u.id AND ar.alert_type=target_type))
$$
SQL);
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION reconcile_stock_alert() RETURNS trigger LANGUAGE plpgsql AS $$
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
  FROM effective_alert_recipients(NEW.store_id,next_level) recipient JOIN users u ON u.id=recipient.user_id
  ON CONFLICT(event_key) DO NOTHING;
 END IF;
 RETURN NEW;
END;
$$
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM alert_recipient_configurations) THEN RAISE EXCEPTION 'Retour arrière refusé : configuration des destinataires présente'; END IF; END $$");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION reconcile_stock_alert() RETURNS trigger LANGUAGE plpgsql AS $$
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
        $this->addSql('DROP FUNCTION effective_alert_recipients(uuid,text)');$this->addSql('DROP TABLE alert_recipient_configurations');
    }
}
