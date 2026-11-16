<?php

declare(strict_types=1);

namespace App\Infrastructure\Ical\Service;

use App\Shared\DTO\Holiday\PublicHolidayDTO;
use App\Shared\DTO\LeaveRequest\LeaveRequestDTO;
use App\Shared\Service\DateLocalizer;
use App\Shared\Service\Messaging\EmojisProvider;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\MultiDay;
use Eluceo\iCal\Domain\ValueObject\SingleDay;
use Eluceo\iCal\Presentation\Component;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

class IcalCalendarBuilder
{
    public function __construct(
        private readonly DateLocalizer $dateLocalizer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<LeaveRequestDTO>  $leaveRequests
     * @param list<PublicHolidayDTO> $holidays
     */
    public function build(array $leaveRequests, array $holidays): Component
    {
        $events = [
            ...$this->leaveRequestEvents($leaveRequests),
            ...$this->holidayEvents($holidays),
        ];

        return new CalendarFactory()->createCalendar(new Calendar($events));
    }

    /**
     * @param list<LeaveRequestDTO> $leaveRequests
     *
     * @return list<Event>
     */
    private function leaveRequestEvents(array $leaveRequests): array
    {
        $events = [];
        foreach ($leaveRequests as $leaveRequestDTO) {
            $summary = sprintf(
                '%s %s %s',
                $leaveRequestDTO->leaveType->icon,
                $leaveRequestDTO->user->firstName,
                $leaveRequestDTO->user->lastName
            );

            $description = sprintf(
                '%s (%s - %s) %s',
                $leaveRequestDTO->leaveType->name,
                $this->dateLocalizer->format($leaveRequestDTO->startDate, DateLocalizer::STYLE_LONG),
                $this->dateLocalizer->format($leaveRequestDTO->endDate, DateLocalizer::STYLE_LONG),
                $leaveRequestDTO->comment
            );

            $events[] = new Event()
                ->setSummary($summary)
                ->setDescription($description)
                ->setOccurrence(
                    new MultiDay(
                        new Date($leaveRequestDTO->startDate),
                        new Date($leaveRequestDTO->endDate),
                    )
                );
        }

        return $events;
    }

    /**
     * @param list<PublicHolidayDTO> $holidays
     *
     * @return list<Event>
     */
    private function holidayEvents(array $holidays): array
    {
        $events = [];
        foreach ($holidays as $holiday) {
            $summary = sprintf('%s %s', EmojisProvider::getFlagEmojiCode($holiday->countryCode), $holiday->description);
            $description = $this->translator->trans('ical.public_holiday', ['%name%' => $holiday->description]);

            $events[] = new Event()
                ->setSummary($summary)
                ->setDescription($description)
                ->setOccurrence(new SingleDay(new Date($holiday->date)));
        }

        return $events;
    }
}
