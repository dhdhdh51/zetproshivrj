<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Models\ActivityLog;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\EmailLog;
use App\Models\ShareLink;
use App\Services\DocumentService;
use App\Services\MailService;
use App\Services\OpenRouterService;
use App\Services\PDFService;
use App\Services\UsageService;
use App\Validators\DocumentRules;

final class DocumentController extends Controller
{
    private Document $documents;
    private DocumentService $service;
    private BusinessProfile $profiles;
    private DocumentTemplate $templates;
    private UsageService $usage;

    public function __construct()
    {
        $this->documents = new Document();
        $this->service = new DocumentService();
        $this->profiles = new BusinessProfile();
        $this->templates = new DocumentTemplate();
        $this->usage = new UsageService();
    }

    /* ================================================================== */
    /* Listing                                                             */
    /* ================================================================== */

    public function index(Request $request): void
    {
        $userId = (int) Auth::id();

        $filters = [
            'search' => (string) $request->query('q', ''),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
            'client_id' => $request->integer('client_id', 0),
        ];

        $this->view('documents.index', [
            'title' => 'Documents',
            'filters' => $filters,
            'documents' => $this->documents->paginateForUser($userId, $filters, $request->integer('page', 1), 10),
            'clients' => (new Client())->forUser($userId),
            'summary' => $this->usage->summary($userId),
        ]);
    }

    /* ================================================================== */
    /* Create                                                              */
    /* ================================================================== */

    public function create(Request $request): void
    {
        $userId = (int) Auth::id();
        $profile = $this->profiles->forUserOrEmpty($userId);
        $limit = $this->usage->canCreateDocument($userId);
        $allTemplates = $this->usage->canUseAllTemplates($userId);

        $selectedClient = null;
        $clientId = $request->integer('client_id', 0);

        if ($clientId > 0) {
            $selectedClient = (new Client())->findForUser($clientId, $userId);
        }

        $type = (string) $request->query('type', 'quotation');
        $type = array_key_exists($type, document_types()) ? $type : 'quotation';

        $this->view('documents.create', [
            'title' => 'Create document',
            'profile' => $profile,
            'profile_complete' => $this->profiles->isComplete($this->profiles->forUser($userId)),
            'clients' => (new Client())->forUser($userId),
            'selected_client' => $selectedClient,
            'templates' => $this->templates->active(),
            'all_templates' => $allTemplates,
            'type' => $type,
            'limit' => $limit,
            'summary' => $this->usage->summary($userId),
            'ai_ready' => (new OpenRouterService())->isEnabled(),
            'ai_enabled' => Settings::bool('ai_enabled', true),
        ]);
    }

    public function store(Request $request): void
    {
        $userId = (int) Auth::id();

        $limit = $this->usage->canCreateDocument($userId);

        if (!$limit['allowed']) {
            $this->error($limit['message']);
            $this->redirect('/pricing');

            return;
        }

        $data = $this->documentInput($request, '/documents/create');
        $documentId = $this->service->create($userId, $data);
        $this->usage->recordDocument($userId);

        $this->success('Document ' . (string) ($this->documents->find($documentId)['document_number'] ?? '') . ' created.');
        $this->redirect('/documents/' . $documentId . '/edit');
    }

    /* ================================================================== */
    /* View / edit                                                         */
    /* ================================================================== */

    public function show(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);
        $items = $this->documents->items((int) $document['id']);
        $profile = $this->profiles->forUserOrEmpty($userId);
        $share = $this->documents->shareLink((int) $document['id']);
        $pdf = new PDFService();

