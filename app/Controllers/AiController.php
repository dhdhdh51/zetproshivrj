<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Document;
use App\Services\DocumentService;
use App\Services\OpenRouterService;
use App\Services\UsageService;

/**
 * JSON endpoints for every AI feature. All OpenRouter traffic happens here,
 * server side — the API key never reaches the browser.
 */
final class AiController extends Controller
{
    private OpenRouterService $ai;
    private UsageService $usage;

    public function __construct()
    {
        $this->ai = new OpenRouterService();
        $this->usage = new UsageService();
    }

    /* ================================================================== */
    /* Document generation                                                 */
    /* ================================================================== */

    public function generateDocument(Request $request): void
    {
        $userId = (int) Auth::id();

        if (($guard = $this->guard($userId)) !== null) {
            $this->json($guard, $guard['status'] ?? 429);

            return;
        }

        $instructions = trim((string) $request->input('instructions', ''));

        if (mb_strlen($instructions) < 10) {
            $this->json([
                'success' => false,
                'message' => 'Tell us a little more about what you need (at least 10 characters).',
            ], 422);

            return;
        }

        $service = new DocumentService();
        $profiles = new BusinessProfile();

        $documentType = (string) $request->input('document_type', 'quotation');
        $documentType = array_key_exists($documentType, document_types()) ? $documentType : 'quotation';

        $client = [
            'name' => (string) $request->input('client_name', ''),
            'company' => (string) $request->input('client_company', ''),
        ];

        $clientId = $request->integer('client_id', 0);
        if ($clientId > 0) {
            $record = (new Client())->findForUser($clientId, $userId);
            $client['name'] = (string) $record['name'];
            $client['company'] = (string) ($record['company'] ?? '');
        }

        $items = $service->itemsFromRequest($request->raw('items', []));

        $result = $this->ai->generateDocument([
            'document_type' => $documentType,
            'instructions' => $instructions,
            'currency' => (string) $request->input('currency', 'INR'),
            'profile' => $profiles->forUserOrEmpty($userId),
            'client' => $client,
            'items' => $items,
            'discount_type' => (string) $request->input('discount_type', 'fixed'),
            'discount_value' => $request->decimal('discount_value', 0),
            'user_id' => $userId,
        ]);

        if (!$result['success']) {
            $this->json(['success' => false, 'message' => (string) $result['error']], 502);

            return;
        }

        $this->usage->recordAi($userId);

        $data = $result['data'];
        $totals = $service->calculate(
            $data['items'] === [] ? $items : $data['items'],
            (string) $request->input('discount_type', 'fixed'),
            $request->decimal('discount_value', 0)
        );

        $this->json([
            'success' => true,
            'message' => 'Draft generated. Review and edit anything you like.',
            'data' => [
                'title' => $data['title'],
                'summary' => $data['summary'],
                'notes' => $data['notes'],
                'terms' => $data['terms'],
                'items' => $totals['items'],
            ],
            'totals' => [
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'discount_total' => $totals['discount_total'],
                'total' => $totals['total'],
            ],
            'usage' => $this->usage->summary($userId),
        ]);
    }

    /* ================================================================== */
    /* Writing tools                                                       */
    /* ================================================================== */

    public function write(Request $request): void
    {
        $userId = (int) Auth::id();

        if (($guard = $this->guard($userId)) !== null) {
            $this->json($guard, $guard['status'] ?? 429);

            return;
        }

        $action = (string) $request->input('action', 'improve');

        if (!array_key_exists($action, OpenRouterService::WRITING_ACTIONS)) {
            $this->json(['success' => false, 'message' => 'Unknown writing action.'], 422);

            return;
        }

        $text = trim((string) $request->input('text', ''));

        if (mb_strlen($text) < 3) {
            $this->json(['success' => false, 'message' => 'There is no text to work with yet.'], 422);

            return;
        }

        $documentId = $this->ownDocumentId($request, $userId);

        $result = $this->ai->writingTool($action, $text, [
            'user_id' => $userId,
            'document_id' => $documentId,
        ]);

        if (!$result['success']) {
            $this->json(['success' => false, 'message' => (string) $result['error']], 502);

            return;
        }

        $this->usage->recordAi($userId);

        $this->json([
            'success' => true,
            'message' => 'Text updated.',
            'content' => $result['content'],
            'usage' => $this->usage->summary($userId),
        ]);
    }

