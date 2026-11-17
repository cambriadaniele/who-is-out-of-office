<?php

declare(strict_types=1);

use App\Shared\Service\DateLocalizer;
use Symfony\Contracts\Translation\LocaleAwareInterface;

function localizerFor(string $locale): DateLocalizer
{
    return new DateLocalizer(new class($locale) implements LocaleAwareInterface {
        public function __construct(private string $locale)
        {
        }

        public function getLocale(): string
        {
            return $this->locale;
        }

        public function setLocale(string $locale): void
        {
            $this->locale = $locale;
        }
    });
}

/*
 * Regression: a date that carries only an offset ("+00:00", e.g. parsed from an ATOM string) crashed the
 * pending-request list with "IntlDateFormatter: No such time zone".
 */
it('formats a date that only has a UTC offset instead of a time zone name', function (): void {
    $date = new DateTimeImmutable('2027-03-01T10:00:00+00:00');

    expect(localizerFor('de')->format($date, DateLocalizer::STYLE_DATETIME))->toContain('2027')->not->toBe('');
});

it('formats a named time zone and a +02:00 offset', function (): void {
    expect(localizerFor('en')->format(new DateTimeImmutable('2027-03-01 10:00', new DateTimeZone('Europe/Zurich')), DateLocalizer::STYLE_MEDIUM))->toContain('2027')
        ->and(localizerFor('en')->format(new DateTimeImmutable('2027-03-01T10:00:00+02:00'), DateLocalizer::STYLE_MEDIUM))->toContain('2027');
});

it('speaks German when the locale is German', function (): void {
    expect(localizerFor('de')->format(new DateTimeImmutable('2027-03-15'), DateLocalizer::STYLE_LONG))->toContain('März');
});
