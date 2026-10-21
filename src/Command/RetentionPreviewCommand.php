<?php

declare(strict_types=1);

namespace App\Command;

use App\Privacy\RetentionPreview;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:privacy:preview', description: 'Simulation agrégée en lecture seule du délai de conservation ; aucun effacement.')]
final class RetentionPreviewCommand extends Command
{
    public function __construct(private readonly RetentionPreview $preview) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->preview->read(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
