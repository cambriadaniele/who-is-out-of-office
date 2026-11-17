<?php

declare(strict_types=1);

namespace App\Shared\Handler\User\Command;

use App\Shared\Enum\BalanceLedgerEntryTypeEnum;
use App\Shared\Enum\BalanceLedgerSourceEnum;

readonly class RecordBalanceChangeCommand
{
    public function __construct(
        public string $userId,
        public int $delta,
        public BalanceLedgerEntryTypeEnum $type,
        public BalanceLedgerSourceEnum $source,
        public ?string $reason = null,
        public ?string $actorUserId = null,
        public ?string $leaveRequestId = null,
        /** Fork: a booking for a later year may take the running balance below zero, the later year's allowance covers it. */
        public bool $allowNegativeBalance = false,
        /** Fork: for the yearly accrual, the new year's allowance. The days forfeited are worked out when the entry is written (after any catch-up), not before. */
        public ?int $yearAllowance = null,
    ) {
    }
}
