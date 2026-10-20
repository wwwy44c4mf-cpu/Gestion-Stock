<?php

declare(strict_types=1);
namespace App\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:notifications:retry',description:'Autorise un nouveau cycle borné pour une notification en échec')]
final class NotificationRetryCommand extends Command
{
    public function __construct(private readonly Connection $db) {parent::__construct();}
    protected function configure(): void {$this->addArgument('id',InputArgument::REQUIRED,'Identifiant de notification');}
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $id=$input->getArgument('id');if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)) {$output->writeln('Identifiant invalide.');return Command::INVALID;}
        $count=$this->db->transactional(function() use($id): int {
            $row=$this->db->fetchAssociative("UPDATE notification_events SET status='pending',attempts=0,available_at=clock_timestamp(),last_error_code=NULL WHERE id=? AND status='failed' RETURNING id,store_id",[$id]);
            if(!$row) {return 0;}
            $this->db->executeStatement("INSERT INTO audit_logs (store_id,action,target_type,target_id,context) VALUES (?,'notification.retry','notification',?,'{}'::jsonb)",[$row['store_id'],$row['id']]);return 1;
        });
        $output->writeln($count===1?'Un nouveau cycle est autorisé ; historique conservé.':'Aucune notification en échec à reprendre.');return $count===1?Command::SUCCESS:Command::FAILURE;
    }
}
