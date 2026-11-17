<?php

declare(strict_types=1);

use App\Infrastructure\Doctrine\Entity\BalanceLedgerEntry;
use App\Infrastructure\Doctrine\Entity\User;
use App\Module\User\Repository\BalanceLedgerEntryRepositoryInterface;
use App\Module\User\Repository\LeavePeriodRepositoryInterface;
use App\Module\User\Repository\UserBalanceWriterInterface;
use App\Module\User\Repository\UserRepositoryInterface;
use App\Module\User\UseCase\Command\OpenInitialLeavePeriodCommandHandler;
use App\Module\User\UseCase\Command\OpenLeavePeriodCommandHandler;
use App\Module\User\UseCase\Command\ResetAbsenceBalanceCommandHandler;
use App\Shared\Enum\BalanceLedgerEntryTypeEnum;
use App\Shared\Enum\LeaveRequestStatusEnum;
use App\Shared\Exception\InsufficientLeaveBalanceException;
use App\Shared\Facade\LeaveRequestFacadeInterface;
use App\Shared\Facade\UserFacadeInterface;
use App\Shared\Handler\LeaveRequest\Command\SaveLeaveRequestCommand;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/*
 * Fork: vacation days count in the year they are taken, with no carry-over.
 * The leave year ends on the user's reset day (1 January next year here), so "next year" is the
 * calendar year after the current one. Dates are derived from today so the tests keep working in 2027, 2028, ...
 */

beforeEach(function (): void {
    self::bootKernel();
    $this->em = self::getContainer()->get(EntityManagerInterface::class);
    $this->users = self::getContainer()->get(UserFacadeInterface::class);
    $this->requests = self::getContainer()->get(LeaveRequestFacadeInterface::class);
    $this->nextYear = (int) date('Y') + 1;
});

function yearlyUser(EntityManagerInterface $em, UserFacadeInterface $users, int $allowance, int $pot, ?DateTimeImmutable $resetDay = null, ?DateTimeImmutable $createdAt = null): string
{
    $user = new User(
        id: Uuid::uuid4(),
        firstName: 'Jahr',
        lastName: 'Test',
        email: sprintf('yearly-%s@example.com', Uuid::uuid4()->toString()),
        password: 'password',
        isActive: true,
        annualLeaveAllowance: $allowance,
        currentLeaveBalance: $pot,
        absenceBalanceResetDay: $resetDay ?? new DateTimeImmutable('first day of January next year midnight'),
    );
    if (null !== $createdAt) {
        $user->setCreatedAt($createdAt);
    }
    $em->persist($user);
    $em->flush();
    $users->openInitialLeavePeriod($user->id->toString());

    return $user->id->toString();
}

function bookWorkdays(UserFacadeInterface $users, LeaveRequestFacadeInterface $requests, string $userId, DateTimeImmutable $monday, int $days): void
{
    $type = null;
    foreach ($requests->getAllLeaveTypes() as $candidate) {
        if ($candidate->isAffectingBalance) {
            $type = $candidate;
            break;
        }
    }
    $requests->save(new SaveLeaveRequestCommand(
        userDTO: $users->getUser($userId),
        leaveRequestTypeDTO: $type,
        startDate: $monday,
        endDate: $monday->modify(sprintf('+%d weekdays', $days - 1)),
    ));
}

it('starts with the allowance for next year when nothing is booked', function (): void {
    $id = yearlyUser($this->em, $this->users, 26, 0);

    $balance = $this->users->getYearlyBalance($id);

    expect($balance->currentRemaining)->toBe(0)
        ->and($balance->currentYear)->toBe($this->nextYear - 1)
        ->and($balance->next()['year'])->toBe($this->nextYear)
        ->and($balance->next()['remaining'])->toBe(26);
});

it('counts a booking in next year against next year and leaves this year untouched', function (): void {
    $id = yearlyUser($this->em, $this->users, 26, 4);
    $monday = new DateTimeImmutable(sprintf('first monday of March %d', $this->nextYear));

    bookWorkdays($this->users, $this->requests, $id, $monday, 3);
    $balance = $this->users->getYearlyBalance($id);

    expect($balance->currentRemaining)->toBe(4)
        ->and($balance->next()['booked'])->toBe(3)
        ->and($balance->next()['remaining'])->toBe(23);
});

it('refuses a booking that exceeds the allowance of its own year', function (): void {
    $id = yearlyUser($this->em, $this->users, 2, 30);
    $monday = new DateTimeImmutable(sprintf('first monday of March %d', $this->nextYear));

    expect(fn () => bookWorkdays($this->users, $this->requests, $id, $monday, 3))
        ->toThrow(InsufficientLeaveBalanceException::class);
});

it('does not let this year\'s leftover pay for a booking next year', function (): void {
    $id = yearlyUser($this->em, $this->users, 1, 20);
    $monday = new DateTimeImmutable(sprintf('first monday of March %d', $this->nextYear));

    expect($this->users->canBookDays($id, $monday, 3))->toBeFalse()
        ->and($this->users->canBookDays($id, $monday, 1))->toBeTrue();
});

it('gives the days back when the booking is withdrawn', function (): void {
    $id = yearlyUser($this->em, $this->users, 26, 0);
    $monday = new DateTimeImmutable(sprintf('first monday of March %d', $this->nextYear));
    bookWorkdays($this->users, $this->requests, $id, $monday, 5);
    expect($this->users->getYearlyBalance($id)->next()['remaining'])->toBe(21);

    $requestId = $this->em->getConnection()->fetchOne('SELECT id FROM leave_request WHERE user_id = ?', [$id]);
    $dto = $this->requests->getById($requestId);
    $dto->status = LeaveRequestStatusEnum::Withdrawn;
    $this->requests->updateAndRestoreBalanceIfNeeded($dto);
    $this->em->clear();

    expect($this->users->getYearlyBalance($id)->next()['remaining'])->toBe(26);
});

