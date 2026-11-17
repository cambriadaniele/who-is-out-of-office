<?php

declare(strict_types=1);

use App\Module\Admin\DTO\UserProfileDTO;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

/*
 * Regression: phone photos (2-8 MB) were rejected by a 2 MB limit.
 */

function profilePng(int $padToBytes = 0): string
{
    $path = tempnam(sys_get_temp_dir(), 'png');
    $image = imagecreatetruecolor(40, 40);
    imagepng($image, $path);
    if ($padToBytes > 0) {
        // Trailing bytes after the PNG end chunk keep it a valid image but make it big.
        file_put_contents($path, str_repeat("\0", $padToBytes), FILE_APPEND);
    }

    return $path;
}

function validateProfile(UserProfileDTO $dto): int
{
    $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

    return count($validator->validateProperty($dto, 'profileImageFile'));
}

function profileDto(?UploadedFile $file): UserProfileDTO
{
    return new UserProfileDTO(firstName: 'A', lastName: 'B', workingDays: [1], profileImageFile: $file);
}

it('accepts a missing photo', function (): void {
    expect(validateProfile(profileDto(null)))->toBe(0);
});

it('accepts a normal phone photo of 8 MB', function (): void {
    $path = profilePng(8 * 1024 * 1024);

    expect(validateProfile(profileDto(new UploadedFile($path, 'photo.png', 'image/png', null, true))))->toBe(0);
});

it('rejects a photo over 15 MB', function (): void {
    $path = profilePng(16 * 1024 * 1024);

    expect(validateProfile(profileDto(new UploadedFile($path, 'photo.png', 'image/png', null, true))))->toBeGreaterThan(0);
});

it('rejects a file that is not an image', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, 'not an image');

    expect(validateProfile(profileDto(new UploadedFile($path, 'photo.png', 'image/png', null, true))))->toBeGreaterThan(0);
});