    /* ================================================================== */
    /* Client email                                                        */
    /* ================================================================== */

    public function clientEmail(Request $request): void
    {
        $userId = (int) Auth::id();

        if (($guard = $this->guard($userId)) !== null) {
            $this->json($guard, $guard['status'] ?? 429);

            return;
        }

        $documentId = $this->ownDocumentId($request, $userId);

        if ($documentId === null) {
            $this->json(['success' => false, 'message' => 'Save the document first, then generate an email.'], 422);

            return;
        }

        $documents = new Document();
        $document = $documents->findForUser($documentId, $userId);

        $result = $this->ai->generateClientEmail(
            $document,
            (new BusinessProfile())->forUserOrEmpty($userId),
            [
                'user_id' => $userId,
                'instructions' => (string) $request->input('instructions', ''),
            ]
        );

        if (!$result['success']) {
            $this->json(['success' => false, 'message' => (string) $result['error']], 502);

            return;
        }

        $this->usage->recordAi($userId);

        $this->json([
            'success' => true,
            'message' => 'Email drafted.',
            'subject' => $result['subject'],
            'content' => $result['message'],
            'usage' => $this->usage->summary($userId),
        ]);
    }

    /* ================================================================== */
    /* Terms & conditions                                                  */
    /* ================================================================== */

    public function terms(Request $request): void
    {
        $userId = (int) Auth::id();

        if (($guard = $this->guard($userId)) !== null) {
            $this->json($guard, $guard['status'] ?? 429);

            return;
        }

        $documentType = (string) $request->input('document_type', 'quotation');
        $documentType = array_key_exists($documentType, document_types()) ? $documentType : 'quotation';

        $result = $this->ai->generateTerms(
            $documentType,
            (new BusinessProfile())->forUserOrEmpty($userId),
            [
                'user_id' => $userId,
                'document_id' => $this->ownDocumentId($request, $userId),
                'instructions' => (string) $request->input('instructions', ''),
            ]
        );

        if (!$result['success']) {
            $this->json(['success' => false, 'message' => (string) $result['error']], 502);

            return;
        }

        $this->usage->recordAi($userId);

        $this->json([
            'success' => true,
            'message' => 'Terms generated.',
            'content' => $result['content'],
            'usage' => $this->usage->summary($userId),
        ]);
    }

    /* ================================================================== */
    /* Server-side totals (used by the editor to stay authoritative)        */
    /* ================================================================== */

    public function calculate(Request $request): void
    {
        $service = new DocumentService();

        $totals = $service->calculate(
            $service->itemsFromRequest($request->raw('items', [])),
            (string) $request->input('discount_type', 'fixed'),
            $request->decimal('discount_value', 0)
        );

        $this->json([
            'success' => true,
            'items' => $totals['items'],
            'totals' => [
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'discount_total' => $totals['discount_total'],
                'total' => $totals['total'],
            ],
        ]);
    }

    /* ================================================================== */
    /* Helpers                                                             */
    /* ================================================================== */

    /**
     * Rate limit + plan limit + configuration checks shared by every endpoint.
     *
     * @return array<string, mixed>|null  null when the request may proceed
     */
    private function guard(int $userId): ?array
    {
        if (!$this->ai->isEnabled()) {
            return [
                'success' => false,
                'status' => 503,
                'message' => $this->ai->isConfigured()
                    ? 'AI features are currently disabled by the administrator.'
                    : 'AI is not configured yet. An administrator needs to add an OpenRouter API key.',
            ];
        }

        $perMinute = (int) Config::get('security.ai_max_per_minute', 5);
        $key = 'ai:' . $userId;

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return [
                'success' => false,
                'status' => 429,
                'message' => sprintf(
                    'You are generating a lot at once. Please wait %d seconds.',
                    RateLimiter::availableIn($key)
                ),
            ];
        }

        $allowed = $this->usage->canUseAi($userId);

        if (!$allowed['allowed']) {
            return ['success' => false, 'status' => 402, 'message' => $allowed['message'], 'upgrade' => true];
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function ownDocumentId(Request $request, int $userId): ?int
    {
        $documentId = $request->integer('document_id', 0);

        if ($documentId <= 0) {
            return null;
        }

        // Throws 403/404 if the document is not the caller's.
        (new Document())->findForUser($documentId, $userId);

        return $documentId;
    }
}
