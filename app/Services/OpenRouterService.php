<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;
use App\Models\AiGeneration;

/**
 * All AI functionality goes through OpenRouter. Requests are made server side
 * only — the API key is never exposed to the browser.
 */
final class OpenRouterService
{
    public const WRITING_ACTIONS = [
        'improve' => 'Improve the writing so it reads clearly and professionally.',
        'rewrite' => 'Rewrite the text with the same meaning but fresh wording.',
        'professional' => 'Rewrite the text in a formal, professional business tone.',
        'shorter' => 'Make the text noticeably shorter while keeping every important detail.',
        'expand' => 'Expand the text with helpful, relevant detail. Do not invent facts, prices or figures.',
        'grammar' => 'Fix spelling, grammar and punctuation. Keep the original wording wherever possible.',
    ];

    private AiGeneration $generations;

    public function __construct()
    {
        $this->generations = new AiGeneration();
    }

    /* ------------------------------------------------------------------ */
    /* Configuration                                                       */
    /* ------------------------------------------------------------------ */

    public function config(): array
    {
        return [
            'api_key' => Settings::string('openrouter_api_key'),
            'model' => Settings::string('openrouter_model', 'openai/gpt-4o-mini'),
            'base_url' => rtrim(Settings::string('openrouter_base_url', 'https://openrouter.ai/api/v1'), '/'),
            'temperature' => Settings::float('ai_temperature', 0.4),
            'max_tokens' => Settings::int('ai_max_tokens', 2000),
            'timeout' => (int) config('openrouter.timeout', 90),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->config()['api_key'] !== '';
    }

    public function isEnabled(): bool
    {
        return Settings::bool('ai_enabled', true) && $this->isConfigured();
    }

    public function model(): string
    {
        return $this->config()['model'];
    }

    /* ------------------------------------------------------------------ */
    /* Core request                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @param array{json?:bool, temperature?:float, max_tokens?:int, model?:string, user_id?:int|null,
     *              document_id?:int|null, type?:string, log?:bool} $options
     * @return array{success:bool, content:string, error:string|null, usage:array, model:string}
     */
    public function chat(array $messages, array $options = []): array
    {
        $config = $this->config();

        if (!Settings::bool('ai_enabled', true)) {
            return $this->failure('AI features are currently disabled.', $messages, $options);
        }

        if ($config['api_key'] === '') {
            return $this->failure(
                'OpenRouter is not configured yet. Add your API key in Admin > AI Settings.',
                $messages,
                $options
            );
        }

        $payload = [
            'model' => (string) ($options['model'] ?? $config['model']),
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? $config['temperature']),
            'max_tokens' => (int) ($options['max_tokens'] ?? $config['max_tokens']),
        ];

        if (!empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $start = microtime(true);
        $response = $this->post('/chat/completions', $payload, $config);

        // Some models reject response_format; retry once without it.
        if (!$response['success'] && !empty($options['json']) && $this->mentionsResponseFormat($response['error'] ?? '')) {
            unset($payload['response_format']);
            $response = $this->post('/chat/completions', $payload, $config);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);

        if (!$response['success']) {
            return $this->failure((string) $response['error'], $messages, $options, $duration, $payload['model']);
        }

        $body = $response['body'];
        $content = (string) ($body['choices'][0]['message']['content'] ?? '');
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        if (trim($content) === '') {
            return $this->failure('The AI returned an empty response. Please try again.', $messages, $options, $duration, $payload['model']);
        }

        if (($options['log'] ?? true) && isset($options['user_id'])) {
            $this->generations->log([
                'user_id' => (int) $options['user_id'],
                'document_id' => $options['document_id'] ?? null,
                'type' => (string) ($options['type'] ?? 'document'),
                'model' => $payload['model'],
                'prompt' => $this->promptForLog($messages),
                'response' => $content,
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'duration_ms' => $duration,
                'status' => 'success',
            ]);
        }

        return [
            'success' => true,
            'content' => trim($content),
            'error' => null,
            'usage' => $usage,
            'model' => $payload['model'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Document generation                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{
     *   document_type:string, instructions:string, currency:string, profile:array, client:array,
     *   items:array<int, array<string, mixed>>, discount_type?:string, discount_value?:float,
     *   user_id:int, document_id?:int|null
     * } $context
     * @return array{success:bool, data:array, error:string|null, model?:string}
     */
    public function generateDocument(array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->documentSystemPrompt()],
            ['role' => 'user', 'content' => $this->documentUserPrompt($context)],
        ];

        $result = $this->chat($messages, [
            'json' => true,
            'user_id' => $context['user_id'] ?? null,
            'document_id' => $context['document_id'] ?? null,
            'type' => 'document',
        ]);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $data = $this->extractJson($result['content']);

        if ($data === null) {
            return [
                'success' => false,
                'data' => [],
                'error' => 'The AI response could not be read as structured data. Please try again.',
            ];
        }

        return [
            'success' => true,
            'data' => $this->sanitizeDocumentPayload($data, $context),
            'error' => null,
            'model' => $result['model'],
        ];
    }

    private function documentSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a senior business documentation writer for a document automation product.
You write professional quotations, invoices, proposals, estimates and purchase orders.

Return ONLY a valid JSON object with exactly these keys:
{
  "title": "short document title (max 120 characters)",
  "summary": "1-3 sentence professional summary of the scope",
  "items": [
    {"description": "clear line item description", "quantity": 1, "unit": "unit", "rate": 0, "tax_percent": 0}
  ],
  "notes": "short helpful notes for the client (plain text, may use newlines)",
  "terms": "professional terms and conditions (plain text, may use newlines)"
}

Hard rules:
- NEVER invent business identity or banking data: no GSTIN, tax numbers, bank names, account numbers, IFSC codes, addresses, phone numbers, emails or websites anywhere in your output.
- NEVER invent prices. Use only amounts the user supplied. If the user gives a single total, split it across line items so the item amounts add up to exactly that total.
- If line items are supplied, keep their descriptions faithful and keep quantity, rate and tax_percent exactly as given (you may polish wording only).
- If no amount is mentioned at all, set every rate to 0 and let the user fill them in.
- quantity, rate and tax_percent must be plain numbers (no currency symbols, no commas, no text).
- Write in clear professional English. No markdown, no code fences, no commentary outside the JSON.
PROMPT;
    }

    private function documentUserPrompt(array $context): string
    {
        $profile = $context['profile'] ?? [];
        $client = $context['client'] ?? [];
        $items = $context['items'] ?? [];
        $type = document_type_label((string) $context['document_type']);

        $lines = [];
        $lines[] = 'Document type: ' . $type;
        $lines[] = 'Currency: ' . strtoupper((string) ($context['currency'] ?? 'INR'));

        $lines[] = '';
        $lines[] = 'Sender business (context only — do not repeat identity or banking details in your output):';
        $lines[] = '- Business name: ' . ($profile['business_name'] ?? 'Not provided');
        if (!empty($profile['city']) || !empty($profile['country'])) {
            $lines[] = '- Operating from: ' . trim(($profile['city'] ?? '') . ' ' . ($profile['country'] ?? ''));
        }

        $lines[] = '';
        $lines[] = 'Client:';
        $lines[] = '- Name: ' . ($client['name'] ?? 'Not provided');
        if (!empty($client['company'])) {
            $lines[] = '- Company: ' . $client['company'];
        }

        $lines[] = '';
        if ($items === []) {
            $lines[] = 'Line items: none supplied. Propose sensible line items based on the request below.';
        } else {
            $lines[] = 'Line items supplied by the user (keep these amounts exactly):';
            foreach ($items as $index => $item) {
                $lines[] = sprintf(
                    '%d. %s | quantity: %s %s | rate: %s | tax: %s%%',
                    $index + 1,
                    (string) ($item['description'] ?? ''),
                    (string) ($item['quantity'] ?? 1),
                    (string) ($item['unit'] ?? 'unit'),
                    (string) ($item['rate'] ?? 0),
                    (string) ($item['tax_percent'] ?? 0)
                );
            }
        }

        $discountValue = (float) ($context['discount_value'] ?? 0);
        if ($discountValue > 0) {
            $lines[] = '';
            $lines[] = 'A discount of ' . $discountValue
                . (($context['discount_type'] ?? 'fixed') === 'percent' ? '%' : ' (fixed amount)')
                . ' will be applied by the system. Do not calculate totals yourself.';
        }

        $lines[] = '';
        $lines[] = 'What the user wants:';
        $lines[] = trim((string) ($context['instructions'] ?? '')) !== ''
            ? (string) $context['instructions']
            : 'Create a professional ' . strtolower($type) . ' for the client above.';

        return implode("\n", $lines);
    }

    /**
     * Validate + normalise the AI payload before it touches the database.
     */
    private function sanitizeDocumentPayload(array $data, array $context): array
    {
        $items = [];
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];

        foreach (array_slice($rawItems, 0, 30) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? $item['name'] ?? ''));

