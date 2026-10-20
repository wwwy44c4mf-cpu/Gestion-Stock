<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021010000 extends AbstractMigration
{
    public function getDescription(): string {return 'MAIL-01 : journal technique des tentatives sans adresses ni diagnostics SMTP';}
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE notification_delivery_attempts (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,event_id uuid NOT NULL REFERENCES notification_events(id) ON DELETE RESTRICT,attempt_number integer NOT NULL CHECK(attempt_number>0),result_code varchar(40) NOT NULL CHECK(result_code IN ('sent','smtp_delivery_failed','recipient_unavailable','notification_render_failed')),created_at timestamptz NOT NULL DEFAULT clock_timestamp())");
        $this->addSql('CREATE INDEX notification_attempts_event_idx ON notification_delivery_attempts(event_id,created_at)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS(SELECT 1 FROM notification_delivery_attempts) THEN RAISE EXCEPTION 'Retour arrière refusé : historique de livraison présent'; END IF; END $$");$this->addSql('DROP TABLE notification_delivery_attempts');
    }
}
