<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018150000 extends AbstractMigration
{
    public function getDescription(): string { return 'ART-02 : rejeux persistants des soumissions et décisions de propositions'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE proposal_requests (
    actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    action varchar(16) NOT NULL CHECK (action IN ('create','review')),
    request_key varchar(160) NOT NULL CHECK (request_key ~ '^[A-Za-z0-9._:-]{8,160}$'),
    request_fingerprint varchar(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    proposal_id uuid NOT NULL REFERENCES article_proposals(id) ON DELETE RESTRICT,
    response jsonb NOT NULL CHECK (jsonb_typeof(response) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (actor_id,action,request_key)
)
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM proposal_requests) THEN RAISE EXCEPTION 'Retour arrière refusé : résultats de propositions présents'; END IF; END $$");
        $this->addSql('DROP TABLE proposal_requests');
    }
}
