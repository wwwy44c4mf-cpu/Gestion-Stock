<?php

declare(strict_types=1);

namespace App\Security;

/** Central role matrix. Store/ownership checks are applied by AccessPolicy. */
enum Permission: string
{
    case ACCOUNTS_ADMIN = 'APP_ACCOUNTS_ADMIN';
    case STORES_ADMIN = 'APP_STORES_ADMIN';
    case CATALOG_READ = 'APP_CATALOG_READ';
    case CATALOG_ADMIN = 'APP_CATALOG_ADMIN';
    case CATALOG_PROPOSE = 'APP_CATALOG_PROPOSE';
    case PROPOSAL_READ = 'APP_PROPOSAL_READ';
    case STOCK_READ = 'APP_STOCK_READ';
    case ENTRY_CREATE = 'APP_ENTRY_CREATE';
    case CONSUMPTION_CREATE = 'APP_CONSUMPTION_CREATE';
    case CONSUMPTION_READ = 'APP_CONSUMPTION_READ';
    case CONSUMPTION_OTHER = 'APP_CONSUMPTION_OTHER';
    case TRANSFER_CREATE = 'APP_TRANSFER_CREATE';
    case RETURN_CREATE = 'APP_RETURN_CREATE';
    case MOVEMENT_READ = 'APP_MOVEMENT_READ';
    case MOVEMENT_REVERSE = 'APP_MOVEMENT_REVERSE';
    case ADJUSTMENT_CREATE = 'APP_ADJUSTMENT_CREATE';
    case INVENTORY_READ = 'APP_INVENTORY_READ';
    case INVENTORY_WRITE = 'APP_INVENTORY_WRITE';
    case THRESHOLD_WRITE = 'APP_THRESHOLD_WRITE';
    case ALERT_READ = 'APP_ALERT_READ';
    case ALERT_RECIPIENTS = 'APP_ALERT_RECIPIENTS';
    case AUDIT_READ = 'APP_AUDIT_READ';
    case STATISTICS_READ = 'APP_STATISTICS_READ';
    case DASHBOARD_READ = 'APP_DASHBOARD_READ';
    case INFORMATION_SEND = 'APP_INFORMATION_SEND';
    case BENEFICIARIES_READ = 'APP_BENEFICIARIES_READ';

    public function roles(): array
    {
        return match ($this) {
            self::ACCOUNTS_ADMIN, self::STORES_ADMIN, self::CATALOG_ADMIN, self::INFORMATION_SEND => ['ADMIN'],
            self::CATALOG_READ, self::STOCK_READ, self::CONSUMPTION_CREATE, self::CONSUMPTION_READ,
                self::RETURN_CREATE, self::STATISTICS_READ => ['ADMIN', 'MANAGER', 'USER'],
            default => ['ADMIN', 'MANAGER'],
        };
    }

    public function writesStock(): bool
    {
        return in_array($this, [self::ENTRY_CREATE, self::CONSUMPTION_CREATE, self::CONSUMPTION_OTHER,
            self::TRANSFER_CREATE, self::RETURN_CREATE, self::MOVEMENT_REVERSE, self::ADJUSTMENT_CREATE,
            self::INVENTORY_WRITE, self::THRESHOLD_WRITE, self::ALERT_RECIPIENTS], true);
    }
}
