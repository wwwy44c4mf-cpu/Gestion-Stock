<?php

declare(strict_types=1);
namespace App\Command;
use App\Notification\DailySummaryGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:notifications:daily-summary',description:'Prépare le récapitulatif du jour après 08 h Europe/Paris, sans doublon')]
final class DailySummaryCommand extends Command
{
    public function __construct(private readonly DailySummaryGenerator $generator) {parent::__construct();}
    protected function execute(InputInterface $input,OutputInterface $output): int {$output->writeln($this->generator->generate().' récapitulatif(s) préparé(s).');return Command::SUCCESS;}
}
