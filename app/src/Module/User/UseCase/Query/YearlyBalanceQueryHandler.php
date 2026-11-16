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
            $this->data->allowancesByYear($userId),
            $this->data->activeBookingsFrom($userId, $user->absenceBalanceResetDay),
            $minHorizon,
        );
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
