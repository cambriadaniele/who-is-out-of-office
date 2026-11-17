<?php

declare(strict_types=1);

use App\Infrastructure\Doctrine\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/*
 * Fork: school holidays are shown as information bars in the calendar for everybody.
 */

it('lists school holidays in the calendar feed, only those in the shown range', function (): void {
    $client = static::createClient();
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $year = (int) date('Y') + 1;
    $name = 'Sommerferien '.Uuid::uuid4()->toString();
    $other = 'Winterferien '.Uuid::uuid4()->toString();
    $db = $em->getConnection();
    $db->insert('school_holiday', ['id' => Uuid::uuid4()->toString(), 'group_name' => 'Schulferien BFS', 'name' => $name, 'start_date' => "$year-07-19", 'end_date' => "$year-08-20", 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $db->insert('school_holiday', ['id' => Uuid::uuid4()->toString(), 'group_name' => 'Schulferien BFS', 'name' => $other, 'start_date' => "$year-12-24", 'end_date' => "$year-12-31", 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

    $client->loginUser($em->getRepository(User::class)->findOneBy(['email' => 'user@whoisooo.app']));
    $client->request('GET', '/fc-load-events', ['start' => "$year-07-01T00:00:00", 'end' => "$year-09-01T00:00:00"]);
    $titles = array_column(json_decode($client->getResponse()->getContent(), true), 'title');

    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and($titles)->toContain('🏫 '.$name)
        ->and($titles)->not->toContain('🏫 '.$other);

    $db->delete('school_holiday', ['name' => $name]);
    $db->delete('school_holiday', ['name' => $other]);
});

it('keeps school holidays for admins only to edit', function (): void {
    $client = static::createClient();
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $client->loginUser($em->getRepository(User::class)->findOneBy(['email' => 'user@whoisooo.app']));
    $client->request('GET', '/app/school-holidays');

    expect($client->getResponse()->getStatusCode())->toBeIn([302, 403, 404]);
});
