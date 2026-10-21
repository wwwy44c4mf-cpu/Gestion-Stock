<?php

declare(strict_types=1);
namespace App\Command;
use App\Operations\OperationalStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:ops:status',description:'Diagnostic agrégé en lecture seule, réservé aux opérateurs du serveur.')]
final class OperationalStatusCommand extends Command
{
    public function __construct(private readonly OperationalStatus $status) {parent::__construct();}
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $report=$this->status->read();$output->writeln(json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return $report['status']==='ok'?Command::SUCCESS:Command::FAILURE;
    }
}
