<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Service\DateLocalizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class LocalizedDateExtension extends AbstractExtension
{
    public function __construct(private readonly DateLocalizer $dateLocalizer)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ldate', $this->format(...)),
        ];
    }

    public function format(\DateTimeInterface|string|null $date, string $style = DateLocalizer::STYLE_MEDIUM): string
    {
        if (null === $date || '' === $date) {
            return '';
        }

        return $this->dateLocalizer->format(is_string($date) ? new \DateTimeImmutable($date) : $date, $style);
    }
}
