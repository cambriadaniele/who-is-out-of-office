<?php

declare(strict_types=1);

use App\Infrastructure\Doctrine\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/*
 * Fork: the old "Saldo anpassen" form was replaced by "Ferientage verwalten".
 * The old address stays valid (bookmarks, links) and leads to the new page.
 * Adjusting days itself is covered by UserVacationDaysControllerTest.
 */

it('sends the old adjust-balance address to the new vacation days page', function (): void {
    $client = static::createClient();
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@whoisooo.app']);
    $target = $em->getRepository(User::class)->findOneBy(['email' => 'manager@whoisooo.app']);
    $client->loginUser($admin);

    $client->request('GET', sprintf('/app/my-organization/%s/adjust-balance', $target->id->toString()));

    expect($client->getResponse()->isRedirect(sprintf('/app/my-organization/%s/vacation-days', $target->id->toString())))->toBeTrue();
});
