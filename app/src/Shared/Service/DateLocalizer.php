<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Contracts\Translation\LocaleAwareInterface;

class DateLocalizer
{
    public const string STYLE_SHORT = 'short';
    public const string STYLE_MEDIUM = 'medium';
    public const string STYLE_LONG = 'long';
    public const string STYLE_DATETIME = 'datetime';
    public const string STYLE_WEEKDAY = 'weekday';
    public const string STYLE_MONTH = 'month';

    private const array SKELETONS = [
        self::STYLE_SHORT => 'MMMd',
        self::STYLE_MEDIUM => 'yMMMd',
        self::STYLE_LONG => 'MMMMd',
        self::STYLE_DATETIME => 'yMMMdHm',
        self::STYLE_WEEKDAY => 'EEE',
        self::STYLE_MONTH => 'MMM',
    ];

    /** @var array<string, \IntlDateFormatter> */
    private array $formatters = [];

    public function __construct(private readonly LocaleAwareInterface $translator)
    {
    }

    public function format(\DateTimeInterface $date, string $style = self::STYLE_MEDIUM, ?string $locale = null): string
    {
        $locale ??= $this->translator->getLocale();
        $timezone = $date->getTimezone()->getName();
        // A date with a fixed offset ("+00:00", as in ATOM strings) has no zone name; ICU wants "GMT+00:00".
        if (preg_match('/^[+-]\d{2}:?\d{2}$/', $timezone)) {
            $timezone = 'GMT'.$timezone;
        }
        $key = sprintf('%s|%s|%s', $locale, $style, $timezone);

        $this->formatters[$key] ??= new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $timezone,
            \IntlDateFormatter::GREGORIAN,
            new \IntlDatePatternGenerator($locale)->getBestPattern(self::SKELETONS[$style] ?? self::SKELETONS[self::STYLE_MEDIUM]) ?: 'yyyy-MM-dd',
        );

        return $this->formatters[$key]->format($date) ?: $date->format('Y-m-d');
    }
}
