<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261020230000 extends AbstractMigration
{
    public function getDescription(): string { return 'INV-02 : version de ligne pour protéger les comptages concurrents'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_lines ADD version bigint NOT NULL DEFAULT 0 CHECK (version BETWEEN 0 AND 9007199254740991)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION bump_inventory_line_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.version >= 9007199254740991 THEN RAISE EXCEPTION 'Limite de version atteinte' USING ERRCODE = '23514'; END IF;
    NEW.version := OLD.version + 1;
    RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER inventory_lines_version BEFORE UPDATE ON inventory_lines FOR EACH ROW EXECUTE FUNCTION bump_inventory_line_version()');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM inventory_lines WHERE version > 0) THEN RAISE EXCEPTION 'Retour arrière refusé : comptages versionnés présents'; END IF; END $$");
        $this->addSql('DROP TRIGGER inventory_lines_version ON inventory_lines');$this->addSql('DROP FUNCTION bump_inventory_line_version()');$this->addSql('ALTER TABLE inventory_lines DROP COLUMN version');
    }
}
