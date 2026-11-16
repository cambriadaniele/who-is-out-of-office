<?php

declare(strict_types=1);

namespace App\Module\User\Service;

use App\Shared\DTO\User\YearlyBalanceDTO;

/**
 * Pure arithmetic, no database access (fork).
 *
 * Leave years run from reset day to reset day. Index 0 is the current one (it ends on the
 * next reset day), index 1 the one after that, and so on. A booking belongs to the leave
 * year in which it STARTS.
 *
 * The stored pot already has every booking deducted, also those in future years. So the days free
 * in the current year are the pot plus the future bookings. Each following year has its own allowance
 * minus its own bookings. Unused days are NOT carried over: they are forfeited at the end of the year.
 */
final class YearlyBalanceCalculator
{
    public static function periodIndex(\DateTimeImmutable $resetDay, \DateTimeImmutable $date): int
    {
        if ($date < $resetDay) {
            return 0;
        }

        $index = 1;
        while ($date >= $resetDay->modify(sprintf('+%d year', $index))) {
            ++$index;
        }

        return $index;
    }

    /** The calendar year a leave year starts in. */
    public static function periodYear(\DateTimeImmutable $resetDay, int $periodIndex): int
    {
        $start = 0 === $periodIndex
            ? $resetDay->modify('-1 year')
            : $resetDay->modify(sprintf('+%d year', $periodIndex - 1));

        return (int) $start->format('Y');
    }

    /**
     * @param array<int, int>                                   $allowanceByYear overrides, keyed by the year a leave year starts in
     * @param list<array{start: \DateTimeImmutable, days: int}> $bookings        active bookings that start on or after the reset day
     */
    public function compute(
        int $pot,
        \DateTimeImmutable $resetDay,
        int $defaultAllowance,
        array $allowanceByYear,
        array $bookings,
        int $minHorizon = 1,
    ): YearlyBalanceDTO {
        $booked = [];
        $horizon = max(1, $minHorizon);
        foreach ($bookings as $booking) {
            $index = self::periodIndex($resetDay, $booking['start']);
            if ($index < 1) {
                continue;
            }
            $booked[$index] = ($booked[$index] ?? 0) + $booking['days'];
            $horizon = max($horizon, $index);
        }

        $current = $pot + array_sum($booked);
        $future = [];
        for ($i = 1; $i <= $horizon; ++$i) {
            $year = self::periodYear($resetDay, $i);
            $allowance = $allowanceByYear[$year] ?? $defaultAllowance;
            $future[] = ['year' => $year, 'allowance' => $allowance, 'booked' => $booked[$i] ?? 0, 'remaining' => $allowance - ($booked[$i] ?? 0)];
        }

        return new YearlyBalanceDTO(self::periodYear($resetDay, 0), $current, $future);
    }

    /**
     * Would a new booking of $days that starts in leave year $periodIndex still be covered?
     * It only has to fit in its own year: nothing is carried over.
     */
    public function canBook(YearlyBalanceDTO $balance, int $periodIndex, int $days): bool
    {
        return $balance->remainingAt($periodIndex) - $days >= 0;
    }
}
