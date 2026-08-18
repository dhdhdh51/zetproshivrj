<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;
use RuntimeException;

/**
 * Field photograph storage.
 *
 * Photographs are evidence, so this service:
 *   • accepts only real images (verified by decoding, not by extension),
 *   • re-encodes every upload, which strips EXIF (including any embedded GPS
 *     that might contradict the recorded point) and neutralises polyglot files,
 *   • burns a watermark with the supervisor name, timestamp, coordinates and
 *     address into the pixels so a screenshot cannot be passed off as a visit,
 *   • stores a SHA-256 so duplicate submissions can be detected,
 *   • writes outside the web root; files are only served through an authorised
 *     controller.
 */
final class Photos
{
    private const MAX_WIDTH = 1600;
    private const JPEG_QUALITY = 82;

    /* ------------------------------------------------------------------ */
    /* Public entry points                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Attach a photo to a customer visit (TYPE A).
     *
     * @param array{tmp_name:string, name?:string, size?:int, error?:int}|string $source
     *        An uploaded file array, or a base64 payload from the Android app.
     * @param array<string, mixed> $meta photo_type, latitude, longitude, accuracy,
     *        address, captured_at, caption
     */
    public function storeForVisit(int $visitId, mixed $source, array $meta = []): array
    {
        $visit = Database::selectOne(
            'SELECT v.*, u.name AS supervisor_name, s.bc_code
               FROM visits v
               JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
              WHERE v.id = :id',
            ['id' => $visitId]
        );

        if ($visit === null) {
            throw new HttpException(404, 'Visit not found.');
        }

        $types = array_keys(photo_types());
        $types[] = 'selfie';
        $photoType = in_array((string) ($meta['photo_type'] ?? ''), $types, true)
            ? (string) $meta['photo_type']
            : 'other';

        $stored = $this->process($source, 'visits/' . date('Y-m', strtotime((string) $visit['visit_date'])) . '/visit-' . $visitId, [
            'line1' => sprintf('%s (%s)', $visit['supervisor_name'], $visit['bc_code']),
            'line2' => $meta,
            'label' => photo_types()[$photoType] ?? 'Photo',
        ]);

        $existing = Database::selectOne(
            'SELECT id FROM visit_photos WHERE visit_id = :v AND sha256 = :h',
            ['v' => $visitId, 'h' => $stored['sha256']]
        );

        if ($existing !== null) {
            // Idempotent: a retried offline upload must not create a second row.
            @unlink($stored['absolute_path']);

            return ['id' => (int) $existing['id'], 'duplicate' => true];
        }

        $id = Database::insert('visit_photos', [
            'visit_id' => $visitId,
            'photo_type' => $photoType,
            'file_path' => $stored['relative_path'],
            'file_name' => $stored['file_name'],
            'mime_type' => 'image/jpeg',
            'file_size' => $stored['file_size'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'sha256' => $stored['sha256'],
            'latitude' => $this->coordinate($meta['latitude'] ?? null),
            'longitude' => $this->coordinate($meta['longitude'] ?? null),
            'accuracy' => isset($meta['accuracy']) && $meta['accuracy'] !== '' ? (float) $meta['accuracy'] : null,
            'address' => isset($meta['address']) ? mb_substr((string) $meta['address'], 0, 255) : null,
            'captured_at' => Gps::normaliseTimestamp($meta['captured_at'] ?? null) ?? now(),
            'watermarked' => $stored['watermarked'] ? 1 : 0,
            'caption' => isset($meta['caption']) ? mb_substr((string) $meta['caption'], 0, 255) : null,
            'uploaded_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Database::update(
            'visits',
            ['photo_count' => (int) Database::scalar('SELECT COUNT(*) FROM visit_photos WHERE visit_id = :v', ['v' => $visitId])],
            'id = :id',
            ['id' => $visitId]
        );

        return ['id' => $id, 'duplicate' => false];
    }

    /**
     * Attach a photo to a BC Supervisor inspection (TYPE B).
     */
    public function storeForInspection(int $inspectionId, mixed $source, array $meta = []): array
    {
        $inspection = Database::selectOne(
            'SELECT i.*, a.name AS inspector_name
               FROM inspections i
               JOIN users a ON a.id = i.admin_user_id
              WHERE i.id = :id',
            ['id' => $inspectionId]
        );

        if ($inspection === null) {
            throw new HttpException(404, 'Inspection not found.');
        }

        $photoType = in_array((string) ($meta['photo_type'] ?? ''), array_keys(inspection_photo_types()), true)
            ? (string) $meta['photo_type']
            : 'other';

        $stored = $this->process($source, 'inspections/' . date('Y-m') . '/inspection-' . $inspectionId, [
            'line1' => sprintf('Inspector: %s', $inspection['inspector_name']),
            'line2' => $meta,
            'label' => inspection_photo_types()[$photoType] ?? 'Inspection photo',
        ]);

        $id = Database::insert('inspection_photos', [
            'inspection_id' => $inspectionId,
            'photo_type' => $photoType,
            'file_path' => $stored['relative_path'],
            'file_name' => $stored['file_name'],
            'mime_type' => 'image/jpeg',
            'file_size' => $stored['file_size'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'sha256' => $stored['sha256'],
            'latitude' => $this->coordinate($meta['latitude'] ?? null),
            'longitude' => $this->coordinate($meta['longitude'] ?? null),
            'accuracy' => isset($meta['accuracy']) && $meta['accuracy'] !== '' ? (float) $meta['accuracy'] : null,
            'address' => isset($meta['address']) ? mb_substr((string) $meta['address'], 0, 255) : null,
            'captured_at' => Gps::normaliseTimestamp($meta['captured_at'] ?? null) ?? now(),
            'watermarked' => $stored['watermarked'] ? 1 : 0,
            'caption' => isset($meta['caption']) ? mb_substr((string) $meta['caption'], 0, 255) : null,
            'uploaded_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Database::update(
            'inspections',
            ['photo_count' => (int) Database::scalar(
                'SELECT COUNT(*) FROM inspection_photos WHERE inspection_id = :i',
                ['i' => $inspectionId]
            )],
            'id = :id',
            ['id' => $inspectionId]
        );

        return ['id' => $id, 'duplicate' => false];
    }

    /**
     * Store a signature image (PNG data URL from a canvas) and return its
     * relative path.
     */
    public function storeSignature(string $dataUrl, string $prefix): ?string
    {
        $binary = $this->decodeBase64($dataUrl);

        if ($binary === null) {
            return null;
        }

        $directory = storage_path('uploads/signatures/' . date('Y-m'));
        $this->ensureDirectory($directory);

        $fileName = $prefix . '-' . date('Ymd-His') . '-' . str_random(6) . '.png';
        $absolute = $directory . '/' . $fileName;

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        // Flatten onto white so a transparent canvas signature is visible in PDFs.
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagepng($canvas, $absolute, 8);
        imagedestroy($image);
        imagedestroy($canvas);

        @chmod($absolute, 0640);

        return 'uploads/signatures/' . date('Y-m') . '/' . $fileName;
    }

    /* ------------------------------------------------------------------ */
    /* Processing                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{tmp_name:string, name?:string, size?:int, error?:int}|string $source
     * @param array{line1:string, line2:array<string,mixed>, label:string} $watermark
     * @return array{
     *   relative_path:string, absolute_path:string, file_name:string,
     *   file_size:int, width:int, height:int, sha256:string, watermarked:bool
     * }
     */
    private function process(mixed $source, string $subdirectory, array $watermark): array
    {
        $binary = $this->readSource($source);
        $maxBytes = (int) Config::get('security.upload_max_bytes', 8388608);

        if (strlen($binary) > $maxBytes) {
            throw new HttpException(422, sprintf('Photos must be smaller than %d MB.', (int) ($maxBytes / 1048576)));
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD extension is required to process photographs.');
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new HttpException(422, 'That file is not a readable image.');
        }

        $image = $this->resize($image);
        $watermarked = false;

        if (Settings::bool('watermark_photos', true)) {
            $this->watermark($image, $watermark);
            $watermarked = true;
        }

        $directory = storage_path('uploads/photos/' . $subdirectory);
        $this->ensureDirectory($directory);

        $fileName = date('Ymd-His') . '-' . str_random(8) . '.jpg';
        $absolute = $directory . '/' . $fileName;

        if (!imagejpeg($image, $absolute, self::JPEG_QUALITY)) {
            imagedestroy($image);
            throw new RuntimeException('The photograph could not be saved.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        @chmod($absolute, 0640);

        return [
            'relative_path' => 'uploads/photos/' . $subdirectory . '/' . $fileName,
            'absolute_path' => $absolute,
            'file_name' => $fileName,
            'file_size' => (int) filesize($absolute),
            'width' => $width,
            'height' => $height,
            'sha256' => (string) hash_file('sha256', $absolute),
            'watermarked' => $watermarked,
        ];
    }

    private function readSource(mixed $source): string
    {
        if (is_string($source)) {
            $binary = $this->decodeBase64($source);

            if ($binary === null) {
                throw new HttpException(422, 'The photo payload could not be decoded.');
            }

            return $binary;
        }

        if (!is_array($source) || !isset($source['tmp_name'])) {
            throw new HttpException(422, 'No photo was received.');
        }

        if (($source['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The photo upload failed. Please retry.');
        }

        $contents = @file_get_contents($source['tmp_name']);

        if ($contents === false || $contents === '') {
            throw new HttpException(422, 'The uploaded photo was empty.');
        }

        return $contents;
    }

    private function decodeBase64(string $payload): ?string
    {
        if (str_contains($payload, ',') && str_starts_with($payload, 'data:')) {
            $payload = substr($payload, strpos($payload, ',') + 1);
        }

        $payload = preg_replace('/\s+/', '', $payload) ?? $payload;
        $binary = base64_decode($payload, true);

        return $binary === false || $binary === '' ? null : $binary;
    }

    /**
     * @param \GdImage $image
     * @return \GdImage
     */
    private function resize(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= self::MAX_WIDTH) {
            return $image;
        }

        $newWidth = self::MAX_WIDTH;
        $newHeight = (int) round($height * ($newWidth / $width));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Burn the evidence caption into the bottom of the image.
     *
     * GD's bundled bitmap fonts are used deliberately: no TrueType file has to
     * ship with the app, and the text stays legible after JPEG compression.
     *
     * @param array{line1:string, line2:array<string,mixed>, label:string} $watermark
     */
    private function watermark(\GdImage $image, array $watermark): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $meta = $watermark['line2'];
        $captured = Gps::normaliseTimestamp($meta['captured_at'] ?? null) ?? now();

        $lines = [];
        $lines[] = strtoupper($watermark['label']) . ' — ' . $this->ascii($watermark['line1']);
        $lines[] = 'Date: ' . date('d M Y', strtotime($captured)) . '   Time: ' . date('h:i A', strtotime($captured));

        $latitude = $meta['latitude'] ?? null;
        $longitude = $meta['longitude'] ?? null;

        $lines[] = $latitude !== null && $longitude !== null && $latitude !== '' && $longitude !== ''
            ? sprintf('Lat: %.6f  Lng: %.6f%s', (float) $latitude, (float) $longitude,
                isset($meta['accuracy']) && $meta['accuracy'] !== '' ? sprintf('  (±%.0fm)', (float) $meta['accuracy']) : '')
            : 'Location: not captured';

        if (!empty($meta['address'])) {
            $lines[] = $this->ascii(mb_substr((string) $meta['address'], 0, 90));
        }

        // Scale the bitmap font up so the caption is readable on a large photo.
        $baseFont = 3; // 7x13 px
        $scale = max(1, (int) round($width / 640));
        $lineHeight = 13 * $scale;
        $padding = 6 * $scale;
        $bandHeight = ($lineHeight * count($lines)) + ($padding * 2);

        $band = imagecreatetruecolor($width, $bandHeight);
        imagefill($band, 0, 0, imagecolorallocate($band, 0, 0, 0));

        $textColour = imagecolorallocate($band, 255, 255, 255);
        $y = $padding;

        foreach ($lines as $line) {
            if ($scale === 1) {
                imagestring($band, $baseFont, $padding, $y, $line, $textColour);
            } else {
                // Render at 1x then scale the strip: keeps the text crisp-ish
                // without needing a TTF.
                $stripWidth = max(1, imagefontwidth($baseFont) * strlen($line));
                $strip = imagecreatetruecolor($stripWidth, imagefontheight($baseFont));
                imagefill($strip, 0, 0, imagecolorallocate($strip, 0, 0, 0));
                imagestring($strip, $baseFont, 0, 0, $line, imagecolorallocate($strip, 255, 255, 255));
                imagecopyresized(
                    $band,
                    $strip,
                    $padding,
                    $y,
                    0,
                    0,
                    (int) ($stripWidth * $scale * 0.95),
                    (int) (imagefontheight($baseFont) * $scale * 0.95),
                    $stripWidth,
                    imagefontheight($baseFont)
                );
                imagedestroy($strip);
            }

            $y += $lineHeight;
        }

        // Blend the band over the photo at 78% opacity.
        imagecopymerge($image, $band, 0, max(0, $height - $bandHeight), 0, 0, $width, $bandHeight, 78);
        imagedestroy($band);
    }

    /**
     * GD bitmap fonts are single byte, so non-Latin text has to be transliterated.
     */
    private function ascii(string $text): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);

            if ($converted !== false) {
                return (string) $converted;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }

    private function coordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 7);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The photo directory could not be created: ' . $directory);
        }

        // Belt and braces: even if the storage folder is ever exposed, deny it.
        $guard = $directory . '/.htaccess';

        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Serving                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve a stored file to an absolute path, refusing traversal attempts.
     *
     * Only the two directories LRMS writes to are reachable: uploads (evidence)
     * and generated (report exports). Anything else — including a crafted
     * "../../config/config.php" — resolves outside them and is refused.
     */
    public static function absolutePath(string $relativePath): string
    {
        $absolute = realpath(storage_path($relativePath));

        if ($absolute === false) {
            throw new HttpException(404, 'File not found.');
        }

        foreach (['uploads', 'generated'] as $directory) {
            $base = realpath(storage_path($directory));

            if ($base !== false && str_starts_with($absolute, $base . DIRECTORY_SEPARATOR)) {
                return $absolute;
            }
        }

        throw new HttpException(404, 'File not found.');
    }
}
