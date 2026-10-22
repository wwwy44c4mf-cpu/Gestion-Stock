<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000000 extends AbstractMigration
{
    public function getDescription(): string { return 'RGPD-01 : horloge de désactivation et état du traitement de conservation'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD deactivated_at timestamptz');
        // Never infer a historical deactivation from a profile update timestamp.
        $this->addSql('UPDATE users SET deactivated_at=clock_timestamp() WHERE NOT active');
        $this->addSql(<<<'SQL'
CREATE FUNCTION track_user_deactivation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN NEW.deactivated_at:=CASE WHEN NEW.active THEN NULL ELSE clock_timestamp() END;
 ELSIF NEW.active THEN NEW.deactivated_at:=NULL;
 ELSIF OLD.active THEN NEW.deactivated_at:=clock_timestamp();
 ELSE NEW.deactivated_at:=OLD.deactivated_at;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER users_deactivation_clock BEFORE INSERT OR UPDATE ON users FOR EACH ROW EXECUTE FUNCTION track_user_deactivation()');
        $this->addSql('CREATE TABLE IF NOT EXISTS runtime_restore_gate(singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton),privacy_required boolean NOT NULL)');
        $this->addSql('INSERT INTO runtime_restore_gate VALUES(true,false) ON CONFLICT(singleton) DO NOTHING');
        $this->addSql('CREATE TABLE privacy_runs (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,completed_at timestamptz NOT NULL DEFAULT clock_timestamp(),counts jsonb NOT NULL CHECK(jsonb_typeof(counts)=\'object\'))');
        // Narrow, value-checked erasure paths for finalizing old open cases.
        // No session flag grants arbitrary history mutation to application code.
        $guards = [
            'guard_inventory_transition' => "IF TG_OP='UPDATE' AND OLD.status IN ('closed','cancelled') AND (to_jsonb(NEW)-ARRAY['created_by','validated_by'])=(to_jsonb(OLD)-ARRAY['created_by','validated_by']) AND (NEW.created_by IS NOT DISTINCT FROM OLD.created_by OR (NEW.created_by='00000000-0000-4000-8000-000000000001'::uuid AND OLD.created_at+interval '1 year'<=transaction_timestamp())) AND (NEW.validated_by IS NOT DISTINCT FROM OLD.validated_by OR (NEW.validated_by='00000000-0000-4000-8000-000000000001'::uuid AND OLD.closed_at+interval '1 year'<=transaction_timestamp())) THEN RETURN NEW; END IF;",
            'guard_inventory_line' => "IF TG_OP='UPDATE' AND NEW.counted_by='00000000-0000-4000-8000-000000000001'::uuid AND OLD.counted_at+interval '1 year'<=transaction_timestamp() AND (to_jsonb(NEW)-ARRAY['counted_by','delta'])=(to_jsonb(OLD)-ARRAY['counted_by','delta']) AND EXISTS(SELECT 1 FROM inventories WHERE id=OLD.inventory_id AND status IN ('closed','cancelled')) THEN RETURN NEW; END IF;",
            'bump_inventory_line_version' => "IF NEW.counted_by='00000000-0000-4000-8000-000000000001'::uuid AND OLD.counted_at+interval '1 year'<=transaction_timestamp() AND (to_jsonb(NEW)-ARRAY['counted_by','delta'])=(to_jsonb(OLD)-ARRAY['counted_by','delta']) AND EXISTS(SELECT 1 FROM inventories WHERE id=OLD.inventory_id AND status IN ('closed','cancelled')) THEN RETURN NEW; END IF;",
            'reject_history_mutation' => "IF TG_TABLE_NAME='audit_logs' AND TG_OP='UPDATE' AND (to_jsonb(NEW)-ARRAY['actor_id','target_id','context'])=(to_jsonb(OLD)-ARRAY['actor_id','target_id','context']) AND (to_jsonb(NEW)->>'actor_id') IS NULL AND (to_jsonb(NEW)->>'target_id') IS NULL AND to_jsonb(NEW)->'context'='{}'::jsonb AND (to_jsonb(OLD)->>'created_at')::timestamptz+interval '1 year'<=transaction_timestamp() THEN RETURN NEW; END IF; IF TG_TABLE_NAME='inventory_requests' AND TG_OP='DELETE' AND (to_jsonb(OLD)->>'created_at')::timestamptz+interval '1 year'<=transaction_timestamp() AND EXISTS(SELECT 1 FROM inventories WHERE id=(to_jsonb(OLD)->>'inventory_id')::uuid AND status IN ('closed','cancelled')) THEN RETURN OLD; END IF;",
        ];
        $guards['reject_history_mutation'] = "IF TG_OP='DELETE' AND ((TG_TABLE_NAME='information_campaign_recipients' AND privacy_account_due((to_jsonb(OLD)->>'user_id')::uuid)) OR (TG_TABLE_NAME='daily_notification_runs' AND privacy_account_due((to_jsonb(OLD)->>'recipient_id')::uuid)) OR (TG_TABLE_NAME='daily_summary_items' AND EXISTS(SELECT 1 FROM daily_notification_runs WHERE id=(to_jsonb(OLD)->>'run_id')::uuid AND privacy_account_due(recipient_id)))) THEN RETURN OLD; END IF; IF TG_TABLE_NAME='audit_logs' AND TG_OP='UPDATE' AND (to_jsonb(NEW)-ARRAY['actor_id','target_id','context'])=(to_jsonb(OLD)-ARRAY['actor_id','target_id','context']) AND (to_jsonb(NEW)->>'actor_id') IS NULL AND (to_jsonb(NEW)->>'target_id') IS NULL AND to_jsonb(NEW)->'context'='{}'::jsonb AND privacy_account_due((to_jsonb(OLD)->>'target_id')::uuid) THEN RETURN NEW; END IF; IF TG_TABLE_NAME='inventory_requests' AND TG_OP='DELETE' AND EXISTS(SELECT 1 FROM inventories WHERE id=(to_jsonb(OLD)->>'inventory_id')::uuid AND status IN ('closed','cancelled') AND created_at+interval '1 year'<=transaction_timestamp()) THEN RETURN OLD; END IF; ".$guards['reject_history_mutation'];
        foreach ($guards as $name => $guard) {
            $definition = $this->connection->fetchOne('SELECT pg_get_functiondef(?::regprocedure)', [$name.'()']);
            $this->addSql(preg_replace('/BEGIN/', "BEGIN\n ".$guard, $definition, 1));
        }
        foreach (explode('-- migrate:statement', file_get_contents(__DIR__.'/sql/017_privacy_finalize.sql')) as $sql) {
            if (trim($sql)!=='') { $this->addSql($sql); }
        }

    }
    public function down(Schema $schema): void
    {
        $this->abortIf((bool)$this->connection->fetchOne('SELECT EXISTS(SELECT 1 FROM privacy_runs)'), 'Retour arrière refusé après traitement de conservation.');
        $this->addSql('DROP TRIGGER inventories_privacy_closed ON inventories');
        $this->addSql('DROP TRIGGER proposals_privacy_closed ON article_proposals');
        $this->addSql('DROP FUNCTION privacy_finalize_closed_case()');
        foreach (['Version20261020220000','Version20261020230000'] as $version) {
            $class=__NAMESPACE__.'\\'.$version;
            $migration=new $class($this->connection,new \Psr\Log\NullLogger());
            $migration->up($schema);
            foreach($migration->getSql() as $query) {
                $sql=$query->getStatement();
                if (preg_match('/^CREATE FUNCTION (guard_inventory_transition|guard_inventory_line|bump_inventory_line_version)\(/',$sql)) {
                    $this->addSql(str_replace('CREATE FUNCTION','CREATE OR REPLACE FUNCTION',$sql));
                }
            }
        }
        foreach(explode('-- migrate:statement',file_get_contents(__DIR__.'/sql/001_schema.sql')) as $sql) {
            if(str_contains($sql,'CREATE FUNCTION reject_history_mutation()')) {$this->addSql(str_replace('CREATE FUNCTION','CREATE OR REPLACE FUNCTION',$sql));}
        }
        $this->addSql('DROP FUNCTION privacy_release_accounts()');
        $this->addSql('DROP FUNCTION privacy_account_due(uuid)');
        $this->addSql('DROP TABLE runtime_restore_gate');
        $this->addSql('DROP TABLE privacy_runs');$this->addSql('DROP TRIGGER users_deactivation_clock ON users');$this->addSql('DROP FUNCTION track_user_deactivation()');$this->addSql('ALTER TABLE users DROP COLUMN deactivated_at');
    }
}