            if ($description === '') {
                continue;
            }

            $items[] = [
                'description' => mb_substr($description, 0, 500),
                'quantity' => $this->number($item['quantity'] ?? 1, 1),
                'unit' => mb_substr(trim((string) ($item['unit'] ?? 'unit')) ?: 'unit', 0, 30),
                'rate' => $this->number($item['rate'] ?? $item['price'] ?? 0, 0),
                'tax_percent' => min(100, max(0, $this->number($item['tax_percent'] ?? $item['tax'] ?? 0, 0))),
            ];
        }

        // Keep user supplied pricing authoritative when the counts line up.
        $suppliedItems = $context['items'] ?? [];
        if ($suppliedItems !== [] && count($items) === count($suppliedItems)) {
            foreach ($items as $index => $item) {
                $items[$index]['quantity'] = $this->number($suppliedItems[$index]['quantity'] ?? $item['quantity'], 1);
                $items[$index]['rate'] = $this->number($suppliedItems[$index]['rate'] ?? $item['rate'], 0);
                $items[$index]['tax_percent'] = $this->number($suppliedItems[$index]['tax_percent'] ?? $item['tax_percent'], 0);
                $items[$index]['unit'] = (string) ($suppliedItems[$index]['unit'] ?? $item['unit']);
            }
        }

        return [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 200),
            'summary' => mb_substr($this->plain($data['summary'] ?? ''), 0, 1000),
            'items' => $items,
            'notes' => mb_substr($this->plain($data['notes'] ?? ''), 0, 3000),
            'terms' => mb_substr($this->plain($data['terms'] ?? ''), 0, 5000),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Writing tools                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{success:bool, content:string, error:string|null}
     */
    public function writingTool(string $action, string $text, array $options = []): array
    {
        $instruction = self::WRITING_ACTIONS[$action] ?? self::WRITING_ACTIONS['improve'];

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert business editor. ' . $instruction
                    . ' Reply with the edited text only — no preamble, no quotes, no markdown.'
                    . ' Never invent prices, GSTIN, tax numbers, bank details, addresses, phone numbers or emails.'
                    . ' Keep any numbers that already appear in the text unchanged.',
            ],
            ['role' => 'user', 'content' => mb_substr($text, 0, 6000)],
        ];

        $result = $this->chat($messages, [
            'user_id' => $options['user_id'] ?? null,
            'document_id' => $options['document_id'] ?? null,
            'type' => 'writing_' . $action,
            'max_tokens' => (int) ($options['max_tokens'] ?? 1200),
        ]);

        return [
            'success' => $result['success'],
            'content' => $this->plain($result['content']),
            'error' => $result['error'],
        ];
    }

    /**
     * Generate a client-ready covering email for a document.
     *
     * @return array{success:bool, subject:string, message:string, error:string|null}
     */
    public function generateClientEmail(array $document, array $profile, array $options = []): array
    {
        $context = [
            'Document type: ' . document_type_label((string) $document['document_type']),
            'Document number: ' . (string) $document['document_number'],
            'Document title: ' . (string) $document['title'],
            'Client name: ' . (string) ($document['client_name'] ?? 'the client'),
            'Total: ' . money((float) $document['total'], (string) $document['currency']),
            'Sender business name: ' . (string) ($profile['business_name'] ?? app_name()),
            'Sender contact person: ' . (string) ($profile['signature_name'] ?? ''),
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'Write a short, warm, professional covering email that accompanies a business document.'
                    . ' Return ONLY JSON: {"subject": "...", "message": "..."}.'
                    . ' The message must be plain text with line breaks, 90-150 words, and must not invent prices,'
                    . ' discounts, bank details, addresses or phone numbers. Mention that the document is attached as a PDF.',
            ],
            ['role' => 'user', 'content' => implode("\n", $context) . "\n\n" . trim((string) ($options['instructions'] ?? ''))],
        ];

        $result = $this->chat($messages, [
            'json' => true,
            'user_id' => $options['user_id'] ?? null,
            'document_id' => (int) $document['id'],
            'type' => 'client_email',
            'max_tokens' => 900,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'subject' => '', 'message' => '', 'error' => $result['error']];
        }

        $data = $this->extractJson($result['content']);

        if ($data === null) {
            return [
                'success' => true,
                'subject' => document_type_label((string) $document['document_type']) . ' ' . (string) $document['document_number'],
                'message' => $this->plain($result['content']),
                'error' => null,
            ];
        }

        return [
            'success' => true,
            'subject' => mb_substr($this->plain($data['subject'] ?? ''), 0, 200),
            'message' => mb_substr($this->plain($data['message'] ?? ''), 0, 4000),
            'error' => null,
        ];
    }

    /**
     * Generate terms & conditions for a document type.
     *
     * @return array{success:bool, content:string, error:string|null}
     */
    public function generateTerms(string $documentType, array $profile, array $options = []): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You write concise, fair terms and conditions for small business documents.'
                    . ' Return 5 to 8 short numbered clauses as plain text, one per line.'
                    . ' Cover validity, payment schedule, revisions/scope, delivery timelines, taxes and cancellation'
                    . ' in general language. Never invent specific bank details, GSTIN, tax numbers, addresses,'
                    . ' phone numbers or amounts. No markdown, no headings.',
            ],
            [
                'role' => 'user',
                'content' => 'Document type: ' . document_type_label($documentType)
                    . "\nBusiness name: " . (string) ($profile['business_name'] ?? '')
                    . "\nExtra instructions: " . trim((string) ($options['instructions'] ?? 'none')),
            ],
        ];

        $result = $this->chat($messages, [
            'user_id' => $options['user_id'] ?? null,
            'document_id' => $options['document_id'] ?? null,
            'type' => 'terms',
            'max_tokens' => 900,
        ]);

        return [
            'success' => $result['success'],
            'content' => $this->plain($result['content']),
            'error' => $result['error'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Admin: connection test + model list                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{success:bool, message:string, model?:string, reply?:string, latency_ms?:int}
     */
    public function testConnection(?string $apiKey = null, ?string $model = null): array
    {
        $config = $this->config();

        if ($apiKey !== null && $apiKey !== '') {
            $config['api_key'] = $apiKey;
        }

        if ($config['api_key'] === '') {
            return ['success' => false, 'message' => 'Add an OpenRouter API key first.'];
        }

        $start = microtime(true);
        $response = $this->post('/chat/completions', [
            'model' => $model !== null && $model !== '' ? $model : $config['model'],
            'messages' => [
                ['role' => 'system', 'content' => 'Reply with exactly: DocuPilot connection OK'],
                ['role' => 'user', 'content' => 'ping'],
            ],
            'max_tokens' => 20,
            'temperature' => 0,
        ], $config);

        $latency = (int) round((microtime(true) - $start) * 1000);

        if (!$response['success']) {
            return ['success' => false, 'message' => (string) $response['error'], 'latency_ms' => $latency];
        }

        return [
            'success' => true,
            'message' => 'Connection successful.',
            'model' => (string) ($response['body']['model'] ?? $config['model']),
            'reply' => trim((string) ($response['body']['choices'][0]['message']['content'] ?? '')),
            'latency_ms' => $latency,
        ];
    }

    /**
     * A curated fallback list plus (when reachable) the live OpenRouter catalogue.
     *
     * @return array<int, string>
     */
    public function suggestedModels(): array
    {
        return [
            'openai/gpt-4o-mini',
            'openai/gpt-4o',
            'anthropic/claude-3.5-sonnet',
            'anthropic/claude-3-haiku',
            'google/gemini-flash-1.5',
            'google/gemini-pro-1.5',
            'meta-llama/llama-3.1-70b-instruct',
            'mistralai/mistral-large',
            'deepseek/deepseek-chat',
            'qwen/qwen-2.5-72b-instruct',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* HTTP + helpers                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{success:bool, body:array, error:string|null, status:int}
     */
    private function post(string $endpoint, array $payload, array $config): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'body' => [], 'error' => 'PHP cURL extension is not enabled on this server.', 'status' => 0];
        }

        $ch = curl_init($config['base_url'] . $endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['api_key'],
                'Content-Type: application/json',
                'HTTP-Referer: ' . base_url(),
                'X-Title: ' . app_name(),
            ],
            CURLOPT_TIMEOUT => (int) $config['timeout'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            Logger::error('OpenRouter request failed: ' . $curlError);

            return [
                'success' => false,
                'body' => [],
                'error' => 'Could not reach OpenRouter: ' . ($curlError !== '' ? $curlError : 'unknown network error'),
                'status' => $status,
            ];
        }

        $body = json_decode((string) $raw, true);
        $body = is_array($body) ? $body : [];

        if ($status < 200 || $status >= 300) {
            $error = (string) ($body['error']['message'] ?? $body['message'] ?? ('HTTP ' . $status . ' from OpenRouter'));
            Logger::error('OpenRouter error response', ['status' => $status, 'error' => $error]);

            return ['success' => false, 'body' => $body, 'error' => $this->friendlyError($status, $error), 'status' => $status];
        }

        if (isset($body['error'])) {
            $error = (string) ($body['error']['message'] ?? 'OpenRouter returned an error.');

            return ['success' => false, 'body' => $body, 'error' => $error, 'status' => $status];
        }

        return ['success' => true, 'body' => $body, 'error' => null, 'status' => $status];
    }

    private function friendlyError(int $status, string $error): string
    {
        return match (true) {
            $status === 401 => 'OpenRouter rejected the API key. Check Admin > AI Settings.',
            $status === 402 => 'Your OpenRouter account has insufficient credits.',
            $status === 404 => 'The configured model was not found on OpenRouter: ' . $error,
            $status === 429 => 'OpenRouter rate limit reached. Please wait a moment and try again.',
            $status >= 500 => 'OpenRouter is temporarily unavailable. Please try again shortly.',
            default => $error,
        };
    }

    private function mentionsResponseFormat(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'response_format') || str_contains($error, 'json_object');
    }

    /**
     * @return array{success:bool, content:string, error:string, usage:array, model:string}
     */
    private function failure(
        string $error,
        array $messages,
        array $options,
        int $duration = 0,
        string $model = ''
    ): array {
        if (($options['log'] ?? true) && isset($options['user_id'])) {
            $this->generations->log([
                'user_id' => (int) $options['user_id'],
                'document_id' => $options['document_id'] ?? null,
                'type' => (string) ($options['type'] ?? 'document'),
                'model' => $model !== '' ? $model : $this->config()['model'],
                'prompt' => $this->promptForLog($messages),
                'response' => null,
                'duration_ms' => $duration,
                'status' => 'failed',
                'error_message' => $error,
            ]);
        }

        return ['success' => false, 'content' => '', 'error' => $error, 'usage' => [], 'model' => $model];
    }

    private function promptForLog(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            $parts[] = strtoupper((string) ($message['role'] ?? 'user')) . ': ' . (string) ($message['content'] ?? '');
        }

        return implode("\n\n", $parts);
    }

    /**
     * Pull a JSON object out of a model response, tolerating code fences and prose.
     */
    public function extractJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?|```$/mi', '', $content) ?? $content;
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function number(mixed $value, float $fallback): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (is_string($value)) {
            $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
            if ($clean !== '' && is_numeric($clean)) {
                return round((float) $clean, 2);
            }
        }

        return $fallback;
    }

    /** Strip markdown artefacts and normalise whitespace. */
    private function plain(mixed $value): string
    {
        $text = is_string($value) ? $value : '';
        $text = preg_replace('/```[a-z]*\n?/i', '', $text) ?? $text;
        $text = str_replace(['**', '__'], '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
