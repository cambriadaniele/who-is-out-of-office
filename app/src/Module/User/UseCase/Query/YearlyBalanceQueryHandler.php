<?php

declare(strict_types=1);

namespace App\Module\User\UseCase\Query;

use App\Infrastructure\Doctrine\Repository\YearlyBalanceDataRepository;
use App\Module\User\Repository\UserRepositoryInterface;
use App\Module\User\Service\YearlyBalanceCalculator;
use App\Shared\DTO\User\YearlyBalanceDTO;

/**
 * Vacation days split by the year they are taken in (fork).
 */
class YearlyBalanceQueryHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly YearlyBalanceDataRepository $data,
        private readonly YearlyBalanceCalculator $calculator,
    ) {
    }

    public function balance(string $userId, int $minHorizon = 1): YearlyBalanceDTO
    {
        $user = $this->userRepository->findOneById($userId);
        if (null === $user) {
            throw new \DomainException(sprintf('User "%s" not found.', $userId));
        }

        return $this->calculator->compute(
            $user->currentLeaveBalance,
            $user->absenceBalanceResetDay,
            $user->annualLeaveAllowance,
            $this->data->effectiveAllowancesByYear($userId, $user->annualLeaveAllowance),
            $this->data->activeBookingsFrom($userId, $user->absenceBalanceResetDay),
            $minHorizon,
        );
    }

    /**
     * Everything the "Ferientage verwalten" page shows: the current leave year and the next $futureYears.
     *
     * @return list<array{year: int, isCurrent: bool, allowance: int, baseAllowance: int, isException: bool, corrections: int, booked: int, free: int, entries: list<array{delta: int, reason: string, actor: ?string, created_at: string}>}>
     */
    public function overview(string $userId, int $futureYears = 2): array
    {
        $user = $this->userRepository->findOneById($userId);
        if (null === $user) {
            throw new \DomainException(sprintf('User "%s" not found.', $userId));
        }

        $balance = $this->balance($userId, $futureYears);
        $resetDay = $user->absenceBalanceResetDay;

        // Current leave year: free days come from the ledger; the allowance is shown as free + booked - corrections.
        // Corrections booked during the year (ledger) plus those entered in advance for this year
        // (they were added by the 1 January reset together with the allowance).
        $advance = array_values(array_filter(
            $this->data->adjustmentEntries($userId),
            static fn (array $e): bool => $e['year'] === $balance->currentYear,
        ));
        $currentEntries = [...$this->data->currentYearCorrections($userId), ...array_map(
            static fn (array $e): array => ['delta' => $e['delta'], 'reason' => $e['reason'], 'actor' => $e['actor'], 'created_at' => $e['created_at']],
            $advance,
        )];
        usort($currentEntries, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        $currentCorrections = array_sum(array_column($currentEntries, 'delta'));
        $currentBooked = $this->data->bookedBetween($userId, $resetDay->modify('-1 year'), $resetDay);
        $cards = [[
            'year' => $balance->currentYear,
            'isCurrent' => true,
            'allowance' => $balance->currentRemaining + $currentBooked - $currentCorrections,
            'baseAllowance' => $balance->currentRemaining + $currentBooked - $currentCorrections,
            'isException' => false,
            'corrections' => $currentCorrections,
            'booked' => $currentBooked,
            'free' => $balance->currentRemaining,
            'entries' => $currentEntries,
        ]];

        $adjustments = $this->data->adjustmentsByYear($userId);
        $entries = $this->data->adjustmentEntries($userId);
        $exceptions = $this->data->allowancesByYear($userId);
        foreach (array_slice($balance->future, 0, $futureYears) as $row) {
            $year = $row['year'];
            $corrections = $adjustments[$year] ?? 0;
            $cards[] = [
                'year' => $year,
                'isCurrent' => false,
                'allowance' => $row['allowance'] - $corrections,
                'baseAllowance' => $row['allowance'] - $corrections,
                'isException' => isset($exceptions[$year]),
                'corrections' => $corrections,
                'booked' => $row['booked'],
                'free' => $row['remaining'],
                'entries' => array_values(array_filter($entries, static fn (array $e): bool => $e['year'] === $year)),
            ];
        }

        return $cards;
    }

    /**
     * Would a booking of $days starting on $start still be covered, now and in the later years?
     */
    public function canBook(string $userId, \DateTimeImmutable $start, int $days): bool
    {
        $user = $this->userRepository->findOneById($userId);
        if (null === $user) {
            return false;
        }

        $index = YearlyBalanceCalculator::periodIndex($user->absenceBalanceResetDay, $start);
        $balance = $this->balance($userId, $index);

        return $this->calculator->canBook($balance, $index, $days);
    }

    /**
     * Days still free at the end of the leave year that contains $date.
     */
    public function remainingFor(string $userId, \DateTimeImmutable $date): int
    {
        $user = $this->userRepository->findOneById($userId);
        if (null === $user) {
            return 0;
        }

        $index = YearlyBalanceCalculator::periodIndex($user->absenceBalanceResetDay, $date);

        return $this->balance($userId, $index)->remainingAt($index);
    }
}
