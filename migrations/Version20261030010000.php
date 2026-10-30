<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010000 extends AbstractMigration
{
    public function getDescription(): string { return 'USR-03 : demandes de compte validées et création du mot de passe par invitation'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE account_requests (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 first_name varchar(80),last_name varchar(80),email varchar(254),
 status varchar(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','expired')),
 user_id uuid UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
 reviewed_by uuid REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),reviewed_at timestamptz,activated_at timestamptz,anonymized_at timestamptz,
 CHECK((anonymized_at IS NULL AND first_name IS NOT NULL AND btrim(first_name)<>'' AND last_name IS NOT NULL AND btrim(last_name)<>'' AND email IS NOT NULL AND btrim(email)<>'')
 OR (anonymized_at IS NOT NULL AND first_name IS NULL AND last_name IS NULL AND email IS NULL AND user_id IS NULL AND reviewed_by IS NULL AND status<>'pending')),
 CHECK((status='pending' AND reviewed_at IS NULL AND user_id IS NULL) OR (status<>'pending' AND reviewed_at IS NOT NULL)),
 CHECK(user_id IS NULL OR status='approved'),CHECK(activated_at IS NULL OR status='approved')
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX account_requests_pending_email ON account_requests(lower(btrim(email))) WHERE status='pending'");
        $this->addSql('CREATE INDEX account_requests_queue ON account_requests(status,created_at,id)');
        $this->addSql("ALTER TABLE password_reset_tokens ADD purpose varchar(16) NOT NULL DEFAULT 'reset' CHECK(purpose IN ('reset','activation'))");
        $this->addSql('ALTER TABLE notification_events DROP CONSTRAINT notification_events_type_check');
        $this->addSql("ALTER TABLE notification_events ADD CONSTRAINT notification_events_type_check CHECK(type IN ('stock_low','stock_critical','password_reset','account_activation','information','daily_summary'))");
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM account_requests) OR EXISTS(SELECT 1 FROM password_reset_tokens WHERE purpose='activation') OR EXISTS(SELECT 1 FROM notification_events WHERE type='account_activation') THEN RAISE EXCEPTION 'Retour arrière refusé : demandes ou invitations présentes'; END IF; END $$");
        $this->addSql('DROP TABLE account_requests');
        $this->addSql('ALTER TABLE password_reset_tokens DROP COLUMN purpose');
        $this->addSql('ALTER TABLE notification_events DROP CONSTRAINT notification_events_type_check');
        $this->addSql("ALTER TABLE notification_events ADD CONSTRAINT notification_events_type_check CHECK(type IN ('stock_low','stock_critical','password_reset','information','daily_summary'))");
    }
}
