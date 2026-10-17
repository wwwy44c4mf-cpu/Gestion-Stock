CREATE DOMAIN stock_quantity AS numeric
    CHECK (VALUE = trunc(VALUE) AND abs(VALUE) <= 9007199254740991);
-- migrate:statement
CREATE TABLE roles (
    id smallint PRIMARY KEY,
    code varchar(16) NOT NULL UNIQUE CHECK (code IN ('ADMIN', 'MANAGER', 'USER'))
);
-- migrate:statement
INSERT INTO roles (id, code) VALUES (1, 'ADMIN'), (2, 'MANAGER'), (3, 'USER');
-- migrate:statement
CREATE TABLE users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email varchar(254),
    password_hash varchar(255),
    name varchar(160),
    role_id smallint NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
    active boolean NOT NULL DEFAULT true,
    auth_version bigint NOT NULL DEFAULT 0 CHECK (auth_version >= 0),
    timezone varchar(64) NOT NULL DEFAULT 'Europe/Paris',
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anonymized_at timestamptz,
    CONSTRAINT user_identity CHECK (
        (anonymized_at IS NULL AND email IS NOT NULL AND btrim(email) <> '' AND name IS NOT NULL AND btrim(name) <> '' AND password_hash IS NOT NULL AND btrim(password_hash) <> '')
        OR (anonymized_at IS NOT NULL AND NOT active AND email IS NULL AND name IS NULL AND password_hash IS NULL)
    )
);
-- migrate:statement
CREATE UNIQUE INDEX users_email_unique ON users (lower(btrim(email))) WHERE email IS NOT NULL;
-- migrate:statement
CREATE TABLE stores (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code varchar(40) NOT NULL CHECK (btrim(code) <> ''),
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
    description text NOT NULL DEFAULT '',
    address text,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- migrate:statement
CREATE UNIQUE INDEX stores_code_unique ON stores (lower(btrim(code)));
-- migrate:statement
CREATE TABLE user_stores (
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    store_id uuid NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, store_id)
);
-- migrate:statement
CREATE INDEX user_stores_store_idx ON user_stores (store_id, user_id);
-- migrate:statement
CREATE TABLE categories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
    parent_id uuid REFERENCES categories(id) ON DELETE RESTRICT,
    active boolean NOT NULL DEFAULT true,
    CHECK (parent_id IS DISTINCT FROM id)
);
-- migrate:statement
CREATE INDEX categories_parent_idx ON categories (parent_id);
-- migrate:statement
CREATE TABLE articles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    sku varchar(80) NOT NULL CHECK (btrim(sku) <> ''),
    name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
    description text NOT NULL DEFAULT '',
    category_id uuid REFERENCES categories(id) ON DELETE RESTRICT,
    unit_code varchar(16) NOT NULL DEFAULT 'unit' CHECK (unit_code = 'unit'),
    active boolean NOT NULL DEFAULT true,
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- migrate:statement
CREATE UNIQUE INDEX articles_sku_unique ON articles (lower(btrim(sku)));
-- migrate:statement
CREATE INDEX articles_category_status_idx ON articles (category_id, active);
-- migrate:statement
CREATE TABLE article_proposals (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    operation varchar(16) NOT NULL CHECK (operation IN ('create', 'update')),
    article_id uuid REFERENCES articles(id) ON DELETE RESTRICT,
    published_article_id uuid REFERENCES articles(id) ON DELETE RESTRICT,
    base_version bigint CHECK (base_version > 0),
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    proposed_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    reviewed_by uuid REFERENCES users(id) ON DELETE RESTRICT,
    review_comment text,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at timestamptz,
    CHECK ((operation = 'create' AND article_id IS NULL AND base_version IS NULL) OR (operation = 'update' AND article_id IS NOT NULL AND base_version IS NOT NULL)),
    CHECK ((status = 'pending' AND reviewed_by IS NULL AND reviewed_at IS NULL AND published_article_id IS NULL) OR (status IN ('approved', 'rejected') AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
    CHECK (status <> 'approved' OR published_article_id IS NOT NULL)
);
-- migrate:statement
CREATE INDEX proposals_pending_idx ON article_proposals (status, created_at);
-- migrate:statement
CREATE TABLE stock_levels (
    article_id uuid NOT NULL REFERENCES articles(id) ON DELETE RESTRICT,
    store_id uuid NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
    quantity stock_quantity NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    low_threshold stock_quantity CHECK (low_threshold >= 0),
    critical_threshold stock_quantity CHECK (critical_threshold >= 0),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    PRIMARY KEY (article_id, store_id),
    CHECK (critical_threshold IS NULL OR (low_threshold IS NOT NULL AND critical_threshold <= low_threshold))
);
-- migrate:statement
CREATE INDEX stock_levels_store_idx ON stock_levels (store_id, article_id);
-- migrate:statement
CREATE TABLE inventories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    store_id uuid NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
    status varchar(24) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'in_progress', 'pending_validation', 'closed', 'cancelled')),
    created_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    validated_by uuid REFERENCES users(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    snapshot_at timestamptz,
    closed_at timestamptz,
    cancelled_at timestamptz,
    CHECK (status NOT IN ('in_progress', 'pending_validation', 'closed') OR snapshot_at IS NOT NULL),
    CHECK (status <> 'closed' OR (validated_by IS NOT NULL AND closed_at IS NOT NULL)),
    CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)
);
-- migrate:statement
CREATE UNIQUE INDEX inventories_one_active_per_store ON inventories (store_id) WHERE status IN ('in_progress', 'pending_validation');
-- migrate:statement
CREATE INDEX inventories_store_date_idx ON inventories (store_id, created_at DESC);
-- migrate:statement
CREATE TABLE inventory_lines (
    inventory_id uuid NOT NULL REFERENCES inventories(id) ON DELETE RESTRICT,
    article_id uuid NOT NULL REFERENCES articles(id) ON DELETE RESTRICT,
    theoretical_qty stock_quantity NOT NULL CHECK (theoretical_qty >= 0),
    counted_qty stock_quantity CHECK (counted_qty >= 0),
    delta numeric GENERATED ALWAYS AS (counted_qty - theoretical_qty) STORED,
    counted_by uuid REFERENCES users(id) ON DELETE RESTRICT,
    counted_at timestamptz,
    PRIMARY KEY (inventory_id, article_id),
    CHECK ((counted_qty IS NULL AND counted_by IS NULL AND counted_at IS NULL) OR (counted_qty IS NOT NULL AND counted_by IS NOT NULL AND counted_at IS NOT NULL))
);
-- migrate:statement
CREATE TABLE stock_movements (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    type varchar(16) NOT NULL CHECK (type IN ('entry', 'consumption', 'adjustment', 'transfer', 'return', 'reversal')),
    article_id uuid NOT NULL REFERENCES articles(id) ON DELETE RESTRICT,
    source_store_id uuid REFERENCES stores(id) ON DELETE RESTRICT,
    destination_store_id uuid REFERENCES stores(id) ON DELETE RESTRICT,
    quantity stock_quantity NOT NULL CHECK (quantity > 0),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    consumer_id uuid REFERENCES users(id) ON DELETE RESTRICT,
    related_movement_id uuid REFERENCES stock_movements(id) ON DELETE RESTRICT,
    inventory_id uuid REFERENCES inventories(id) ON DELETE RESTRICT,
    reason text,
    reference varchar(255),
    comment text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    idempotency_key varchar(160) NOT NULL CHECK (btrim(idempotency_key) <> ''),
    request_fingerprint varchar(64) CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    UNIQUE (author_id, idempotency_key),
    CHECK (source_store_id IS NOT NULL OR destination_store_id IS NOT NULL),
    CHECK (source_store_id IS NULL OR destination_store_id IS NULL OR source_store_id <> destination_store_id),
    CHECK (
        (type IN ('entry', 'return') AND source_store_id IS NULL AND destination_store_id IS NOT NULL)
        OR (type = 'consumption' AND source_store_id IS NOT NULL AND destination_store_id IS NULL)
        OR (type = 'transfer' AND source_store_id IS NOT NULL AND destination_store_id IS NOT NULL)
        OR (type = 'adjustment' AND ((source_store_id IS NULL) <> (destination_store_id IS NULL)))
        OR type = 'reversal'
    ),
    CHECK ((type = 'consumption' AND consumer_id IS NOT NULL) OR (type <> 'consumption' AND consumer_id IS NULL)),
    CHECK (type NOT IN ('entry', 'adjustment', 'reversal') OR (reason IS NOT NULL AND btrim(reason) <> '')),
    CHECK ((type IN ('return', 'reversal') AND related_movement_id IS NOT NULL) OR (type NOT IN ('return', 'reversal') AND related_movement_id IS NULL)),
    CHECK (related_movement_id IS DISTINCT FROM id),
    CHECK (inventory_id IS NULL OR type = 'adjustment')
);
-- migrate:statement
CREATE INDEX movements_article_date_idx ON stock_movements (article_id, created_at DESC);
-- migrate:statement
CREATE INDEX movements_source_date_idx ON stock_movements (source_store_id, created_at DESC) WHERE source_store_id IS NOT NULL;
-- migrate:statement
CREATE INDEX movements_destination_date_idx ON stock_movements (destination_store_id, created_at DESC) WHERE destination_store_id IS NOT NULL;
-- migrate:statement
CREATE INDEX movements_consumer_date_idx ON stock_movements (consumer_id, created_at DESC) WHERE consumer_id IS NOT NULL;
-- migrate:statement
CREATE INDEX movements_date_idx ON stock_movements (created_at DESC);
-- migrate:statement
CREATE INDEX movements_inventory_idx ON stock_movements (inventory_id) WHERE inventory_id IS NOT NULL;
-- migrate:statement
CREATE INDEX movements_related_idx ON stock_movements (related_movement_id) WHERE related_movement_id IS NOT NULL;
-- migrate:statement
CREATE UNIQUE INDEX movements_one_reversal_idx ON stock_movements (related_movement_id) WHERE type = 'reversal';
-- migrate:statement
CREATE FUNCTION validate_related_movement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original stock_movements%ROWTYPE;
BEGIN
    IF NEW.related_movement_id IS NOT NULL THEN
        SELECT * INTO original FROM stock_movements WHERE id = NEW.related_movement_id;
        IF NOT FOUND OR original.article_id <> NEW.article_id THEN
            RAISE EXCEPTION 'Opération d’origine absente ou article différent' USING ERRCODE = '23514';
        END IF;
        IF NEW.type = 'return' AND (original.type <> 'consumption' OR NEW.destination_store_id IS DISTINCT FROM original.source_store_id) THEN
            RAISE EXCEPTION 'Un retour doit viser la consommation et son magasin d’origine' USING ERRCODE = '23514';
        END IF;
        IF NEW.type = 'reversal' AND (
            NEW.source_store_id IS DISTINCT FROM original.destination_store_id
            OR NEW.destination_store_id IS DISTINCT FROM original.source_store_id
            OR NEW.quantity <> original.quantity
        ) THEN
            RAISE EXCEPTION 'Un contre-mouvement doit compenser exactement l’origine' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
