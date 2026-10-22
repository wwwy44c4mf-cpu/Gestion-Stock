CREATE FUNCTION privacy_account_due(target uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM users u WHERE u.id=target AND NOT u.active AND u.anonymized_at IS NULL AND u.deactivated_at+interval '1 year'<=transaction_timestamp()
 AND NOT EXISTS(SELECT 1 FROM inventories i WHERE i.status NOT IN ('closed','cancelled') AND (i.created_by=u.id OR i.validated_by=u.id OR EXISTS(SELECT 1 FROM inventory_lines l WHERE l.inventory_id=i.id AND l.counted_by=u.id)))
 AND NOT EXISTS(SELECT 1 FROM article_proposals p WHERE p.status='pending' AND p.proposed_by=u.id))
$$;
-- migrate:statement
CREATE FUNCTION privacy_release_accounts() RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 DELETE FROM user_creation_requests WHERE privacy_account_due(user_id) OR privacy_account_due(actor_id);
 DELETE FROM daily_summary_items WHERE run_id IN (SELECT id FROM daily_notification_runs WHERE privacy_account_due(recipient_id));
 DELETE FROM daily_notification_runs WHERE privacy_account_due(recipient_id);
 DELETE FROM information_campaign_recipients WHERE privacy_account_due(user_id);
 DELETE FROM notification_delivery_attempts WHERE event_id IN (SELECT id FROM notification_events WHERE privacy_account_due(recipient_id));
 DELETE FROM notification_events WHERE privacy_account_due(recipient_id);
 DELETE FROM password_reset_tokens WHERE privacy_account_due(user_id);
 DELETE FROM user_stores WHERE privacy_account_due(user_id);
 DELETE FROM alert_recipients WHERE privacy_account_due(user_id);
 UPDATE audit_logs SET actor_id=NULL,target_id=NULL,context='{}' WHERE privacy_account_due(target_id);
 UPDATE users SET name=NULL,email=NULL,password_hash=NULL,active=false,anonymized_at=transaction_timestamp(),auth_version=auth_version+1,timezone='Europe/Paris' WHERE privacy_account_due(id);
END $$;
-- migrate:statement
CREATE FUNCTION privacy_finalize_closed_case() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE anonymous uuid:='00000000-0000-4000-8000-000000000001';
BEGIN
 IF (TG_TABLE_NAME='inventories' AND NEW.status NOT IN ('closed','cancelled')) OR (TG_TABLE_NAME='article_proposals' AND NEW.status='pending') THEN RETURN NEW; END IF;
 IF NEW.created_at+interval '1 year'>transaction_timestamp() THEN RETURN NEW; END IF;
 INSERT INTO users(id,active,anonymized_at,role_id) VALUES(anonymous,false,transaction_timestamp(),3) ON CONFLICT(id) DO NOTHING;
 IF TG_TABLE_NAME='inventories' THEN
  UPDATE inventories SET created_by=CASE WHEN created_at+interval '1 year'<=transaction_timestamp() THEN anonymous ELSE created_by END,
   validated_by=CASE WHEN closed_at+interval '1 year'<=transaction_timestamp() THEN anonymous ELSE validated_by END WHERE id=NEW.id;
  UPDATE inventory_lines SET counted_by=anonymous WHERE inventory_id=NEW.id AND counted_at+interval '1 year'<=transaction_timestamp() AND counted_by<>anonymous;
  DELETE FROM inventory_requests WHERE inventory_id=NEW.id;
 ELSE
  UPDATE article_proposals SET proposed_by=CASE WHEN created_at+interval '1 year'<=transaction_timestamp() THEN anonymous ELSE proposed_by END,
   payload=CASE WHEN created_at+interval '1 year'<=transaction_timestamp() THEN '{}'::jsonb ELSE payload END,
   reviewed_by=CASE WHEN reviewed_at+interval '1 year'<=transaction_timestamp() THEN anonymous ELSE reviewed_by END,
   review_comment=CASE WHEN reviewed_at+interval '1 year'<=transaction_timestamp() THEN NULL ELSE review_comment END WHERE id=NEW.id;
  DELETE FROM proposal_requests WHERE proposal_id=NEW.id;
 END IF;
 UPDATE audit_logs SET actor_id=NULL,target_id=NULL,context='{}' WHERE target_id=NEW.id AND created_at+interval '1 year'<=transaction_timestamp();
 PERFORM privacy_release_accounts();
 INSERT INTO privacy_runs(counts) VALUES ('{"closed_cases":1}');
 RETURN NEW;
END $$;
-- migrate:statement
CREATE TRIGGER inventories_privacy_closed AFTER UPDATE OF status ON inventories FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status) EXECUTE FUNCTION privacy_finalize_closed_case();
-- migrate:statement
CREATE TRIGGER proposals_privacy_closed AFTER UPDATE OF status ON article_proposals FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status) EXECUTE FUNCTION privacy_finalize_closed_case();
