<?php

declare(strict_types=1);
if(getenv('OPS_PRODUCTION_FIXTURE')!=='1'||getenv('DATABASE_HOST')!=='db') {throw new RuntimeException('Recette isolée obligatoire.');}
require dirname(__DIR__,2).'/vendor/autoload.php';$kernel=new App\Kernel('prod',false);$kernel->boot();$db=$kernel->getContainer()->get('doctrine')->getConnection();
try {$db->executeStatement("INSERT INTO users(email,name,password_hash,role_id) SELECT email,'private-marker-row','private-marker-hash',role_id FROM users WHERE email='recovery@example.test'");throw new RuntimeException('SQL incident not triggered');}
catch(\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {echo "Erreur SQL de recette interceptée sans détail de ligne.\n";}
