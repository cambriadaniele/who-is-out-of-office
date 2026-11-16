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

    /** Base allowance of a leave year: the exception if there is one, otherwise $default. */
    public function baseAllowanceForYear(string $userId, int $year, int $default): int
    {
        $value = $this->connection->fetchOne(
            'SELECT days FROM user_leave_allowance WHERE user_id = :userId AND year = :year',
            ['userId' => $userId, 'year' => $year],
        );

        return false === $value ? $default : (int) $value;
    }

    /** What a leave year grants in total: base allowance plus the corrections made for that year. */
    public function allowanceForYear(string $userId, int $year, int $default): int
    {
        return $this->baseAllowanceForYear($userId, $year, $default) + ($this->adjustmentsByYear($userId)[$year] ?? 0);
    }

    /**
     * Total allowance per year for every year that differs from the default (exception or corrections).
     *
     * @return array<int, int>
     */
    public function effectiveAllowancesByYear(string $userId, int $default): array
    {
        $base = $this->allowancesByYear($userId);
        $adjustments = $this->adjustmentsByYear($userId);
        $result = [];
        foreach (array_unique([...array_keys($base), ...array_keys($adjustments)]) as $year) {
            $result[$year] = ($base[$year] ?? $default) + ($adjustments[$year] ?? 0);
        }

        return $result;
    }

    /** @return array<int, int> year => sum of corrections */
    public function adjustmentsByYear(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT year, SUM(delta) AS total FROM user_leave_year_adjustment WHERE user_id = :userId GROUP BY year',
            ['userId' => $userId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['year']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * @return list<array{year: int, delta: int, reason: string, actor: ?string, created_at: string}>
     */
    public function adjustmentEntries(string $userId): array
    {
        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'delta' => (int) $row['delta'],
                'reason' => (string) $row['reason'],
                'actor' => $row['actor'] ?? null,
                'created_at' => (string) $row['created_at'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT a.year, a.delta, a.reason, a.created_at, CONCAT(u.first_name, \' \', u.last_name) AS actor
                   FROM user_leave_year_adjustment a
                   LEFT JOIN user u ON u.id = a.actor_user_id
                  WHERE a.user_id = :userId
                  ORDER BY a.created_at DESC',
                ['userId' => $userId],
            ),
        );
    }

    public function addAdjustment(string $userId, int $year, int $delta, string $reason, ?string $actorUserId): void
    {
        $this->connection->insert('user_leave_year_adjustment', [
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'year' => $year,
            'delta' => $delta,
            'reason' => mb_substr($reason, 0, 255),
            'actor_user_id' => $actorUserId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** Set the base allowance of one leave year; null goes back to the person's annual allowance. */
    public function setAllowance(string $userId, int $year, ?int $days): void
    {
        if (null === $days) {
            $this->connection->executeStatement('DELETE FROM user_leave_allowance WHERE user_id = :userId AND year = :year', ['userId' => $userId, 'year' => $year]);

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO user_leave_allowance (user_id, year, days) VALUES (:userId, :year, :days) ON DUPLICATE KEY UPDATE days = VALUES(days)',
            ['userId' => $userId, 'year' => $year, 'days' => $days],
        );
    }

    /** Days of active bookings that start in [$from, $to). */
    public function bookedBetween(string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(r.work_days), 0)
               FROM leave_request r
               JOIN leave_request_type t ON t.id = r.leave_type
              WHERE r.user_id = :userId AND t.is_affecting_balance = 1
                AND r.status IN (\'pending\', \'approved\')
                AND r.start_date >= :from AND r.start_date < :to',
            ['userId' => $userId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );
    }

    /**
     * Manual corrections recorded in the open leave year (the ledger's "Saldo anpassen" entries).
     *
     * @return list<array{delta: int, reason: string, actor: ?string, created_at: string}>
     */
    public function currentYearCorrections(string $userId): array
    {
        return array_map(
            static fn (array $row): array => [
                'delta' => (int) $row['delta'],
                'reason' => (string) $row['reason'],
                'actor' => $row['actor'] ?? null,
                'created_at' => (string) $row['created_at'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT l.delta, l.reason, l.created_at, CONCAT(u.first_name, \' \', u.last_name) AS actor
                   FROM balance_ledger_entry l
                   JOIN leave_period p ON p.id = l.leave_period_id AND p.closed_at IS NULL
                   LEFT JOIN user u ON u.id = l.actor_user_id
                  WHERE l.user_id = :userId AND l.type = \'manual_adjustment\'
                  ORDER BY l.created_at DESC',
                ['userId' => $userId],
            ),
        );
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
