<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Finaliser les comptes arrivés à échéance lors de la clôture d’un dossier récent'; }

    public function up(Schema $schema): void
    {
        $this->replace(
            "IF NEW.created_at+interval '1 year'>transaction_timestamp() THEN RETURN NEW; END IF;",
            "IF NEW.created_at+interval '1 year'>transaction_timestamp() THEN PERFORM privacy_release_accounts(); INSERT INTO privacy_runs(counts) VALUES ('{\"closed_cases\":1}'); RETURN NEW; END IF;",
        );
    }

    public function down(Schema $schema): void
    {
        // Preserve the existing rollback barrier after any recorded retention treatment.
        $this->abortIf((int) $this->connection->fetchOne('SELECT count(*) FROM privacy_runs') > 0, 'Un traitement de conservation interdit ce retour arrière.');
        $this->replace(
            "IF NEW.created_at+interval '1 year'>transaction_timestamp() THEN PERFORM privacy_release_accounts(); INSERT INTO privacy_runs(counts) VALUES ('{\"closed_cases\":1}'); RETURN NEW; END IF;",
            "IF NEW.created_at+interval '1 year'>transaction_timestamp() THEN RETURN NEW; END IF;",
        );
    }

    private function replace(string $before, string $after): void
    {
        $definition=$this->connection->fetchOne("SELECT pg_get_functiondef('privacy_finalize_closed_case()'::regprocedure)");
        $updated=str_replace($before, $after, $definition, $count);
        $this->abortIf($count !== 1, 'Version de la finalisation RGPD inattendue.');
        $this->addSql($updated);
    }
}
