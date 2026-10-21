<?php

declare(strict_types=1);
if (getenv('OPS_PRODUCTION_FIXTURE')!=='1' || getenv('DATABASE_HOST')!=='restore-db' || getenv('MAIL_DELIVERY_ENABLED')!=='0') {throw new RuntimeException('Base de reprise jetable obligatoire.');}
require dirname(__DIR__,2).'/vendor/autoload.php';
$kernel=new App\Kernel('prod',false);$kernel->boot();$db=$kernel->getContainer()->get('doctrine')->getConnection();
$checks=[
    'SELECT count(*) FROM doctrine_migration_versions'=>15,
    'SELECT count(*) FROM stock_movements'=>3,
    'SELECT sum(quantity) FROM stock_levels'=>8,
    'SELECT count(*) FROM inventories'=>1,
    'SELECT count(*) FROM audit_logs'=>3,
    "SELECT count(*) FROM messenger_messages WHERE queue_name='ops_recovery_probe'"=>1,
    "SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0"=>0,
];
foreach($checks as $query=>$expected) {if ((int)$db->fetchOne($query)!==$expected) {throw new RuntimeException('Reprise non conforme.');}}
if ($db->fetchOne("SELECT to_regclass('public.daily_summary_batches')")!==null) {throw new RuntimeException('Le schéma précédent n’a pas été restauré.');}
echo "Retour au schéma précédent (15 migrations), stocks, journal, inventaire, audit et file vérifiés.\n";
