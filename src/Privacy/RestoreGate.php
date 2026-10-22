<?php

declare(strict_types=1);
namespace App\Privacy;
use Doctrine\DBAL\Connection;
final class RestoreGate
{
    public function __construct(private readonly Connection $db) {}
    public function closed(): bool
    {
        if (!$this->db->fetchOne("SELECT to_regclass('runtime_restore_gate')")) { return false; }
        return (bool)$this->db->fetchOne('SELECT EXISTS(SELECT 1 FROM runtime_restore_gate WHERE privacy_required)');
    }
}
