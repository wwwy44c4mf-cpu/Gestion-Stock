DO $$
DECLARE table_name text;
DECLARE has_data boolean;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['users', 'stores', 'user_stores', 'categories', 'articles', 'article_proposals', 'stock_levels', 'stock_movements', 'inventories', 'inventory_lines', 'stock_alerts', 'alert_recipients', 'notification_events', 'daily_notification_runs', 'password_reset_tokens', 'audit_logs'] LOOP
        EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I LIMIT 1)', table_name) INTO has_data;
        IF has_data THEN
            RAISE EXCEPTION 'Retour arrière refusé : données présentes dans % ; restaurer une sauvegarde selon la procédure', table_name;
        END IF;
    END LOOP;
END;
$$;
-- migrate:statement
DROP VIEW stock_reconciliation;
-- migrate:statement
DROP VIEW stock_ledger;
-- migrate:statement
DROP TABLE daily_notification_runs, notification_events, alert_recipients, stock_alerts, stock_movements, inventory_lines, inventories, stock_levels, article_proposals, articles, categories, user_stores, audit_logs, password_reset_tokens, stores, users, roles;
-- migrate:statement
DROP FUNCTION reject_history_mutation();
-- migrate:statement
DROP FUNCTION protect_article_reference();
-- migrate:statement
DROP FUNCTION validate_related_movement();
-- migrate:statement
DROP DOMAIN stock_quantity;
