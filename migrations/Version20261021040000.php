<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021040000 extends AbstractMigration
{
    public function getDescription(): string {return 'MAIL-02 : campagnes d’information idempotentes et audience confirmée';}
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE information_campaigns (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),author_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 request_key varchar(160) NOT NULL,request_fingerprint char(64) NOT NULL,
 subject varchar(200) NOT NULL CHECK(btrim(subject)<>''),message text NOT NULL CHECK(btrim(message)<>'' AND char_length(message)<=10000),
 store_ids uuid[] NOT NULL DEFAULT '{}',roles text[] NOT NULL DEFAULT '{}',
 recipient_count integer NOT NULL CHECK(recipient_count BETWEEN 1 AND 1000),created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(author_id,request_key),CHECK(cardinality(store_ids)>0 OR cardinality(roles)>0),CHECK(roles<@ARRAY['ADMIN','MANAGER','USER']::text[])
)
SQL);
        $this->addSql('ALTER TABLE notification_events ADD information_id uuid REFERENCES information_campaigns(id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE notification_events ADD CONSTRAINT notification_information_type CHECK(information_id IS NULL OR type='information'), ADD CONSTRAINT notification_information_recipient_unique UNIQUE(id,recipient_id,information_id)");
        $this->addSql(<<<'SQL'
CREATE TABLE information_campaign_recipients (
 campaign_id uuid NOT NULL REFERENCES information_campaigns(id) ON DELETE RESTRICT,
 user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,email_fingerprint char(64) NOT NULL,
 notification_id uuid NOT NULL UNIQUE,
 PRIMARY KEY(campaign_id,user_id),FOREIGN KEY(notification_id,user_id,campaign_id) REFERENCES notification_events(id,recipient_id,information_id) ON DELETE RESTRICT
)
SQL);
        $this->addSql('CREATE INDEX information_campaigns_date_idx ON information_campaigns(created_at,id)');
        $this->addSql('CREATE INDEX notifications_information_idx ON notification_events(information_id) WHERE information_id IS NOT NULL');
        $this->addSql('CREATE TRIGGER information_campaigns_immutable BEFORE UPDATE OR DELETE ON information_campaigns FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
        $this->addSql('CREATE TRIGGER information_recipients_immutable BEFORE UPDATE OR DELETE ON information_campaign_recipients FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION information_recipient_eligible(target_event uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(
 SELECT 1 FROM information_campaign_recipients ir JOIN information_campaigns c ON c.id=ir.campaign_id
 JOIN notification_events e ON e.id=ir.notification_id JOIN users u ON u.id=ir.user_id JOIN roles r ON r.id=u.role_id
 WHERE e.id=target_event AND u.active AND u.anonymized_at IS NULL AND NOT u.development_only AND u.email IS NOT NULL
 AND encode(sha256(convert_to(lower(btrim(u.email)),'UTF8')),'hex')=ir.email_fingerprint
 AND (cardinality(c.roles)=0 OR r.code=ANY(c.roles))
 AND (cardinality(c.store_ids)=0 OR EXISTS(SELECT 1 FROM user_stores us JOIN stores s ON s.id=us.store_id WHERE us.user_id=u.id AND s.active AND s.id=ANY(c.store_ids)))
 )
$$
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM information_campaigns) THEN RAISE EXCEPTION 'Retour arrière refusé : campagnes d’information présentes'; END IF; END $$");
        $this->addSql('DROP FUNCTION information_recipient_eligible(uuid)');$this->addSql('DROP TABLE information_campaign_recipients');
        $this->addSql('ALTER TABLE notification_events DROP CONSTRAINT notification_information_recipient_unique,DROP CONSTRAINT notification_information_type,DROP COLUMN information_id');$this->addSql('DROP TABLE information_campaigns');
    }
}
