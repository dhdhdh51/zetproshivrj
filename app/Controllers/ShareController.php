<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\BusinessProfile;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\ShareLink;
use App\Services\PDFService;

/**
 * Public, token-protected document pages: /documents/share/{token}
 */
final class ShareController extends Controller
{
    public function show(Request $request): void
    {
        [$link, $document] = $this->resolve((string) $request->param('token'));

        (new ShareLink())->registerView((int) $link['id']);

        $documents = new Document();
        $profile = (new BusinessProfile())->forUserOrEmpty((int) $document['user_id']);

        $html = (new PDFService())->html(
            $document,
            $documents->items((int) $document['id']),
            $profile,
            ['accent' => $this->accent($document), 'for_pdf' => false]
        );

        $this->view('documents.public', [
            'title' => (string) $document['document_number'] . ' · ' . (string) $document['title'],
            'meta_description' => str_excerpt((string) ($document['summary'] ?? $document['title']), 150),
            'document' => $document,
            'profile' => $profile,
            'token' => (string) $link['token'],
            'document_html' => $html,
            'noindex' => true,
        ], 'layouts.public');
    }

    public function download(Request $request): void
    {
        [, $document] = $this->resolve((string) $request->param('token'));

        $documents = new Document();
        $pdf = new PDFService();
        $path = $pdf->pathFor($document);

        if ($path === null) {
            $result = $pdf->generate(
                $document,
                $documents->items((int) $document['id']),
                (new BusinessProfile())->forUserOrEmpty((int) $document['user_id']),
                ['accent' => $this->accent($document)]
            );

            if (!$result['success']) {
                throw new HttpException(500, 'The PDF could not be generated. Please contact the sender.');
            }

            $documents->updateById((int) $document['id'], [
                'pdf_path' => $result['filename'],
                'pdf_generated_at' => now(),
            ]);

            $path = (string) $result['path'];
        }

        Response::download($path, $document['document_number'] . '.pdf', 'application/pdf');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function resolve(string $token): array
    {
        if (preg_match('/^[a-f0-9]{16,64}$/i', $token) !== 1) {
            throw new HttpException(404, 'This share link is not valid.');
        }

        $link = (new ShareLink())->findByToken($token);

        if ($link === null) {
            throw new HttpException(404, 'This share link is not valid or has been removed.');
        }

        if ((int) $link['is_active'] !== 1) {
            throw new HttpException(403, 'The sender has disabled this link.');
        }

        if (!empty($link['expires_at']) && strtotime((string) $link['expires_at']) < time()) {
            throw new HttpException(403, 'This link has expired. Please ask the sender for a new one.');
        }

        $document = (new Document())->find((int) $link['document_id']);

        if ($document === null) {
            throw new HttpException(404, 'The document is no longer available.');
        }

        return [$link, $document];
    }

    private function accent(array $document): string
    {
        $template = (new DocumentTemplate())->findBySlug((string) $document['template']);

        return (string) ($template['accent_color'] ?? '#4f46e5');
    }
}
