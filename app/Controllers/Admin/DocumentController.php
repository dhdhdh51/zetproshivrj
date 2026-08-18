<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\BusinessProfile;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\PDFService;

final class DocumentController extends Controller
{
    private Document $documents;

    public function __construct()
    {
        $this->documents = new Document();
    }

    public function index(Request $request): void
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $this->view('admin.documents.index', [
            'title' => 'Documents',
            'filters' => $filters,
            'documents' => $this->documents->paginateForAdmin($filters, $request->integer('page', 1), 20),
            'stats' => $this->documents->statistics(),
        ], 'layouts.admin');
    }

    public function show(Request $request): void
    {
        $document = $this->documents->findOrFail($request->paramInt('id'));
        $owner = (new User())->find((int) $document['user_id']);

        $this->view('admin.documents.show', [
            'title' => (string) $document['document_number'],
            'document' => $document,
            'items' => $this->documents->items((int) $document['id']),
            'owner' => $owner,
        ], 'layouts.admin');
    }

    /** Read-only render of the client-facing document. */
    public function preview(Request $request): void
    {
        $document = $this->documents->findOrFail($request->paramInt('id'));
        $template = (new DocumentTemplate())->findBySlug((string) $document['template']);

        $html = (new PDFService())->html(
            $document,
            $this->documents->items((int) $document['id']),
            (new BusinessProfile())->forUserOrEmpty((int) $document['user_id']),
            ['accent' => (string) ($template['accent_color'] ?? '#4f46e5'), 'for_pdf' => false]
        );

        Response::html($html);
    }

    public function destroy(Request $request): void
    {
        $document = $this->documents->findOrFail($request->paramInt('id'));

        (new DocumentService())->delete($document);

        ActivityLog::record(
            Auth::id(),
            'admin.document_deleted',
            (string) $document['document_number'],
            'document',
            (int) $document['id']
        );

        $this->success('Document ' . (string) $document['document_number'] . ' deleted.');
        $this->redirect('/admin/documents');
    }
}