-- migrate:statement
CREATE TRIGGER movements_reference_valid BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION validate_related_movement();
-- migrate:statement
CREATE FUNCTION reject_history_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Historique immuable : créer une opération compensatrice' USING ERRCODE = '23514';
END;
$$;
-- migrate:statement
CREATE TRIGGER movements_immutable BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION reject_history_mutation();
-- migrate:statement
CREATE FUNCTION protect_article_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.sku IS DISTINCT FROM OLD.sku THEN
        RAISE EXCEPTION 'La référence article est immuable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
-- migrate:statement
CREATE TRIGGER article_reference_immutable BEFORE UPDATE ON articles FOR EACH ROW EXECUTE FUNCTION protect_article_reference();
-- migrate:statement
CREATE TABLE stock_alerts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    article_id uuid NOT NULL,
    store_id uuid NOT NULL,
    level varchar(16) NOT NULL CHECK (level IN ('low', 'critical')),
    status varchar(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'resolved')),
    threshold stock_quantity NOT NULL CHECK (threshold >= 0),
    opened_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at timestamptz,
    FOREIGN KEY (article_id, store_id) REFERENCES stock_levels(article_id, store_id) ON DELETE RESTRICT,
    CHECK ((status = 'active' AND closed_at IS NULL) OR (status = 'resolved' AND closed_at IS NOT NULL AND closed_at >= opened_at))
);
-- migrate:statement
CREATE UNIQUE INDEX alerts_one_active_idx ON stock_alerts (article_id, store_id) WHERE status = 'active';
-- migrate:statement
CREATE INDEX alerts_store_status_idx ON stock_alerts (store_id, status, level);
-- migrate:statement
CREATE TABLE alert_recipients (
    store_id uuid NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    alert_type varchar(16) NOT NULL CHECK (alert_type IN ('low', 'critical', 'daily_summary')),
    PRIMARY KEY (store_id, user_id, alert_type)
);
-- migrate:statement
CREATE TABLE notification_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    event_key varchar(255) NOT NULL UNIQUE CHECK (btrim(event_key) <> ''),
    type varchar(32) NOT NULL CHECK (type IN ('stock_low', 'stock_critical', 'password_reset', 'information', 'daily_summary')),
    recipient_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    store_id uuid REFERENCES stores(id) ON DELETE RESTRICT,
    alert_id uuid REFERENCES stock_alerts(id) ON DELETE RESTRICT,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'queued', 'sent', 'failed')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    available_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at timestamptz,
    last_error_code varchar(80),
    CHECK (status <> 'sent' OR sent_at IS NOT NULL),
    UNIQUE (id, recipient_id)
);
-- migrate:statement
CREATE INDEX notification_dispatch_idx ON notification_events (status, available_at) WHERE status IN ('pending', 'failed');
-- migrate:statement
CREATE INDEX notification_recipient_date_idx ON notification_events (recipient_id, created_at);
-- migrate:statement
CREATE TABLE daily_notification_runs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    recipient_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    local_date date NOT NULL,
    timezone varchar(64) NOT NULL DEFAULT 'Europe/Paris' CHECK (timezone = 'Europe/Paris'),
    event_id uuid NOT NULL UNIQUE,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (recipient_id, local_date),
    FOREIGN KEY (event_id, recipient_id) REFERENCES notification_events(id, recipient_id) ON DELETE RESTRICT
);
-- migrate:statement
CREATE TABLE password_reset_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    token_hash varchar(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamptz NOT NULL,
    used_at timestamptz,
    CHECK (expires_at > created_at),
    CHECK (used_at IS NULL OR used_at >= created_at)
);
-- migrate:statement
CREATE INDEX password_reset_user_idx ON password_reset_tokens (user_id, expires_at);
-- migrate:statement
CREATE TABLE audit_logs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_id uuid REFERENCES users(id) ON DELETE RESTRICT,
    store_id uuid REFERENCES stores(id) ON DELETE RESTRICT,
    action varchar(80) NOT NULL CHECK (btrim(action) <> ''),
    target_type varchar(80) NOT NULL,
    target_id uuid,
    context jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(context) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
