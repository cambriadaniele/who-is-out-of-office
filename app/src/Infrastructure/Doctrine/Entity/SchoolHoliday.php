<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Entity;

use App\Infrastructure\Traits\TimestampableTrait;
use Ramsey\Uuid\UuidInterface;

/**
 * Fork: school holidays shown in the team calendar (information only, they do not change workdays).
 */
class SchoolHoliday
{
    use TimestampableTrait;

    public function __construct(
        public UuidInterface $id,
        public string $groupName,
        public string $name,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
    ) {
        $this->initializeTimestamps();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
