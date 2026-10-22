<?php
// Synthetic data only, in a disposable QA database that has no host port.
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
if(getenv('QA_FIXTURE')!=='1'||getenv('DATABASE_HOST')!=='db'||getenv('DATABASE_NAME')!=='privacy_test'||getenv('MAIL_DELIVERY_ENABLED')!=='0') {throw new RuntimeException('qa_fixture_guard');}
$kernel=new App\Kernel('test',false);$kernel->boot();$db=$kernel->getContainer()->get('doctrine')->getConnection();
if(($argv[1]??'seed')==='verify') {
 $report=['stock_reconciliation_differences'=>(int)$db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'),'negative_balances'=>(int)$db->fetchOne('SELECT count(*) FROM stock_levels WHERE quantity<0'),'stores'=>(int)$db->fetchOne('SELECT count(*) FROM stores'),'articles'=>(int)$db->fetchOne('SELECT count(*) FROM articles'),'users'=>(int)$db->fetchOne('SELECT count(*) FROM users'),'movements'=>(int)$db->fetchOne('SELECT count(*) FROM stock_movements'),'pending_notifications'=>(int)$db->fetchOne("SELECT count(*) FROM notification_events WHERE status IN ('pending','queued')")];
 file_put_contents('/qa-output/integrity.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
 if($report['stock_reconciliation_differences']!==0||$report['negative_balances']!==0||$report['stores']!==2||$report['articles']!==20||$report['users']!==5) {throw new RuntimeException('qa_integrity_failed');}
 echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit;
}
if((int)$db->fetchOne('SELECT count(*) FROM users')!==0||(int)$db->fetchOne('SELECT count(*) FROM stock_movements')!==0) {throw new RuntimeException('qa_empty_database_required');}
$fixture=$db->transactional(function()use($db):array {
 $stores=[];foreach(['A','B'] as $code) {$stores[$code]=$db->fetchOne('INSERT INTO stores(code,name) VALUES (?,?) RETURNING id',['QA-'.$code,'Magasin fictif '.$code]);}
 $users=[];$hash=password_hash('QA-fixture-only-2026!',PASSWORD_ARGON2ID);
 foreach([['admin',1,'A'],['manager-a',2,'A'],['manager-b',2,'B'],['technician-a',3,'A'],['technician-b',3,'B']] as [$name,$role,$store]) {
  $id=$db->fetchOne('INSERT INTO users(email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test','QA '.$name,$hash,$role]);
  $users[]=['id'=>$id,'email'=>$name.'@example.test','store_id'=>$stores[$store],'role'=>$role];
  $db->insert('user_stores',['user_id'=>$id,'store_id'=>$stores[$store]]);
  if($role===1) {$db->insert('user_stores',['user_id'=>$id,'store_id'=>$stores['B']]);}
 }
 $articles=[];
 for($i=1;$i<=20;$i++) {
  $id=$db->fetchOne('INSERT INTO articles(sku,name) VALUES (?,?) RETURNING id',[sprintf('QA-%03d',$i),'Article fictif '.$i]);$articles[]=$id;
  foreach($stores as $code=>$store) {$db->executeStatement("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,reason,created_at,idempotency_key) VALUES ('entry',?,?,?,?, 'Initialisation fictive',clock_timestamp()-interval '361 days',?)",[$id,$store,($i===20&&$code==='A')?5:1000,$users[0]['id'],'qa-initial-'.$i.'-'.$code]);}
 }
 // Exactly ten historical movements per day (two entries, eight consumptions).
 $db->executeStatement(<<<'SQL'
 INSERT INTO stock_movements(type,article_id,source_store_id,destination_store_id,quantity,author_id,consumer_id,reason,created_at,idempotency_key)
 SELECT CASE WHEN i IN (0,5) THEN 'entry' ELSE 'consumption' END,
 (?::uuid[])[(day*10+i)%19+1],
 CASE WHEN i IN (0,5) THEN NULL ELSE (?::uuid[])[(day/2+i)%2+1] END,
 CASE WHEN i IN (0,5) THEN (?::uuid[])[(day/2+i)%2+1] ELSE NULL END,
 CASE WHEN i IN (0,5) THEN 10 ELSE 1 END,?::uuid,
 CASE WHEN i IN (0,5) THEN NULL ELSE (?::uuid[])[(day/2+i)%2+1] END,
 CASE WHEN i IN (0,5) THEN 'Réception fictive' ELSE NULL END,
 clock_timestamp()-interval '360 days'+day*interval '1 day'+i*interval '1 hour',
 'qa-history-'||day||'-'||i FROM generate_series(0,359) day CROSS JOIN generate_series(0,9) i
 SQL,['{'.implode(',',$articles).'}','{'.implode(',',$stores).'}','{'.implode(',',$stores).'}',$users[0]['id'],'{'.$users[3]['id'].','.$users[4]['id'].'}']);
 $db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity) SELECT article_id,store_id,sum(quantity) FROM stock_ledger GROUP BY article_id,store_id');
 $db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id,created_at) SELECT author_id,coalesce(source_store_id,destination_store_id),'stock.'||type,'stock_movement',id,created_at FROM stock_movements");
 $db->executeStatement('INSERT INTO stock_change_events(movement_id,article_id,store_ids,created_at,processed_at) SELECT id,article_id,ARRAY[coalesce(source_store_id,destination_store_id)],created_at,created_at FROM stock_movements');
 return ['stores'=>$stores,'users'=>$users,'articles'=>$articles,'history_days'=>360,'history_movements'=>3600,'initial_entries'=>40,'runtime'=>['php'=>PHP_VERSION,'postgres'=>$db->fetchOne('SHOW server_version')]];
});
file_put_contents('/qa-output/fixture.json',json_encode($fixture,JSON_THROW_ON_ERROR)."\n");
echo "Recette fictive prête : cinq comptes, deux magasins, vingt articles, 3 600 mouvements historiques.\n";
