<?php

declare(strict_types=1);
if(getenv('OPS_PRODUCTION_FIXTURE')!=='1'||getenv('DATABASE_HOST')!=='db'||getenv('MAIL_DELIVERY_ENABLED')!=='0') {throw new RuntimeException('Recette isolée obligatoire.');}
require dirname(__DIR__,2).'/vendor/autoload.php';
$kernel=new App\Kernel('prod',false);$kernel->boot();$db=$kernel->getContainer()->get('doctrine')->getConnection();
if((int)$db->fetchOne('SELECT count(*) FROM notification_events')!==0) {throw new RuntimeException('Notification fixture must be empty');}
$user=$db->fetchOne("SELECT id FROM users WHERE email='recovery@example.test' AND NOT development_only");if(!$user) {throw new RuntimeException('Fixture user required');}
$id=$db->fetchOne("INSERT INTO notification_events(type,event_key,recipient_id,payload,status) VALUES ('information','ops03-fixture',?,?,'queued') RETURNING id",[$user,json_encode(['subject'=>'private-marker-subject','body'=>'private-marker-body','path'=>'/profil'])]);
$kernel->getContainer()->get('messenger.default_bus')->dispatch(new App\Message\DeliverNotification($id));
echo "Notification de recette mise en file.\n";
