<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML → PDF rendering with Dompdf. Generated files live in /storage/generated
 * and are streamed through the application so ownership can be checked.
 */
final class PDFService
{
    public function isAvailable(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function templateFile(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/i', '', $slug) ?: 'modern';
        $file = base_path('resources/templates/' . $slug . '.php');

        return is_file($file) ? $file : base_path('resources/templates/modern.php');
    }

    /**
     * Render the printable HTML for a document.
     *
     * @param array<string, mixed> $document
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $profile
     */
    public function html(array $document, array $items, array $profile, array $options = []): string
    {
        $data = [
            'document' => $document,
            'items' => $items,
            'profile' => $profile,
            'logo' => $this->logoDataUri($profile),
            'accent' => (string) ($options['accent'] ?? '#4f46e5'),
            'for_pdf' => (bool) ($options['for_pdf'] ?? true),
            'share_url' => (string) ($options['share_url'] ?? ''),
        ];

        $file = $this->templateFile((string) ($document['template'] ?? 'modern'));

        ob_start();
        extract($data, EXTR_SKIP);
        require $file;

        return (string) ob_get_clean();
    }

    /**
     * Generate (or regenerate) the PDF file for a document.
     *
     * @return array{success:bool, filename:string, path:string, error:string|null}
     */
    public function generate(array $document, array $items, array $profile, array $options = []): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'filename' => '',
                'path' => '',
                'error' => 'Dompdf is not installed. Run "composer install" on the server.',
            ];
        }

        $directory = storage_path('generated');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            return ['success' => false, 'filename' => '', 'path' => '', 'error' => 'Cannot create /storage/generated. Check folder permissions (755).'];
        }

        if (!is_writable($directory)) {
            return ['success' => false, 'filename' => '', 'path' => '', 'error' => '/storage/generated is not writable. Set permissions to 755 or 775.'];
        }

        try {
            $html = $this->html($document, $items, $profile, $options);
            $dompdf = new Dompdf($this->options());
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $output = $dompdf->output();

            if ($output === null || $output === '') {
                return ['success' => false, 'filename' => '', 'path' => '', 'error' => 'Dompdf produced an empty file.'];
            }

            $filename = $this->filename($document);
            $path = $directory . '/' . $filename;

            if (file_put_contents($path, $output) === false) {
                return ['success' => false, 'filename' => '', 'path' => '', 'error' => 'Could not write the PDF to /storage/generated.'];
            }

            return ['success' => true, 'filename' => $filename, 'path' => $path, 'error' => null];
        } catch (\Throwable $e) {
            Logger::error('PDF generation failed: ' . $e->getMessage(), ['document' => $document['id'] ?? null]);

            return ['success' => false, 'filename' => '', 'path' => '', 'error' => 'PDF generation failed: ' . $e->getMessage()];
        }
    }

    private function options(): Options
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);          // never fetch remote assets from a PDF
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', base_path());
        $options->set('defaultPaperSize', 'A4');
        $options->set('dpi', 96);

        $tmp = storage_path('logs/dompdf');
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        if (is_dir($tmp) && is_writable($tmp)) {
            $options->set('tempDir', $tmp);
            $options->set('fontDir', $tmp);
            $options->set('fontCache', $tmp);
        }

        return $options;
    }

    public function filename(array $document): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($document['document_number'] ?? 'document'));

        return sprintf('%s-%d.pdf', $number, (int) ($document['id'] ?? 0));
    }

    public function pathFor(array $document): ?string
    {
        $stored = (string) ($document['pdf_path'] ?? '');

        if ($stored === '') {
            return null;
        }

        $path = storage_path('generated/' . basename($stored));

        return is_file($path) ? $path : null;
    }

    public function existsFor(array $document): bool
    {
        return $this->pathFor($document) !== null;
    }

    public function requirePath(array $document): string
    {
        $path = $this->pathFor($document);

        if ($path === null) {
            throw new HttpException(404, 'The PDF has not been generated yet.');
        }

        return $path;
    }

    /**
     * Embed the logo as a data URI: the most reliable way to get images into Dompdf.
     */
    public function logoDataUri(array $profile): ?string
    {
        $logo = (string) ($profile['logo_path'] ?? '');

        if ($logo === '') {
            return null;
        }

        $path = storage_path('uploads/logos/' . basename($logo));

        if (!is_file($path) || filesize($path) > 3 * 1024 * 1024) {
            return null;
        }

        $mime = @mime_content_type($path) ?: 'image/png';

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        // Dompdf cannot rasterise WEBP — convert with GD when available.
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($path);
            if ($image !== false) {
                ob_start();
                imagepng($image);
                $binary = (string) ob_get_clean();
                imagedestroy($image);

                return 'data:image/png;base64,' . base64_encode($binary);
            }
        }

        $binary = @file_get_contents($path);

        return $binary === false ? null : 'data:' . $mime . ';base64,' . base64_encode($binary);
    }
}
