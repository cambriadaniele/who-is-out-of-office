<?php

declare(strict_types=1);

use App\Infrastructure\Doctrine\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/*
 * Real-browser checks at phone width. These are the things that broke on real phones:
 *  - the calendar filter lists did not open (they were covered / cut off),
 *  - long e-mail addresses in the person cards ran into the next column.
 */

beforeEach(function (): void {
    static::bootKernel();
});

function phoneClient(): Symfony\Component\Panther\Client
{
    $client = createPantherClient();
    $client->manage()->window()->setSize(new Facebook\WebDriver\WebDriverDimension(375, 812));
    loginUserWithLoginForm($client, 'admin@whoisooo.app', '123');

    return $client;
}

it('opens the calendar filter list on a phone, inside the screen and with options', function (): void {
    $client = phoneClient();
    $client->request('GET', '/app/calendar');
    $client->waitFor('#cal-type-toggle');
    $client->executeScript("document.getElementById('cal-type-toggle').click();");
    $client->waitForVisibility('#cal-type-dropdown');

    $box = $client->executeScript(
        "const r = document.getElementById('cal-type-dropdown').getBoundingClientRect();"
        .'return {left: r.left, right: r.right, top: r.top, bottom: r.bottom, h: r.height, vw: window.innerWidth, vh: window.innerHeight, options: document.querySelectorAll("#cal-type-options .cal-multiselect-option").length, position: getComputedStyle(document.getElementById("cal-type-dropdown")).position, w: r.width, covered: (() => { const o = document.querySelector("#cal-type-options .cal-multiselect-option").getBoundingClientRect(); const hit = document.elementFromPoint(o.left + o.width / 2, o.top + o.height / 2); return !hit || !document.getElementById("cal-type-dropdown").contains(hit); })()};'
    );

    // On a phone the list is a bottom sheet over the full width, and nothing covers its options.
    expect($box['position'])->toBe('fixed')
        ->and($box['w'])->toBeGreaterThanOrEqual($box['vw'] * 0.9)
        ->and($box['covered'])->toBeFalse()
        ->and($box['options'])->toBeGreaterThan(1)
        ->and($box['h'])->toBeGreaterThan(80)
        ->and($box['left'])->toBeGreaterThanOrEqual(0)
        ->and($box['right'])->toBeLessThanOrEqual($box['vw'] + 1)
        ->and($box['top'])->toBeGreaterThanOrEqual(0)
        ->and($box['bottom'])->toBeLessThanOrEqual($box['vh'] + 1);
});

it('keeps a very long e-mail address inside its cell in the person list on a phone', function (): void {
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $user = $em->getRepository(User::class)->findOneBy(['email' => 'user@whoisooo.app']);
    $original = $user->email;
    $user->email = 'a.very.long.name.that.would.never.fit.on.a.phone.screen@kitazumfarbhof-example.ch';
    $em->flush();

    try {
        $client = phoneClient();
        $client->request('GET', '/app/dashboard/my-organization');
        $client->waitFor('table.datagrid');

        $overflowing = $client->executeScript(
            "const out = []; document.querySelectorAll('table.datagrid > tbody > tr > td').forEach(td => { if (td.scrollWidth > td.clientWidth + 1) out.push(td.className + ':' + td.scrollWidth + '>' + td.clientWidth); });"
            .'return {out: out, page: document.documentElement.scrollWidth, vw: window.innerWidth};'
        );

        expect($overflowing['out'])->toBe([])
            ->and($overflowing['page'])->toBeLessThanOrEqual($overflowing['vw'] + 1);
    } finally {
        $user->email = $original;
        $em->flush();
    }
});
