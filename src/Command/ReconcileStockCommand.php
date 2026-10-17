<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:stock:reconcile', description: 'Comparer les soldes au journal sans modifier les données.')]
final class ReconcileStockCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $differences = $this->connection->fetchAllAssociative('SELECT * FROM stock_reconciliation WHERE missing_balance OR difference <> 0 ORDER BY store_id, article_id');
        $output->writeln(json_encode(['consistent' => $differences === [], 'differences' => $differences], JSON_THROW_ON_ERROR));
        return $differences === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
