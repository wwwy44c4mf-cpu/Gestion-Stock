\set ON_ERROR_STOP on
-- Operator-only, offline, atomic. This is never a callable application function.
BEGIN;
SET LOCAL TIME ZONE 'UTC';
SET LOCAL lock_timeout='15s';
SET LOCAL statement_timeout='120s';
DO $$ BEGIN
 IF NOT (SELECT rolsuper FROM pg_roles WHERE rolname=current_user) THEN
  RAISE EXCEPTION 'privacy_operator_required';
 END IF;
END $$;
-- Access-exclusive locks prevent any reader/writer from seeing a partial purge.
LOCK TABLE users,user_stores,alert_recipients,stock_movements,movement_corrections,stock_levels,stock_change_events,
 inventories,inventory_lines,article_proposals,audit_logs,user_creation_requests,
 store_creation_requests,catalog_creation_requests,proposal_requests,inventory_requests,
 notification_events,notification_delivery_attempts,information_campaigns,
 information_campaign_recipients,daily_notification_runs,daily_summary_items,
 password_reset_tokens IN ACCESS EXCLUSIVE MODE;
CREATE TEMP TABLE privacy_clock ON COMMIT DROP AS SELECT transaction_timestamp() AS at;
CREATE TEMP TABLE privacy_open_users ON COMMIT DROP AS
 SELECT created_by id FROM inventories WHERE status NOT IN ('closed','cancelled')
 UNION SELECT validated_by FROM inventories WHERE status NOT IN ('closed','cancelled') AND validated_by IS NOT NULL
 UNION SELECT l.counted_by FROM inventory_lines l JOIN inventories i ON i.id=l.inventory_id WHERE i.status NOT IN ('closed','cancelled') AND l.counted_by IS NOT NULL
 UNION SELECT proposed_by FROM article_proposals WHERE status='pending';
CREATE TEMP TABLE privacy_users ON COMMIT DROP AS
 SELECT id FROM users WHERE NOT active AND anonymized_at IS NULL
 AND deactivated_at + interval '1 year' <= (SELECT at FROM privacy_clock)
 AND id NOT IN (SELECT id FROM privacy_open_users);
CREATE TEMP TABLE privacy_due ON COMMIT DROP AS
 SELECT id FROM stock_movements WHERE created_at + interval '1 year' <= (SELECT at FROM privacy_clock)
 AND (author_id<>'00000000-0000-4000-8000-000000000001'::uuid OR reference IS NOT NULL OR comment IS NOT NULL OR request_fingerprint IS NOT NULL);
-- Complete connected correction chains, never retain a recent beneficiary that
-- could reattribute an old original. The quantitative relation is retained.
CREATE TEMP TABLE privacy_consumer_edges(a uuid,b uuid) ON COMMIT DROP;
INSERT INTO privacy_consumer_edges SELECT original_id,replacement_id FROM movement_corrections
 UNION ALL SELECT replacement_id,original_id FROM movement_corrections;
-- Automatic deposits link several consumptions for the same beneficiary. Their
-- connected component must lose that beneficiary together, including recent rows.
-- The dynamic query also supports restoring a pre-group backup before migration.
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='stock_movements' AND column_name='return_group_id') THEN
  EXECUTE $edges$
   WITH roots AS (SELECT DISTINCT ON(return_group_id) return_group_id,related_movement_id origin FROM stock_movements WHERE return_group_id IS NOT NULL ORDER BY return_group_id,id),
   pairs AS (SELECT roots.origin a,m.related_movement_id b FROM roots JOIN stock_movements m USING(return_group_id))
   INSERT INTO privacy_consumer_edges SELECT a,b FROM pairs UNION SELECT b,a FROM pairs
  $edges$;
 END IF;
