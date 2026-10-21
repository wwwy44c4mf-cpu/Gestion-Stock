<?php
// Disposable OPS-01 installation only. Refuse to seed an existing application.
require dirname(__DIR__,2).'/vendor/autoload.php';
$kernel=new App\Kernel('dev',false);$kernel->boot();
$db=$kernel->getContainer()->get('doctrine')->getConnection();
if ((int)$db->fetchOne('SELECT count(*) FROM users')!==0 || getenv('OPS_PRODUCTION_FIXTURE')!=='1' || getenv('DATABASE_HOST')!=='db' || getenv('MAIL_DELIVERY_ENABLED')!=='0') { throw new RuntimeException('Recette isolée et base vide requises.'); }
$db->transactional(function()use($db){
    $user=$db->fetchOne("INSERT INTO users(email,name,password_hash,role_id,development_only) VALUES ('recovery@example.test','Recette de reprise',?,1,false) RETURNING id",[password_hash('Recovery-fixture-2026!',PASSWORD_ARGON2ID)]);
    $db->executeStatement("INSERT INTO users(email,name,password_hash,role_id,development_only) VALUES ('admin.dev@example.test','Compte dev interdit',?,1,true)",[password_hash('Recovery-fixture-2026!',PASSWORD_ARGON2ID)]);
    $store=$db->fetchOne("INSERT INTO stores(code,name) VALUES ('RECOVERY','Magasin de recette') RETURNING id");
    $article=$db->fetchOne("INSERT INTO articles(sku,name) VALUES ('RECOVERY','Article de recette') RETURNING id");
    $entry=$db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,idempotency_key,reason) VALUES ('entry',?,?,10,?,'recovery-entry','Réception fictive de recette') RETURNING id",[$article,$store,$user]);
    $out=$db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,idempotency_key) VALUES ('consumption',?,?,3,?,?,'recovery-consumption') RETURNING id",[$article,$store,$user,$user]);
    $ret=$db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,related_movement_id,idempotency_key) VALUES ('return',?,?,1,?,?,'recovery-return') RETURNING id",[$article,$store,$user,$out]);
    $db->insert('stock_levels',['article_id'=>$article,'store_id'=>$store,'quantity'=>8,'low_threshold'=>3,'critical_threshold'=>1]);
    foreach([$entry=>'stock.entry',$out=>'stock.consumption',$ret=>'stock.return'] as $id=>$action) {$db->insert('audit_logs',['actor_id'=>$user,'store_id'=>$store,'action'=>$action,'target_type'=>'stock_movement','target_id'=>$id]);}
    $db->executeStatement("INSERT INTO inventories(store_id,created_by) VALUES (?,?)",[$store,$user]);
    $db->executeStatement("INSERT INTO messenger_messages(body,headers,queue_name,created_at,available_at) VALUES ('durable-recovery-fixture','{}','ops_recovery_probe',clock_timestamp(),clock_timestamp())");
});
echo "Jeu isolé de reprise prêt.\n";