-- migrate:statement
CREATE INDEX audit_store_date_idx ON audit_logs (store_id, created_at DESC);
-- migrate:statement
CREATE INDEX audit_actor_date_idx ON audit_logs (actor_id, created_at DESC);
-- migrate:statement
CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_history_mutation();
-- migrate:statement
CREATE VIEW stock_ledger AS
    SELECT id AS movement_id, article_id, source_store_id AS store_id, -quantity AS quantity, created_at
    FROM stock_movements WHERE source_store_id IS NOT NULL
    UNION ALL
    SELECT id AS movement_id, article_id, destination_store_id AS store_id, quantity, created_at
    FROM stock_movements WHERE destination_store_id IS NOT NULL;
-- migrate:statement
CREATE VIEW stock_reconciliation AS
    WITH journal AS (
        SELECT article_id, store_id, sum(quantity) AS journal_quantity
        FROM stock_ledger GROUP BY article_id, store_id
    )
    SELECT coalesce(s.article_id, j.article_id) AS article_id,
           coalesce(s.store_id, j.store_id) AS store_id,
           s.quantity AS stored_quantity,
           coalesce(j.journal_quantity, 0) AS journal_quantity,
           s.quantity - coalesce(j.journal_quantity, 0) AS difference,
           s.article_id IS NULL AS missing_balance
    FROM stock_levels s FULL OUTER JOIN journal j USING (article_id, store_id);