END $$;
CREATE TEMP TABLE privacy_consumers ON COMMIT DROP AS
 WITH RECURSIVE edges(a,b) AS (
  SELECT a,b FROM privacy_consumer_edges
 ), chain(id) AS (
  SELECT id FROM stock_movements WHERE type='consumption' AND created_at + interval '1 year' <= (SELECT at FROM privacy_clock)
  UNION SELECT e.b FROM edges e JOIN chain c ON e.a=c.id
 ) SELECT id FROM chain;
CREATE TEMP TABLE privacy_inventories ON COMMIT DROP AS
 SELECT id FROM inventories WHERE status IN ('closed','cancelled');
CREATE TEMP TABLE privacy_proposals ON COMMIT DROP AS
 SELECT id FROM article_proposals WHERE status<>'pending';
CREATE TEMP TABLE privacy_notifications ON COMMIT DROP AS
 SELECT id FROM notification_events WHERE created_at + interval '1 year' <= (SELECT at FROM privacy_clock)
 OR recipient_id IN (SELECT id FROM privacy_users)
 OR information_id IN (SELECT id FROM information_campaigns WHERE created_at + interval '1 year' <= (SELECT at FROM privacy_clock));
DO $$ BEGIN
 IF to_regclass('messenger_messages') IS NOT NULL THEN
  LOCK TABLE messenger_messages IN ACCESS EXCLUSIVE MODE;
  DELETE FROM messenger_messages WHERE created_at+interval '1 year'<=(SELECT at FROM privacy_clock) OR EXISTS(SELECT 1 FROM privacy_notifications p WHERE position(p.id::text IN messenger_messages.body)>0);
 END IF;
END $$;
CREATE TEMP TABLE privacy_balances ON COMMIT DROP AS TABLE stock_levels;
CREATE TEMP TABLE privacy_ledger ON COMMIT DROP AS SELECT * FROM stock_ledger;
-- A single common non-login identity, never one pseudonym per person. The
-- existing non-null FK contracts remain intact without preserving a user map.
INSERT INTO users(id,active,anonymized_at,role_id) VALUES('00000000-0000-4000-8000-000000000001',false,(SELECT at FROM privacy_clock),3) ON CONFLICT(id) DO NOTHING;
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM users WHERE id='00000000-0000-4000-8000-000000000001' AND (active OR anonymized_at IS NULL OR email IS NOT NULL OR name IS NOT NULL OR password_hash IS NOT NULL)) THEN RAISE EXCEPTION 'privacy_identity_conflict'; END IF;
END $$;
-- Only named history guards, only in this privileged transaction. Constraints
-- and all stock/foreign-key checks remain enabled. Rollback restores guards.
ALTER TABLE stock_movements DISABLE TRIGGER movements_immutable;
ALTER TABLE audit_logs DISABLE TRIGGER audit_immutable;
ALTER TABLE inventories DISABLE TRIGGER inventories_transition_guard;
ALTER TABLE inventory_lines DISABLE TRIGGER inventory_lines_guard;
ALTER TABLE inventory_lines DISABLE TRIGGER inventory_lines_version;
ALTER TABLE inventory_requests DISABLE TRIGGER inventory_requests_immutable;
ALTER TABLE information_campaigns DISABLE TRIGGER information_campaigns_immutable;
ALTER TABLE information_campaign_recipients DISABLE TRIGGER information_recipients_immutable;
ALTER TABLE daily_notification_runs DISABLE TRIGGER daily_notification_runs_immutable;
ALTER TABLE daily_summary_items DISABLE TRIGGER daily_summary_items_immutable;
UPDATE stock_movements SET author_id='00000000-0000-4000-8000-000000000001',
 consumer_id=CASE WHEN type='consumption' THEN '00000000-0000-4000-8000-000000000001'::uuid ELSE NULL END,
 reason=CASE WHEN type IN ('entry','adjustment','reversal') THEN '[Effacé]' ELSE NULL END,
 reference=NULL,comment=NULL,idempotency_key=id::text,request_fingerprint=NULL
 WHERE id IN (SELECT id FROM privacy_due);
