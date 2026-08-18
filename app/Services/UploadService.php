<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Secure image uploads (business logos, site logo).
 * Files are stored outside the web root and streamed back through a controller.
 */
final class UploadService
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file
     * @return array{success:bool, filename:string, error:string|null}
     */
    public function storeLogo(array $file, string $directory = 'logos'): array
    {
        $maxBytes = (int) Config::get('security.upload_max_bytes', 2097152);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'filename' => '', 'error' => $this->uploadError((int) ($file['error'] ?? 0))];
        }

        if (($file['size'] ?? 0) <= 0) {
            return ['success' => false, 'filename' => '', 'error' => 'The uploaded file is empty.'];
        }

        if ((int) $file['size'] > $maxBytes) {
            return [
                'success' => false,
                'filename' => '',
                'error' => sprintf('The image is too large. Maximum size is %s.', $this->humanBytes($maxBytes)),
            ];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || (!is_uploaded_file($tmp) && !defined('DOCUPILOT_TESTING'))) {
            return ['success' => false, 'filename' => '', 'error' => 'Invalid upload.'];
        }

        // Trust the real content, not the browser-supplied MIME type or extension.
        $mime = (string) (@mime_content_type($tmp) ?: '');

        if (!array_key_exists($mime, self::ALLOWED)) {
            return [
                'success' => false,
                'filename' => '',
                'error' => 'Only JPG, JPEG, PNG and WEBP images are allowed.',
            ];
        }

        $dimensions = @getimagesize($tmp);

        if ($dimensions === false) {
            return ['success' => false, 'filename' => '', 'error' => 'That file is not a valid image.'];
        }

        $target = storage_path('uploads/' . trim($directory, '/'));

        if (!is_dir($target) && !@mkdir($target, 0775, true)) {
            return ['success' => false, 'filename' => '', 'error' => 'Upload folder could not be created. Check permissions on /storage/uploads.'];
        }

        if (!is_writable($target)) {
            return ['success' => false, 'filename' => '', 'error' => '/storage/uploads is not writable. Set permissions to 755 or 775.'];
        }

        // Always generate our own safe filename — never reuse the client name.
        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $destination = $target . '/' . $filename;

        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $destination) : @copy($tmp, $destination);

        if (!$moved) {
            return ['success' => false, 'filename' => '', 'error' => 'The image could not be saved. Please try again.'];
        }

        @chmod($destination, 0644);

        return ['success' => true, 'filename' => $filename, 'error' => null];
    }

    public function delete(string $filename, string $directory = 'logos'): void
    {
        if ($filename === '') {
            return;
        }

        $path = storage_path('uploads/' . trim($directory, '/') . '/' . basename($filename));

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function path(string $filename, string $directory = 'logos'): ?string
    {
        if ($filename === '') {
            return null;
        }

        $path = storage_path('uploads/' . trim($directory, '/') . '/' . basename($filename));

        return is_file($path) ? $path : null;
    }

    public function mimeFor(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is larger than the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose an image to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload. Contact your host.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by a PHP extension.',
            default => 'The image could not be uploaded.',
        };
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}
