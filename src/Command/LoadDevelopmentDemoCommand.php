<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:dev:load-demo', description: 'Charger une démonstration fictive sur une base de développement vide.')]
final class LoadDevelopmentDemoCommand extends Command
{
    // Public demonstration credential, never accepted outside dev.
    public const PASSWORD = 'Demo-Stock-2026!';

    public function __construct(private readonly Connection $db,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%env(bool:MAIL_DELIVERY_ENABLED)%')] private readonly bool $mailEnabled)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment !== 'dev' || $this->mailEnabled) {
            $io->error('APP_ENV=dev et MAIL_DELIVERY_ENABLED=0 requis. Aucune donnée modifiée.');
            return Command::FAILURE;
        }
        return $this->db->transactional(function () use ($io): int {
            // Serialize with account/catalogue/movement creation before testing emptiness.
            foreach (['users', 'user_stores', 'stores', 'categories', 'articles', 'inventories', 'stock_levels', 'stock_movements'] as $table) {
                $this->db->executeStatement('LOCK TABLE '.$table.' IN EXCLUSIVE MODE');
            }
            foreach (['users', 'stores', 'categories', 'articles', 'inventories', 'stock_levels', 'stock_movements'] as $table) {
                if ((int) $this->db->fetchOne('SELECT count(*) FROM '.$table) > 0) {
                    $io->error('Base non vide : démonstration refusée, aucune donnée remplacée. Utiliser une installation séparée.');
                    return Command::FAILURE;
                }
            }
            $stores = [];
            foreach (['A', 'B'] as $code) {
                $stores[$code] = $this->db->fetchOne('INSERT INTO stores(code,name) VALUES (?,?) RETURNING id', ['DEMO-'.$code, 'Magasin fictif '.$code]);
            }
            $users = [];
            $hash = password_hash(self::PASSWORD, PASSWORD_ARGON2ID);
            foreach (['admin'=>1, 'gestionnaire-a'=>2, 'gestionnaire-b'=>2, 'technicien-a'=>3, 'technicien-b'=>3] as $name=>$role) {
                $users[$name] = $this->db->fetchOne('INSERT INTO users(email,name,password_hash,role_id,development_only) VALUES (?,?,?,?,true) RETURNING id', [$name.'.demo@example.test', 'Démo '.$name, $hash, $role]);
                foreach ($name === 'admin' ? ['A', 'B'] : [str_ends_with($name, '-a') ? 'A' : 'B'] as $store) {
                    $this->db->insert('user_stores', ['user_id'=>$users[$name], 'store_id'=>$stores[$store]]);
                }
            }
            $categories = [];
            foreach (['Fixations', 'Électricité', 'Outillage'] as $name) {
                $categories[] = $this->db->fetchOne('INSERT INTO categories(name) VALUES (?) RETURNING id', [$name.' (démo)']);
            }
            for ($i = 1; $i <= 20; ++$i) {
                $article = $this->db->fetchOne('INSERT INTO articles(sku,name,category_id) VALUES (?,?,?) RETURNING id', [sprintf('DEMO-%03d', $i), 'Article fictif '.$i, $categories[($i - 1) % 3]]);
                foreach ($stores as $code=>$store) {
                    $quantity = $code === 'A' ? ($i === 1 ? 5 : ($i === 2 ? 1 : 20)) : 10;
                    $this->db->executeStatement("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,reason,idempotency_key) VALUES ('entry',?,?,?,?,?,?)", [$article, $store, $quantity, $users['admin'], 'Réception fictive de démonstration', 'demo-entry-'.$i.'-'.$code]);
                }
                if ($i <= 3) {
                    $this->db->executeStatement("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,reference,idempotency_key) VALUES ('consumption',?,?,?,?,?,?,?)", [$article, $stores['A'], $i === 2 ? 1 : 2, $users['technicien-a'], $users['technicien-a'], 'INTERVENTION-FICTIVE', 'demo-consumption-'.$i]);
                }
            }
            // Synthetic initialization; all quantitative history is reflected in balances and audit.
            $this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity,low_threshold,critical_threshold) SELECT article_id,store_id,sum(quantity),5,0 FROM stock_ledger GROUP BY article_id,store_id');
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id) SELECT author_id,coalesce(source_store_id,destination_store_id),'stock.'||type,'stock_movement',id FROM stock_movements");
            $this->db->executeStatement('INSERT INTO stock_change_events(movement_id,article_id,store_ids) SELECT id,article_id,ARRAY[coalesce(source_store_id,destination_store_id)] FROM stock_movements');
            $io->success('Démonstration créée : 5 comptes réservés au développement, 2 magasins, 20 articles. Voir docs/demonstration.md.');
            return Command::SUCCESS;
        });
    }
}