UPDATE stock_movements SET consumer_id='00000000-0000-4000-8000-000000000001',reference=NULL,comment=NULL
 WHERE id IN (SELECT id FROM privacy_consumers) AND consumer_id<>'00000000-0000-4000-8000-000000000001';
UPDATE stock_movements SET reference=NULL,comment=NULL WHERE related_movement_id IN (SELECT id FROM privacy_consumers) AND (reference IS NOT NULL OR comment IS NOT NULL);
UPDATE inventories SET
 created_by=CASE WHEN created_at+interval '1 year'<=(SELECT at FROM privacy_clock) THEN '00000000-0000-4000-8000-000000000001'::uuid ELSE created_by END,
 validated_by=CASE WHEN closed_at+interval '1 year'<=(SELECT at FROM privacy_clock) THEN '00000000-0000-4000-8000-000000000001'::uuid ELSE validated_by END
 WHERE id IN (SELECT id FROM privacy_inventories);
UPDATE inventory_lines SET counted_by='00000000-0000-4000-8000-000000000001'
 WHERE inventory_id IN (SELECT id FROM privacy_inventories) AND counted_at+interval '1 year'<=(SELECT at FROM privacy_clock);
UPDATE article_proposals SET
 proposed_by=CASE WHEN created_at+interval '1 year'<=(SELECT at FROM privacy_clock) THEN '00000000-0000-4000-8000-000000000001'::uuid ELSE proposed_by END,
 payload=CASE WHEN created_at+interval '1 year'<=(SELECT at FROM privacy_clock) THEN '{}'::jsonb ELSE payload END,
 reviewed_by=CASE WHEN reviewed_at+interval '1 year'<=(SELECT at FROM privacy_clock) THEN '00000000-0000-4000-8000-000000000001'::uuid ELSE reviewed_by END,
 review_comment=CASE WHEN reviewed_at+interval '1 year'<=(SELECT at FROM privacy_clock) THEN NULL ELSE review_comment END
 WHERE id IN (SELECT id FROM privacy_proposals);
UPDATE audit_logs SET actor_id=NULL,target_id=NULL,context='{}'
 WHERE (created_at+interval '1 year'<=(SELECT at FROM privacy_clock)
 OR target_id IN (SELECT id FROM privacy_due UNION SELECT id FROM privacy_consumers UNION SELECT id FROM privacy_users UNION SELECT id FROM inventories WHERE id IN (SELECT id FROM privacy_inventories) AND created_at+interval '1 year'<=(SELECT at FROM privacy_clock) UNION SELECT id FROM article_proposals WHERE id IN (SELECT id FROM privacy_proposals) AND created_at+interval '1 year'<=(SELECT at FROM privacy_clock)))
 AND NOT EXISTS(SELECT 1 FROM inventories i WHERE i.id=audit_logs.target_id AND i.status NOT IN ('closed','cancelled'))
 AND NOT EXISTS(SELECT 1 FROM article_proposals p WHERE p.id=audit_logs.target_id AND p.status='pending');
