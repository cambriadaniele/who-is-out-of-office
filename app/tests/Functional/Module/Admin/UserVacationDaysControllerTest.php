<?php

declare(strict_types=1);

use App\Infrastructure\Doctrine\Entity\User;
use App\Shared\Facade\UserFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;

/*
 * "Ferientage verwalten": admin only, CSRF protected, current year via ledger, future years in advance.
 */

beforeEach(function (): void {
    $this->client = static::createClient();
    $this->em = self::getContainer()->get(EntityManagerInterface::class);
    $this->users = self::getContainer()->get(UserFacadeInterface::class);
    $repo = $this->em->getRepository(User::class);
    $this->admin = $repo->findOneBy(['email' => 'admin@whoisooo.app']);
    $this->plain = $repo->findOneBy(['email' => 'user@whoisooo.app']);
    $this->target = $repo->findOneBy(['email' => 'manager@whoisooo.app']);
    $this->users->openInitialLeavePeriod($this->target->id->toString());
    $this->url = '/app/my-organization/'.$this->target->id->toString().'/vacation-days';
});

it('is closed to normal users', function (): void {
    $this->client->loginUser($this->plain);
    $this->client->request('GET', $this->url);

    expect($this->client->getResponse()->getStatusCode())->toBe(403);
});

it('shows this year and the next two years to an admin', function (): void {
    $this->client->loginUser($this->admin);
    $crawler = $this->client->request('GET', $this->url);

    expect($this->client->getResponse()->getStatusCode())->toBe(200)
        ->and($crawler->filter('.year-balance-card')->count())->toBeGreaterThanOrEqual(3);
});

it('refuses a correction without a valid token', function (): void {
    $this->client->loginUser($this->admin);
    $year = (int) date('Y') + 1;
    $this->client->request('POST', sprintf('%s/%d/correct', $this->url, $year), ['days' => 2, 'direction' => 'add', 'reason' => 'x', '_token' => 'wrong']);

    expect($this->client->getResponse()->getStatusCode())->toBe(403);
});

it('adds days to next year and shows them', function (): void {
    $this->client->loginUser($this->admin);
    $year = (int) date('Y') + 1;
    $crawler = $this->client->request('GET', $this->url);
    $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
    $before = $this->users->getYearlyBalance($this->target->id->toString())->next()['allowance'];

    $this->client->request('POST', sprintf('%s/%d/correct', $this->url, $year), ['days' => 3, 'direction' => 'add', 'reason' => 'Test', '_token' => $token]);
    expect($this->client->getResponse()->isRedirect())->toBeTrue();

    $after = $this->users->getYearlyBalance($this->target->id->toString())->next()['allowance'];
    expect($after)->toBe($before + 3);
});

it('adds days to the running year through the ledger', function (): void {
    $this->client->loginUser($this->admin);
    $crawler = $this->client->request('GET', $this->url);
    $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
    $id = $this->target->id->toString();
    $before = $this->users->getYearlyBalance($id)->currentRemaining;

    $this->client->request('POST', sprintf('%s/%d/correct', $this->url, (int) date('Y')), ['days' => 2, 'direction' => 'add', 'reason' => 'Test', '_token' => $token]);

    $this->em->clear();
    expect($this->users->getYearlyBalance($id)->currentRemaining)->toBe($before + 2);
});

it('rejects a correction without a reason', function (): void {
    $this->client->loginUser($this->admin);
    $crawler = $this->client->request('GET', $this->url);
    $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
    $id = $this->target->id->toString();
    $before = $this->users->getYearlyBalance($id)->next()['allowance'];

    $this->client->request('POST', sprintf('%s/%d/correct', $this->url, (int) date('Y') + 1), ['days' => 2, 'direction' => 'add', 'reason' => '  ', '_token' => $token]);

    expect($this->users->getYearlyBalance($id)->next()['allowance'])->toBe($before);
});

it('sets and resets the allowance of a future year', function (): void {
    $this->client->loginUser($this->admin);
    $year = (int) date('Y') + 1;
    $crawler = $this->client->request('GET', $this->url);
    $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
    $id = $this->target->id->toString();

    $card = fn () => array_values(array_filter($this->users->getVacationOverview($id), fn ($c) => $c['year'] === $year))[0];

    $this->client->request('POST', sprintf('%s/%d/allowance', $this->url, $year), ['days' => 33, '_token' => $token]);
    expect($card()['baseAllowance'])->toBe(33)->and($card()['isException'])->toBeTrue();

    $this->client->request('POST', sprintf('%s/%d/allowance', $this->url, $year), ['action' => 'reset', '_token' => $token]);
    expect($card()['isException'])->toBeFalse()->and($card()['baseAllowance'])->not->toBe(33);
});

it('does not offer an allowance change for the running year', function (): void {
    $this->client->loginUser($this->admin);
    $crawler = $this->client->request('GET', $this->url);
    $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

    $this->client->request('POST', sprintf('%s/%d/allowance', $this->url, (int) date('Y')), ['days' => 40, '_token' => $token]);

    expect($this->client->getResponse()->getStatusCode())->toBe(404);
});
