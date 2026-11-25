<?php
declare(strict_types=1);

namespace Erp\Modules\Settings;

use Erp\Core\Config;
use InvalidArgumentException;
use RuntimeException;

final class CompanyLogoStorage
{
    private const MAX_BYTES = 3_145_728;
    private const ALLOWED_MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed>|null $upload */
    public function store(?array $upload): string
    {
        if ($upload === null || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
            throw new InvalidArgumentException('Choose a company logo image first.');
        }
        if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The company logo upload did not complete.');
        }
        $size = (int)$upload['size'];
        $temporaryPath = (string)$upload['tmp_name'];
        if ($size < 1 || $size > self::MAX_BYTES || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('Logo must be a valid uploaded image no larger than 3 MB.');
        }
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file($temporaryPath);
        $image = @getimagesize($temporaryPath);
        if (!is_string($mime) || !isset(self::ALLOWED_MIME[$mime]) || !is_array($image) || ($image['mime'] ?? null) !== $mime) {
            throw new InvalidArgumentException('Logo must be a PNG, JPEG, or WebP image.');
        }
        if ((int)$image[0] > 4096 || (int)$image[1] > 4096 || (int)$image[0] * (int)$image[1] > 16_000_000) {
            throw new InvalidArgumentException('Logo dimensions cannot exceed 4096 by 4096 pixels or 16 megapixels.');
        }

        $directory = $this->config->storagePath() . DIRECTORY_SEPARATOR . 'company-logos';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Company logo storage could not be prepared.');
        }
        $baseName = bin2hex(random_bytes(24)) . '.' . self::ALLOWED_MIME[$mime];
        $destination = $directory . DIRECTORY_SEPARATOR . $baseName;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('Company logo could not be saved.');
        }
        @chmod($destination, 0640);
        return 'company-logos/' . $baseName;
    }

    public function read(string $relativePath): ?array
    {
        if (preg_match('#^company-logos/([a-f0-9]{48})\.(png|jpg|webp)$#', $relativePath, $matches) !== 1) {
            return null;
        }
        $path = $this->config->storagePath() . DIRECTORY_SEPARATOR . 'company-logos' . DIRECTORY_SEPARATOR . $matches[1] . '.' . $matches[2];
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $mime = match ($matches[2]) { 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp' };
        $body = file_get_contents($path);
        return is_string($body) ? ['body' => $body, 'mime' => $mime] : null;
    }

    public function delete(?string $relativePath): void
    {
        if ($relativePath === null || preg_match('#^company-logos/([a-f0-9]{48})\.(png|jpg|webp)$#', $relativePath, $matches) !== 1) {
            return;
        }
        $path = $this->config->storagePath() . DIRECTORY_SEPARATOR . 'company-logos' . DIRECTORY_SEPARATOR . $matches[1] . '.' . $matches[2];
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
