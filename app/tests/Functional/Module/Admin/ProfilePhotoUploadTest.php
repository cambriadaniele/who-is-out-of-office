<?php

declare(strict_types=1);

use App\Infrastructure\Doctrine\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/*
 * Regression: "Änderungen speichern" did nothing for users without a holiday calendar
 * (a hidden required field blocked the browser), and the photo then did not show.
 */

function uploadTestPng(): string
{
    $path = tempnam(sys_get_temp_dir(), 'png').'.png';
    imagepng(imagecreatetruecolor(60, 60), $path);

    return $path;
}

it('saves the profile with a photo when no holiday calendar is chosen', function (): void {
    $client = static::createClient();
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $user = $em->getRepository(User::class)->findOneBy(['email' => 'user@whoisooo.app']);
    $user->holidayCalendar = null;
    $user->profileImageUrl = null;
    $em->flush();
    $client->loginUser($user);

    $crawler = $client->request('GET', '/app/user/profile');
    expect($client->getResponse()->getStatusCode())->toBe(200);

    // The select must not be "required": the browser would refuse to submit.
    expect($crawler->filter('select[name="user_profile[holidayCalendar]"][required]')->count())->toBe(0);

    $form = $crawler->selectButton('Save Changes')->form();
    $form['user_profile[profileImageFile]']->upload(uploadTestPng());
    $client->submit($form);

    expect($client->getResponse()->isRedirect())->toBeTrue();

    $em->clear();
    $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'user@whoisooo.app']);
    expect($reloaded->profileImageUrl)->not->toBeNull()->toEndWith('.png');

    $stored = dirname(__DIR__, 4).'/public/uploads/profile_images/'.$reloaded->profileImageUrl;
    expect(is_file($stored))->toBeTrue();

    // The page that follows must point at the stored file.
    $client->followRedirect();
    expect($client->getResponse()->getContent())->toContain('uploads/profile_images/'.$reloaded->profileImageUrl);

    @unlink($stored);
    $reloaded->profileImageUrl = null;
    $em->flush();
});
