<?php

declare(strict_types=1);

namespace App\Shared\DTO\User;

/**
 * Vacation days per leave year (fork).
 *
 * The ledger keeps one running balance. This view splits it by the year in which the
 * absence is taken: days left in the current year, then, for every following year,
 * the allowance of that year plus whatever is carried over, minus what is already booked.
 */
readonly class YearlyBalanceDTO
{
    /**
     * @param int                                                                 $currentYear      the calendar year the current leave year starts in (e.g. 2026)
     * @param int                                                                 $currentRemaining days still free in the current leave year
     * @param list<array{year: int, allowance: int, booked: int, remaining: int}> $future           following leave years, in order
     */
    public function __construct(
        public int $currentYear,
        public int $currentRemaining,
        public array $future,
    ) {
    }

    /** Days still free at the end of the leave year with the given index (0 = current). */
    public function remainingAt(int $periodIndex): int
    {
        if (0 === $periodIndex) {
            return $this->currentRemaining;
        }

        $last = $this->currentRemaining;
        foreach ($this->future as $i => $row) {
            $last = $row['remaining'];
            if ($i + 1 === $periodIndex) {
                return $last;
            }
        }

        return $last;
    }

    /**
     * First following year, or null when there is none.
     *
     * @return array{year: int, allowance: int, booked: int, remaining: int}|null
     */
    public function next(): ?array
    {
        return $this->future[0] ?? null;
    }
}