        $this->view('documents.show', [
            'title' => (string) $document['document_number'] . ' · ' . (string) $document['title'],
            'document' => $document,
            'items' => $items,
            'profile' => $profile,
            'share' => $share,
            'share_url' => $share === null ? null : url('documents/share/' . (string) $share['token']),
            'pdf_exists' => $pdf->existsFor($document),
            'pdf_available' => $pdf->isAvailable(),
            'emails' => (new EmailLog())->forDocument((int) $document['id']),
            'can_email' => $this->usage->canEmailDocuments($userId),
        ]);
    }

    public function edit(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $this->view('documents.edit', [
            'title' => 'Edit ' . (string) $document['document_number'],
            'document' => $document,
            'items' => $this->documents->items((int) $document['id']),
            'profile' => $this->profiles->forUserOrEmpty($userId),
            'clients' => (new Client())->forUser($userId),
            'templates' => $this->templates->active(),
            'all_templates' => $this->usage->canUseAllTemplates($userId),
            'ai_ready' => (new OpenRouterService())->isEnabled(),
        ]);
    }

    public function update(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $data = $this->documentInput($request, '/documents/' . (int) $document['id'] . '/edit', $document);
        $this->service->update($document, $data);

        $this->success('Changes saved.');

        if ($request->input('action') === 'preview') {
            $this->redirect('/documents/' . (int) $document['id']);

            return;
        }

        $this->redirect('/documents/' . (int) $document['id'] . '/edit');
    }

    public function duplicate(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $limit = $this->usage->canCreateDocument($userId);

        if (!$limit['allowed']) {
            $this->error($limit['message']);
            $this->redirect('/pricing');

            return;
        }

        $newId = $this->service->duplicate($document);
        $this->usage->recordDocument($userId);

        $this->success('Document duplicated as a new draft.');
        $this->redirect('/documents/' . $newId . '/edit');
    }

    public function destroy(Request $request): void
    {
        $document = $this->documents->findForUser($request->paramInt('id'), (int) Auth::id());
        $number = (string) $document['document_number'];

        $this->service->delete($document);

        $this->success('Document ' . $number . ' deleted.');
        $this->redirect('/documents');
    }

    public function status(Request $request): void
    {
        $document = $this->documents->findForUser($request->paramInt('id'), (int) Auth::id());
        $status = (string) $request->input('status', 'draft');

        $this->service->updateStatus($document, $status);
        $this->success('Status updated to ' . ucfirst($status) . '.');
        $this->back('/documents/' . (int) $document['id']);
    }

    /* ================================================================== */
    /* PDF                                                                 */
    /* ================================================================== */

    /** Printable HTML preview (used inside the preview iframe). */
    public function preview(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $html = (new PDFService())->html(
            $document,
            $this->documents->items((int) $document['id']),
            $this->profiles->forUserOrEmpty($userId),
            ['accent' => $this->accent($document), 'for_pdf' => false]
        );

        Response::html($html);
    }

    public function generatePdf(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $result = $this->buildPdf($document, $userId);

        if ($result['success']) {
            $this->success('PDF generated successfully.');
        } else {
            $this->error((string) $result['error']);
        }

        $this->back('/documents/' . (int) $document['id']);
    }

    public function download(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $pdf = new PDFService();
        $path = $pdf->pathFor($document);

        if ($path === null) {
            $result = $this->buildPdf($document, $userId);

            if (!$result['success']) {
                $this->error((string) $result['error']);
                $this->back('/documents/' . (int) $document['id']);

                return;
            }

            $path = (string) $result['path'];
        }

        ActivityLog::record($userId, 'document.downloaded', (string) $document['document_number'], 'document', (int) $document['id']);

        Response::download($path, $document['document_number'] . '.pdf', 'application/pdf');
    }

    /**
     * @return array{success:bool, path:string, error:string|null}
     */
    private function buildPdf(array $document, int $userId): array
    {
        $pdf = new PDFService();

        $result = $pdf->generate(
            $document,
            $this->documents->items((int) $document['id']),
            $this->profiles->forUserOrEmpty($userId),
            ['accent' => $this->accent($document)]
        );

        if ($result['success']) {
            $this->documents->updateById((int) $document['id'], [
                'pdf_path' => $result['filename'],
                'pdf_generated_at' => now(),
            ]);

            ActivityLog::record($userId, 'document.pdf_generated', (string) $document['document_number'], 'document', (int) $document['id']);
        }

        return ['success' => $result['success'], 'path' => (string) $result['path'], 'error' => $result['error']];
    }

    /* ================================================================== */
    /* Sharing                                                             */
    /* ================================================================== */

    public function enableShare(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);

        $link = (new ShareLink())->enable((int) $document['id'], $userId);
        ActivityLog::record($userId, 'document.shared', (string) $document['document_number'], 'document', (int) $document['id']);

        $this->success('Public link enabled: ' . url('documents/share/' . (string) $link['token']));
        $this->back('/documents/' . (int) $document['id']);
    }

    public function disableShare(Request $request): void
    {
        $document = $this->documents->findForUser($request->paramInt('id'), (int) Auth::id());

        (new ShareLink())->disable((int) $document['id']);

        $this->success('Public link disabled.');
        $this->back('/documents/' . (int) $document['id']);
    }

    /* ================================================================== */
    /* Send to client                                                      */
    /* ================================================================== */

    public function sendForm(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);
        $profile = $this->profiles->forUserOrEmpty($userId);
        $mailer = new MailService();

        $this->view('documents.send', [
            'title' => 'Send ' . (string) $document['document_number'],
            'document' => $document,
            'profile' => $profile,
            'can_email' => $this->usage->canEmailDocuments($userId),
            'smtp_ready' => $mailer->isConfigured(),
            'ai_ready' => (new OpenRouterService())->isEnabled(),
            'default_subject' => sprintf(
                '%s %s from %s',
                document_type_label((string) $document['document_type']),
                (string) $document['document_number'],
                trim((string) $profile['business_name']) !== '' ? (string) $profile['business_name'] : app_name()
            ),
            'emails' => (new EmailLog())->forDocument((int) $document['id']),
        ]);
    }

    public function send(Request $request): void
    {
        $userId = (int) Auth::id();
        $document = $this->documents->findForUser($request->paramInt('id'), $userId);
        $redirectTo = '/documents/' . (int) $document['id'] . '/send';

        $canEmail = $this->usage->canEmailDocuments($userId);

        if (!$canEmail['allowed']) {
            $this->error($canEmail['message']);
            $this->redirect('/pricing');

            return;
        }

        $data = $this->validate($request, DocumentRules::send(), [], $redirectTo);

        // Always attach a freshly generated PDF so the client gets the latest version.
        $pdfResult = $this->buildPdf($document, $userId);

        if (!$pdfResult['success']) {
            $this->error('The PDF could not be generated, so nothing was sent: ' . (string) $pdfResult['error']);
            $this->redirect($redirectTo);

            return;
        }

        $document = $this->documents->find((int) $document['id']) ?? $document;
        $share = $this->documents->shareLink((int) $document['id']);

        $result = (new MailService())->sendDocument(
            $document,
            [
                'email' => (string) $data['email'],
                'subject' => (string) $data['subject'],
                'message' => (string) $data['message'],
                'share_url' => $share !== null && (int) $share['is_active'] === 1
                    ? url('documents/share/' . (string) $share['token'])
                    : '',
            ],
            (string) $pdfResult['path'],
            $this->profiles->forUserOrEmpty($userId)
        );

        if (!$result['success']) {
            $this->error('The email could not be sent: ' . $result['message']);
            $this->redirect($redirectTo);

            return;
        }

        $this->documents->updateById((int) $document['id'], ['status' => 'sent', 'sent_at' => now()]);
        $this->usage->recordEmail($userId);

        ActivityLog::record($userId, 'document.emailed', (string) $data['email'], 'document', (int) $document['id']);

        $this->success('Document emailed to ' . (string) $data['email'] . '.');
        $this->redirect('/documents/' . (int) $document['id']);
    }

    /* ================================================================== */
    /* Input handling                                                      */
    /* ================================================================== */

    /**
     * Validate + normalise the document form for both create and update.
     *
     * @return array<string, mixed>
     */
    private function documentInput(Request $request, string $redirectTo, ?array $existing = null): array
    {
        $userId = (int) Auth::id();

        $validated = $this->validate(
            $request,
            DocumentRules::document(),
            DocumentRules::documentMessages(),
            $redirectTo
        );

        $items = $this->service->itemsFromRequest($request->raw('items', []));

        if ($items === []) {
            $this->error('Add at least one line item with a description.');
            Session::flashInput($request->all());
            $this->redirect($redirectTo);
            exit;
        }

        // Free plans may only use the basic template.
        $template = (string) ($validated['template'] ?? '');
        if ($template !== '' && !$this->templates->isAllowed($template, $this->usage->canUseAllTemplates($userId))) {
            $template = $this->templates->defaultSlug();
            $this->info('The selected template is only available on paid plans, so the default template was used.');
        }

        $clientId = $request->integer('client_id', 0);

        if ($clientId > 0) {
            // Ownership check — a user may only attach their own clients.
            (new Client())->findForUser($clientId, $userId);
        }

        return array_merge($validated, [
            'client_id' => $clientId > 0 ? $clientId : null,
            'items' => $items,
            'template' => $template !== '' ? $template : ($existing['template'] ?? $this->templates->defaultSlug()),
            'ai_generated' => $request->boolean('ai_generated'),
            'ai_prompt' => (string) $request->input('ai_prompt', ''),
        ]);
    }

    private function accent(array $document): string
    {
        $template = $this->templates->findBySlug((string) $document['template']);

        return (string) ($template['accent_color'] ?? '#4f46e5');
    }
}