-- Cached responses are copies, not harmless hashes. Expiring them removes
-- their replay capability; movements themselves retain the quantitative trace.
DELETE FROM user_creation_requests WHERE created_at+interval '1 year'<=(SELECT at FROM privacy_clock) OR user_id IN (SELECT id FROM privacy_users) OR actor_id IN (SELECT id FROM privacy_users);
DELETE FROM store_creation_requests WHERE created_at+interval '1 year'<=(SELECT at FROM privacy_clock) OR actor_id IN (SELECT id FROM privacy_users);
DELETE FROM catalog_creation_requests WHERE created_at+interval '1 year'<=(SELECT at FROM privacy_clock) OR actor_id IN (SELECT id FROM privacy_users);
DELETE FROM proposal_requests WHERE proposal_id IN (SELECT id FROM privacy_proposals) AND (created_at+interval '1 year'<=(SELECT at FROM privacy_clock) OR proposal_id IN (SELECT id FROM article_proposals WHERE proposed_by='00000000-0000-4000-8000-000000000001'));
DELETE FROM inventory_requests WHERE inventory_id IN (SELECT id FROM privacy_inventories) AND (created_at+interval '1 year'<=(SELECT at FROM privacy_clock) OR inventory_id IN (SELECT id FROM inventories WHERE created_by='00000000-0000-4000-8000-000000000001'));
DELETE FROM daily_summary_items WHERE run_id IN (SELECT id FROM daily_notification_runs WHERE event_id IN (SELECT id FROM privacy_notifications));
DELETE FROM daily_notification_runs WHERE event_id IN (SELECT id FROM privacy_notifications);
DELETE FROM information_campaign_recipients WHERE notification_id IN (SELECT id FROM privacy_notifications);
DELETE FROM notification_delivery_attempts WHERE event_id IN (SELECT id FROM privacy_notifications);
DELETE FROM notification_events WHERE id IN (SELECT id FROM privacy_notifications);
UPDATE information_campaigns SET author_id='00000000-0000-4000-8000-000000000001',subject='[Effacé]',message='[Effacé]',request_key=id::text,request_fingerprint=repeat('0',64)
 WHERE created_at+interval '1 year'<=(SELECT at FROM privacy_clock);
DELETE FROM password_reset_tokens WHERE expires_at<=(SELECT at FROM privacy_clock) OR user_id IN (SELECT id FROM privacy_users);
DELETE FROM user_stores WHERE user_id IN (SELECT id FROM privacy_users);
DELETE FROM alert_recipients WHERE user_id IN (SELECT id FROM privacy_users);
UPDATE users SET name=NULL,email=NULL,password_hash=NULL,active=false,anonymized_at=(SELECT at FROM privacy_clock),auth_version=auth_version+1,timezone='Europe/Paris'
 WHERE id IN (SELECT id FROM privacy_users);
ALTER TABLE stock_movements ENABLE TRIGGER movements_immutable;
ALTER TABLE audit_logs ENABLE TRIGGER audit_immutable;
ALTER TABLE inventories ENABLE TRIGGER inventories_transition_guard;
ALTER TABLE inventory_lines ENABLE TRIGGER inventory_lines_guard;
ALTER TABLE inventory_lines ENABLE TRIGGER inventory_lines_version;
ALTER TABLE inventory_requests ENABLE TRIGGER inventory_requests_immutable;
ALTER TABLE information_campaigns ENABLE TRIGGER information_campaigns_immutable;
ALTER TABLE information_campaign_recipients ENABLE TRIGGER information_recipients_immutable;
ALTER TABLE daily_notification_runs ENABLE TRIGGER daily_notification_runs_immutable;
ALTER TABLE daily_summary_items ENABLE TRIGGER daily_summary_items_immutable;
DO $$ BEGIN
 IF EXISTS((SELECT * FROM stock_levels EXCEPT SELECT * FROM privacy_balances) UNION ALL (SELECT * FROM privacy_balances EXCEPT SELECT * FROM stock_levels))
 OR EXISTS((SELECT * FROM stock_ledger EXCEPT SELECT * FROM privacy_ledger) UNION ALL (SELECT * FROM privacy_ledger EXCEPT SELECT * FROM stock_ledger)) THEN RAISE EXCEPTION 'privacy_quantity_invariant'; END IF;
END $$;
INSERT INTO privacy_runs(counts) SELECT jsonb_build_object('movements',(SELECT count(*) FROM privacy_due),'consumer_chain_rows',(SELECT count(*) FROM privacy_consumers),'accounts',(SELECT count(*) FROM privacy_users),'notifications',(SELECT count(*) FROM privacy_notifications));
CREATE TABLE IF NOT EXISTS runtime_restore_gate(singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton),privacy_required boolean NOT NULL);
INSERT INTO runtime_restore_gate VALUES(true,false) ON CONFLICT(singleton) DO UPDATE SET privacy_required=false;
COMMIT;