it('adds a correction to next year in advance and shows it in the allowance', function (): void {
    $id = yearlyUser($this->em, $this->users, 26, 0);

    $this->users->addFutureYearCorrection($id, $this->nextYear, 4, 'Mehrstunden', null);
    $this->users->addFutureYearCorrection($id, $this->nextYear, -1, 'Korrektur', null);

    expect($this->users->getYearlyBalance($id)->next()['allowance'])->toBe(29)
        ->and($this->users->getYearlyBalance($id)->next()['remaining'])->toBe(29);
});

it('can set and reset an individual allowance for one year', function (): void {
    $id = yearlyUser($this->em, $this->users, 26, 0);

    $this->users->setYearAllowance($id, $this->nextYear, 31);
    expect($this->users->getYearlyBalance($id)->next()['allowance'])->toBe(31);

    $this->users->setYearAllowance($id, $this->nextYear, null);
    expect($this->users->getYearlyBalance($id)->next()['allowance'])->toBe(26);
});

it('forfeits unused days at year end instead of carrying them over', function (): void {
    $today = new DateTimeImmutable('today');
    $id = yearlyUser($this->em, $this->users, 24, 9, $today, $today->modify('-2 days'));

    self::getContainer()->get(ResetAbsenceBalanceCommandHandler::class)->handle();
    $this->em->clear();

    $user = $this->users->getUser($id);
    $accrual = $this->em->getRepository(BalanceLedgerEntry::class)->findOneBy(['user' => $id, 'type' => BalanceLedgerEntryTypeEnum::Accrual]);

    // 9 days were left, they are lost: the new year starts with exactly the allowance.
    expect($user->currentLeaveBalance)->toBe(24)
        ->and($accrual)->not->toBeNull()
        ->and($accrual->delta)->toBe(15)
        ->and($accrual->reason)->toContain('verfallen');
});

it('starts the new year at the allowance even when next year was already booked', function (): void {
    $today = new DateTimeImmutable('today');
    $id = yearlyUser($this->em, $this->users, 24, -5, $today, $today->modify('-2 days'));
    // 5 days booked in the year that starts after the reset day: they sit in the pot as -5.
    $this->em->getConnection()->executeStatement('UPDATE user SET current_leave_balance = -5 WHERE id = ?', [$id]);
    $this->em->getConnection()->executeStatement(
        'INSERT INTO leave_request (id, user_id, status, leave_type, work_days, start_date, end_date, created_at, updated_at, is_auto_approved)
         SELECT ?, ?, "approved", id, 5, ?, ?, NOW(), NOW(), 0 FROM leave_request_type WHERE is_affecting_balance = 1 LIMIT 1',
        [Uuid::uuid4()->toString(), $id, $today->modify('+30 days')->format('Y-m-d 00:00:00'), $today->modify('+34 days')->format('Y-m-d 00:00:00')],
    );

    self::getContainer()->get(ResetAbsenceBalanceCommandHandler::class)->handle();
    $this->em->clear();

    // 24 granted, 5 already used in the new year: 19 free. Nothing forfeited, nothing carried.
    expect($this->users->getUser($id)->currentLeaveBalance)->toBe(19);
});

it('opens a new account with 0 days when its first leave year is closed', function (): void {
    $user = new User(
        id: Uuid::uuid4(),
        firstName: 'Neu',
        lastName: 'Konto',
        email: sprintf('neu-%s@example.com', Uuid::uuid4()->toString()),
        password: 'password',
        isActive: true,
        annualLeaveAllowance: 26,
        currentLeaveBalance: 26,
        absenceBalanceResetDay: new DateTimeImmutable('2099-01-01'),
    );
    $this->em->persist($user);
    $this->em->flush();
    $container = self::getContainer();

    $handler = fn (string $zeroUntil) => new OpenInitialLeavePeriodCommandHandler(
        $container->get(UserRepositoryInterface::class),
        $container->get(LeavePeriodRepositoryInterface::class),
        $container->get(UserBalanceWriterInterface::class),
        $container->get(BalanceLedgerEntryRepositoryInterface::class),
        $container->get(OpenLeavePeriodCommandHandler::class),
        $zeroUntil,
    );

    $handler('2099-01-01')->handle($user->id->toString(), true);
    $this->em->clear();
    expect($this->users->getUser($user->id->toString())->currentLeaveBalance)->toBe(0);
});

it('gives a new account the full allowance when the switch is off', function (): void {
    $user = new User(
        id: Uuid::uuid4(),
        firstName: 'Neu',
        lastName: 'Konto',
        email: sprintf('neu-%s@example.com', Uuid::uuid4()->toString()),
        password: 'password',
        isActive: true,
        annualLeaveAllowance: 26,
        currentLeaveBalance: 0,
        absenceBalanceResetDay: new DateTimeImmutable('2099-01-01'),
    );
    $this->em->persist($user);
    $this->em->flush();
    $container = self::getContainer();

    (new OpenInitialLeavePeriodCommandHandler(
        $container->get(UserRepositoryInterface::class),
        $container->get(LeavePeriodRepositoryInterface::class),
        $container->get(UserBalanceWriterInterface::class),
        $container->get(BalanceLedgerEntryRepositoryInterface::class),
        $container->get(OpenLeavePeriodCommandHandler::class),
        '',
    ))->handle($user->id->toString(), true);
    $this->em->clear();

    expect($this->users->getUser($user->id->toString())->currentLeaveBalance)->toBe(26);
});
