<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018200000 extends AbstractMigration
{
    public function getDescription(): string { return 'STK-02 : événements durables de changement de stock pour les traitements ultérieurs'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE stock_change_events (
    movement_id uuid PRIMARY KEY REFERENCES stock_movements(id) ON DELETE RESTRICT,
    article_id uuid NOT NULL REFERENCES articles(id) ON DELETE RESTRICT,
    store_ids uuid[] NOT NULL CHECK (cardinality(store_ids) BETWEEN 1 AND 2 AND array_position(store_ids,NULL) IS NULL),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    processed_at timestamptz
)
SQL);
        $this->addSql('CREATE INDEX stock_change_events_pending_idx ON stock_change_events (created_at,movement_id) WHERE processed_at IS NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM stock_change_events) THEN RAISE EXCEPTION 'Retour arrière refusé : événements de stock présents'; END IF; END $$");
        $this->addSql('DROP TABLE stock_change_events');
    }
}
