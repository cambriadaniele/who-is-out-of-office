<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\Connection;

/**
 * Plain SQL access for the per-year vacation view (fork).
 */
class YearlyBalanceDataRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Active (pending or approved) bookings that affect the balance and start on or after $from.
     *
     * @return list<array{start: \DateTimeImmutable, days: int}>
     */
    public function activeBookingsFrom(string $userId, \DateTimeImmutable $from): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.start_date, r.work_days
               FROM leave_request r
               JOIN leave_request_type t ON t.id = r.leave_type
              WHERE r.user_id = :userId
                AND t.is_affecting_balance = 1
                AND r.status IN (\'pending\', \'approved\')
                AND r.start_date >= :from',
            ['userId' => $userId, 'from' => $from->format('Y-m-d')],
        );

        return array_map(
            static fn (array $row): array => [
                'start' => new \DateTimeImmutable(substr((string) $row['start_date'], 0, 10)),
                'days' => (int) $row['work_days'],
            ],
            $rows,
        );
    }

    /**
     * Days that are forfeited when the leave year ends on $boundary: what is still free in the
     * ending year, which is the running balance plus the days already booked for later years.
     */
    public function forfeitedAt(string $userId, int $pot, \DateTimeImmutable $boundary): int
    {
        $booked = array_sum(array_column($this->activeBookingsFrom($userId, $boundary), 'days'));

        return max(0, $pot + (int) $booked);
    }

    /**
     * @return array<int, int> year a leave year starts in => allowance in days
     */
    public function allowancesByYear(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT year, days FROM user_leave_allowance WHERE user_id = :userId ORDER BY year',
            ['userId' => $userId],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['year']] = (int) $row['days'];
        }

        return $result;
    }

    public function allowanceForYear(string $userId, int $year, int $default): int
    {
        $value = $this->connection->fetchOne(
            'SELECT days FROM user_leave_allowance WHERE user_id = :userId AND year = :year',
            ['userId' => $userId, 'year' => $year],
        );

        return false === $value ? $default : (int) $value;
    }

    /**
     * @param array<int, int> $byYear
     */
    public function replaceAllowances(string $userId, array $byYear): void
    {
        $this->connection->transactional(function (Connection $connection) use ($userId, $byYear): void {
            $connection->executeStatement('DELETE FROM user_leave_allowance WHERE user_id = :userId', ['userId' => $userId]);
            foreach ($byYear as $year => $days) {
                $connection->executeStatement(
                    'INSERT INTO user_leave_allowance (user_id, year, days) VALUES (:userId, :year, :days)',
                    ['userId' => $userId, 'year' => $year, 'days' => $days],
                );
            }
        });
    }
}
