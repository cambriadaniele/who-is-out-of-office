<?php

declare(strict_types=1);

use App\Module\User\Service\YearlyBalanceCalculator;

/*
 * Fork: vacation days are counted in the year they are taken, nothing is carried over.
 * The reset day is the END of the current leave year, so with reset day 2027-01-01 the
 * current year is 2026 and the first following year is 2027.
 */

function ybcDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

function ybcBooking(string $start, int $days): array
{
    return ['start' => ybcDay($start), 'days' => $days];
}

it('puts a date into the right leave year around the new year', function (string $date, int $expected): void {
    expect(YearlyBalanceCalculator::periodIndex(ybcDay('2027-01-01'), ybcDay($date)))->toBe($expected);
})->with([
    'last day of the current year' => ['2026-12-31', 0],
    'first day of next year' => ['2027-01-01', 1],
    'last day of next year' => ['2027-12-31', 1],
    'first day of the year after' => ['2028-01-01', 2],
    'far away' => ['2030-06-15', 4],
    'in the past' => ['2020-03-01', 0],
]);

it('names the calendar year of each leave year', function (): void {
    $reset = ybcDay('2027-01-01');

    expect(YearlyBalanceCalculator::periodYear($reset, 0))->toBe(2026)
        ->and(YearlyBalanceCalculator::periodYear($reset, 1))->toBe(2027)
        ->and(YearlyBalanceCalculator::periodYear($reset, 2))->toBe(2028);
});

it('moves on by one year when the reset day moves on (1 January 2027 and 2028)', function (): void {
    // After the reset on 2027-01-01 the user's reset day is 2028-01-01.
    $reset = ybcDay('2028-01-01');

    expect(YearlyBalanceCalculator::periodYear($reset, 0))->toBe(2027)
        ->and(YearlyBalanceCalculator::periodYear($reset, 1))->toBe(2028)
        ->and(YearlyBalanceCalculator::periodIndex($reset, ybcDay('2027-05-05')))->toBe(0)
        ->and(YearlyBalanceCalculator::periodIndex($reset, ybcDay('2028-05-05')))->toBe(1);
});

it('shows the full allowance for next year when nothing is booked', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(0, ybcDay('2027-01-01'), 26, [], []);

    expect($balance->currentYear)->toBe(2026)
        ->and($balance->currentRemaining)->toBe(0)
        ->and($balance->next())->toBe(['year' => 2027, 'allowance' => 26, 'booked' => 0, 'remaining' => 26]);
});

it('does not let next year bookings eat into this year', function (): void {
    // Scherika: pot is -23 because 23 days of 2027 were booked, this year she has 0 left.
    $balance = (new YearlyBalanceCalculator())->compute(
        -23,
        ybcDay('2027-01-01'),
        26,
        [],
        [ybcBooking('2027-08-16', 5), ybcBooking('2027-09-03', 11), ybcBooking('2027-12-30', 2), ybcBooking('2027-05-18', 5)],
    );

    expect($balance->currentRemaining)->toBe(0)
        ->and($balance->next()['booked'])->toBe(23)
        ->and($balance->next()['remaining'])->toBe(3);
});

it('never carries unused days from this year into next year', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(12, ybcDay('2027-01-01'), 26, [], []);

    expect($balance->currentRemaining)->toBe(12)
        ->and($balance->next()['remaining'])->toBe(26);
});

it('uses a different allowance for a year that has an override', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(0, ybcDay('2027-01-01'), 26, [2027 => 30, 2028 => 20], [ybcBooking('2028-03-01', 4)], 2);

    expect($balance->future)->toHaveCount(2)
        ->and($balance->future[0])->toBe(['year' => 2027, 'allowance' => 30, 'booked' => 0, 'remaining' => 30])
        ->and($balance->future[1])->toBe(['year' => 2028, 'allowance' => 20, 'booked' => 4, 'remaining' => 16]);
});

it('extends the horizon when a booking lies further ahead', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(0, ybcDay('2027-01-01'), 26, [], [ybcBooking('2029-02-01', 3)]);

    expect($balance->future)->toHaveCount(3)
        ->and($balance->future[2]['year'])->toBe(2029)
        ->and($balance->future[2]['remaining'])->toBe(23)
        ->and($balance->future[1]['remaining'])->toBe(26);
});

it('shows a negative remainder when a year is overbooked', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(0, ybcDay('2027-01-01'), 10, [], [ybcBooking('2027-02-01', 12)]);

    expect($balance->next()['remaining'])->toBe(-2);
});

it('ignores bookings that start in the current year', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(5, ybcDay('2027-01-01'), 26, [], [ybcBooking('2026-12-30', 2)]);

    expect($balance->currentRemaining)->toBe(5)
        ->and($balance->next()['booked'])->toBe(0);
});

it('lets a booking fit only in its own year', function (): void {
    $calc = new YearlyBalanceCalculator();
    $balance = $calc->compute(-20, ybcDay('2027-01-01'), 26, [], [ybcBooking('2027-03-01', 20)], 2);

    expect($calc->canBook($balance, 1, 6))->toBeTrue()
        ->and($calc->canBook($balance, 1, 7))->toBeFalse()
        // this year has nothing left, even though 2027 still has days
        ->and($calc->canBook($balance, 0, 1))->toBeFalse()
        // 2028 is untouched
        ->and($calc->canBook($balance, 2, 26))->toBeTrue();
});

it('reports the days left at the end of any leave year', function (): void {
    $balance = (new YearlyBalanceCalculator())->compute(4, ybcDay('2027-01-01'), 26, [], [ybcBooking('2027-03-01', 6)], 2);

    expect($balance->remainingAt(0))->toBe(10)
        ->and($balance->remainingAt(1))->toBe(20)
        ->and($balance->remainingAt(2))->toBe(26);
});
