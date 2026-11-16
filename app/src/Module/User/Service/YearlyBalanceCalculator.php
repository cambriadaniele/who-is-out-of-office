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
 * The stored pot already has every booking deducted, also those in future years. So the days
 * free in the current year are the pot plus the future bookings. Each following year then gets
 * its allowance on top, loses its own bookings, and passes what is left to the next year.
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
     * @param array<int, int>                                           $allowanceByYear overrides, keyed by the year a leave year starts in
     * @param list<array{start: \DateTimeImmutable, days: int}>         $bookings        active bookings that start on or after the reset day
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
        $carry = $current;
        for ($i = 1; $i <= $horizon; ++$i) {
            $year = self::periodYear($resetDay, $i);
            $allowance = $allowanceByYear[$year] ?? $defaultAllowance;
            $carry = $carry + $allowance - ($booked[$i] ?? 0);
            $future[] = ['year' => $year, 'allowance' => $allowance, 'booked' => $booked[$i] ?? 0, 'remaining' => $carry];
        }

        return new YearlyBalanceDTO(self::periodYear($resetDay, 0), $current, $future);
    }

    /**
     * Would a new booking of $days that starts in leave year $periodIndex still be covered?
     * It must fit in its own year and must not push any later year below zero.
     */
    public function canBook(YearlyBalanceDTO $balance, int $periodIndex, int $days): bool
    {
        for ($i = $periodIndex; $i <= count($balance->future); ++$i) {
            if ($balance->remainingAt($i) - $days < 0) {
                return false;
            }
        }

        return true;
    }
}
