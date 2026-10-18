<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string { return 'AUTH-02 : résultats idempotents des créations de comptes'; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE user_creation_requests (
    actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_key varchar(160) NOT NULL CHECK (request_key ~ '^[A-Za-z0-9._:-]{8,160}$'),
    request_fingerprint varchar(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    response jsonb NOT NULL CHECK (jsonb_typeof(response) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (actor_id, request_key)
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM user_creation_requests) THEN RAISE EXCEPTION 'Retour arrière refusé : créations de comptes présentes'; END IF; END $$");
        $this->addSql('DROP TABLE user_creation_requests');
    }
}
