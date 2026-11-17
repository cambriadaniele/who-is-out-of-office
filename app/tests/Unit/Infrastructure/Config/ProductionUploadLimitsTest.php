<?php

declare(strict_types=1);

/*
 * Regression: the app allowed 15 MB photos, but production PHP still used its 2 MB default,
 * so the upload failed silently. The three limits have to agree, and production has to load the ini.
 */

function iniBytes(string $value): int
{
    $number = (int) $value;

    return match (strtoupper(substr(trim($value), -1))) {
        'G' => $number * 1024 ** 3,
        'M' => $number * 1024 ** 2,
        'K' => $number * 1024,
        default => $number,
    };
}

/** Files outside app/ (docker/) are only there when the whole repository is checked out. */
function repoFile(string $relative): string
{
    $path = dirname(__DIR__, 4).'/../'.$relative;
    if (!is_file($path)) {
        $path = dirname(__DIR__, 5).'/'.$relative;
    }
    if (!is_file($path)) {
        test()->markTestSkipped('Repository root not available: '.$relative);
    }

    return (string) file_get_contents($path);
}

it('lets production PHP accept the photo size the form allows', function (): void {
    $ini = parse_ini_string(repoFile('docker/php/conf/php.prod.ini'));
    $dto = (string) file_get_contents(dirname(__DIR__, 4).'/src/Module/Admin/DTO/UserProfileDTO.php');
    preg_match("/maxSize: '(\d+[KMG])'/", $dto, $match);

    expect($match)->not->toBeEmpty()
        ->and(iniBytes($ini['upload_max_filesize']))->toBeGreaterThanOrEqual(iniBytes($match[1]))
        ->and(iniBytes($ini['post_max_size']))->toBeGreaterThan(iniBytes($ini['upload_max_filesize']));
});

it('lets nginx pass a request that large', function (): void {
    $ini = parse_ini_string(repoFile('docker/php/conf/php.prod.ini'));
    preg_match('/client_max_body_size\s+(\d+[KMG]);/', repoFile('docker/nginx/tmpl.nginx.conf'), $match);

    expect($match)->not->toBeEmpty()
        ->and(iniBytes($match[1]))->toBeGreaterThanOrEqual(iniBytes($ini['post_max_size']));
});

it('copies the production ini into the production image', function (): void {
    expect(repoFile('docker/php/Dockerfile'))->toContain('docker/php/conf/php.prod.ini /usr/local/etc/php/conf.d/');
});

it('tells people the same photo limit the form enforces', function (): void {
    preg_match("/maxSize: '(\d+)M'/", (string) file_get_contents(dirname(__DIR__, 4).'/src/Module/Admin/DTO/UserProfileDTO.php'), $limit);

    foreach (['de', 'en'] as $locale) {
        $yaml = (string) file_get_contents(sprintf('%s/translations/admin.%s.yaml', dirname(__DIR__, 4), $locale));
        preg_match('/profile_image_help: .*?(\d+) MB/', $yaml, $hint);

        expect($hint[1] ?? null)->toBe($limit[1]);
    }
});
