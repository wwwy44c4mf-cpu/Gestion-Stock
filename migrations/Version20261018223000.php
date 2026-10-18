<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018223000 extends AbstractMigration
{
    public function getDescription(): string { return 'STK-05 : lien immuable entre origine, contre-mouvement et remplacement corrigé'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE movement_corrections (original_id uuid PRIMARY KEY REFERENCES stock_movements(id) ON DELETE RESTRICT, reversal_id uuid NOT NULL UNIQUE REFERENCES stock_movements(id) ON DELETE RESTRICT, replacement_id uuid NOT NULL UNIQUE REFERENCES stock_movements(id) ON DELETE RESTRICT, CHECK (original_id <> reversal_id AND original_id <> replacement_id AND reversal_id <> replacement_id))');
        $this->addSql(<<<'SQL'
CREATE FUNCTION validate_movement_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original stock_movements%ROWTYPE; replacement stock_movements%ROWTYPE;
BEGIN
    SELECT * INTO original FROM stock_movements WHERE id = NEW.original_id;
    SELECT * INTO replacement FROM stock_movements WHERE id = NEW.replacement_id;
    IF original.type NOT IN ('entry','consumption','adjustment','transfer','return') OR original.inventory_id IS NOT NULL
       OR replacement.type IS DISTINCT FROM original.type OR replacement.article_id IS DISTINCT FROM original.article_id
       OR replacement.source_store_id IS DISTINCT FROM original.source_store_id OR replacement.destination_store_id IS DISTINCT FROM original.destination_store_id
       OR replacement.consumer_id IS DISTINCT FROM original.consumer_id OR replacement.related_movement_id IS DISTINCT FROM original.related_movement_id
       OR replacement.quantity = original.quantity OR replacement.inventory_id IS NOT NULL
       OR NOT EXISTS (SELECT 1 FROM stock_movements WHERE id = NEW.reversal_id AND type = 'reversal' AND related_movement_id = NEW.original_id)
    THEN RAISE EXCEPTION 'Paire de correction invalide' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER movement_corrections_valid BEFORE INSERT ON movement_corrections FOR EACH ROW EXECUTE FUNCTION validate_movement_correction()');
        $this->addSql('CREATE TRIGGER movement_corrections_immutable BEFORE UPDATE OR DELETE ON movement_corrections FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM movement_corrections) THEN RAISE EXCEPTION 'Retour arrière refusé : corrections présentes'; END IF; END $$");
        $this->addSql('DROP TABLE movement_corrections');
        $this->addSql('DROP FUNCTION validate_movement_correction()');
    }
}
