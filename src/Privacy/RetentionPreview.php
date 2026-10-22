<?php

declare(strict_types=1);

namespace App\Privacy;

use Doctrine\DBAL\Connection;

/** Simulation only: no personal identifiers or text may leave this service. */
final class RetentionPreview
{
    public function __construct(private readonly Connection $db) {}

    public function read(?\DateTimeImmutable $now = null): array
    {
        // Use one database clock for the whole report. A repeatable-read snapshot
        // also prevents a concurrent closure from producing contradictory counts.
        if ($this->db->isTransactionActive()) {
            throw new \LogicException('privacy_preview_requires_own_transaction');
        }
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->db->executeStatement("SET LOCAL TIME ZONE 'UTC'");
            $at = $now?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')
                ?? $this->db->fetchOne('SELECT transaction_timestamp()::text');
            $count = fn (string $sql): int => (int) $this->db->fetchOne($sql, array_fill(0, substr_count($sql, '?'), $at));
            // PostgreSQL adds a calendar year and clamps 29 February to 28
            // February. The anniversary itself is included in eligibility.
            $report = [
                'mode' => 'preview',
                'as_of' => (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'retention' => 'one_calendar_year_utc_inclusive',
                'counts' => [
                    'movements_due' => $count("SELECT count(*) FROM stock_movements WHERE created_at + interval '1 year' <= ?::timestamptz"),
                    'audit_rows_due' => $count("SELECT count(*) FROM audit_logs WHERE created_at + interval '1 year' <= ?::timestamptz"),
                    'closed_inventories_creator_due' => $count("SELECT count(*) FROM inventories WHERE status IN ('closed','cancelled') AND created_at + interval '1 year' <= ?::timestamptz"),
                    'open_inventories_deferred' => $count("SELECT count(*) FROM inventories WHERE status NOT IN ('closed','cancelled') AND created_at + interval '1 year' <= ?::timestamptz"),
                    'closed_proposals_author_due' => $count("SELECT count(*) FROM article_proposals WHERE status <> 'pending' AND created_at + interval '1 year' <= ?::timestamptz"),
                    'open_proposals_deferred' => $count("SELECT count(*) FROM article_proposals WHERE status = 'pending' AND created_at + interval '1 year' <= ?::timestamptz"),
                    'notifications_due' => $count("SELECT count(*) FROM notification_events WHERE created_at + interval '1 year' <= ?::timestamptz"),
                    'information_campaigns_due' => $count("SELECT count(*) FROM information_campaigns WHERE created_at + interval '1 year' <= ?::timestamptz"),
                    // A recent correction can restore a nominative link to an
                    // old consumption through the correction relation.
                    'mixed_age_consumption_corrections' => $count("SELECT count(*) FROM movement_corrections c JOIN stock_movements o ON o.id=c.original_id JOIN stock_movements r ON r.id=c.replacement_id WHERE o.type='consumption' AND o.created_at + interval '1 year' <= ?::timestamptz AND r.created_at > o.created_at AND r.created_at + interval '1 year' > ?::timestamptz"),
                ],
                'account_identity_eligibility' => 'deactivation_clock_with_open_case_deferral',
                'accounts_due' => $count("SELECT count(*) FROM users u WHERE NOT active AND anonymized_at IS NULL AND deactivated_at+interval '1 year'<=?::timestamptz AND NOT EXISTS(SELECT 1 FROM inventories i WHERE i.status NOT IN ('closed','cancelled') AND (i.created_by=u.id OR i.validated_by=u.id OR EXISTS(SELECT 1 FROM inventory_lines l WHERE l.inventory_id=i.id AND l.counted_by=u.id))) AND NOT EXISTS(SELECT 1 FROM article_proposals p WHERE p.status='pending' AND p.proposed_by=u.id)"),
                'execution_available' => false,
            ];
            $this->db->commit();
            return $report;
        } catch (\Throwable $error) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
