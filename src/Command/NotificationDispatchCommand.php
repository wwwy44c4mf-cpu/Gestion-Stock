<?php

declare(strict_types=1);
namespace App\Command;
use App\Notification\NotificationDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:notifications:dispatch',description:'Met en file les courriels persistants prêts à être traités')]
final class NotificationDispatchCommand extends Command
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {parent::__construct();}
    protected function execute(InputInterface $input,OutputInterface $output): int {$output->writeln($this->dispatcher->dispatch().' notification(s) mise(s) en file.');return Command::SUCCESS;}
}
