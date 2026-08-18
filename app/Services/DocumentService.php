<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\DocumentTemplate;
use PDOException;

/**
 * Document persistence + all money calculations.
 *
 * Calculation rules (always executed on the server, never trusted from the browser):
 *   line_subtotal   = quantity × rate
 *   subtotal        = Σ line_subtotal
 *   discount_total  = percent ? subtotal × value / 100 : min(value, subtotal)
 *   taxable line    = line_subtotal × (subtotal − discount_total) / subtotal   (discount spread pro-rata)
 *   line_tax        = taxable line × tax_percent / 100
 *   total           = subtotal − discount_total + Σ line_tax
 */
final class DocumentService
{
    private Document $documents;
    private DocumentItem $items;
    private DocumentTemplate $templates;

    public function __construct()
    {
        $this->documents = new Document();
        $this->items = new DocumentItem();
        $this->templates = new DocumentTemplate();
    }

    /* ------------------------------------------------------------------ */
    /* Calculations                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int, array<string, mixed>> $rawItems
     * @return array{items:array<int, array<string, mixed>>, subtotal:float, tax_total:float, discount_total:float, total:float}
     */
    public function calculate(array $rawItems, string $discountType = 'fixed', float $discountValue = 0.0): array
    {
        $items = [];
        $subtotal = 0.0;

        foreach ($this->normalizeItems($rawItems) as $item) {
            $lineSubtotal = round($item['quantity'] * $item['rate'], 2);
            $item['line_subtotal'] = $lineSubtotal;
            $subtotal += $lineSubtotal;
            $items[] = $item;
        }

        $subtotal = round($subtotal, 2);
        $discountType = $discountType === 'percent' ? 'percent' : 'fixed';
        $discountValue = max(0.0, round($discountValue, 2));

        if ($discountType === 'percent') {
            $discountValue = min(100.0, $discountValue);
            $discountTotal = round($subtotal * $discountValue / 100, 2);
        } else {
            $discountTotal = min($discountValue, $subtotal);
        }

        $factor = $subtotal > 0 ? ($subtotal - $discountTotal) / $subtotal : 1.0;
        $taxTotal = 0.0;

        foreach ($items as $index => $item) {
            $taxable = round($item['line_subtotal'] * $factor, 2);
            $lineTax = round($taxable * $item['tax_percent'] / 100, 2);

            $items[$index]['line_tax'] = $lineTax;
            $items[$index]['line_total'] = round($item['line_subtotal'] + $lineTax, 2);
            $taxTotal += $lineTax;
        }

        $taxTotal = round($taxTotal, 2);

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_total' => round($discountTotal, 2),
            'total' => round($subtotal - $discountTotal + $taxTotal, 2),
        ];
    }

    /**
     * Clean an arbitrary item payload (form post or AI output) into safe rows.
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @return array<int, array<string, mixed>>
     */
    public function normalizeItems(array $rawItems): array
    {
        $items = [];

        foreach (array_slice(array_values($rawItems), 0, 50) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $description = trim((string) ($raw['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $items[] = [
                'description' => mb_substr($description, 0, 500),
                'quantity' => max(0.0, round($this->toFloat($raw['quantity'] ?? 1, 1.0), 2)),
                'unit' => mb_substr(trim((string) ($raw['unit'] ?? 'unit')) ?: 'unit', 0, 30),
                'rate' => max(0.0, round($this->toFloat($raw['rate'] ?? 0, 0.0), 2)),
                'tax_percent' => min(100.0, max(0.0, round($this->toFloat($raw['tax_percent'] ?? 0, 0.0), 2))),
            ];
        }

        return $items;
    }

    private function toFloat(mixed $value, float $fallback): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = str_replace([',', ' '], '', $value);
            if (is_numeric($clean)) {
                return (float) $clean;
            }
        }

        return $fallback;
    }

    /* ------------------------------------------------------------------ */
    /* Create / update                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data  Sanitised controller input (may include 'items')
     */
    public function create(int $userId, array $data): int
    {
        $type = $this->documentType((string) ($data['document_type'] ?? 'quotation'));
        $totals = $this->calculate(
            (array) ($data['items'] ?? []),
            (string) ($data['discount_type'] ?? 'fixed'),
            (float) ($data['discount_value'] ?? 0)
        );

        $status = (string) ($data['status'] ?? 'draft');

        $row = [
            'user_id' => $userId,
            'client_id' => ($data['client_id'] ?? null) ?: null,
            'document_type' => $type,
            'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: (document_type_label($type)), 0, 200),
            'summary' => $this->nullable($data['summary'] ?? null, 1000),
            'status' => in_array($status, Document::STATUSES, true) ? $status : 'draft',
            'template' => $this->template((string) ($data['template'] ?? '')),
            'currency' => $this->currency((string) ($data['currency'] ?? 'INR')),
            'issue_date' => $this->date($data['issue_date'] ?? null) ?? date('Y-m-d'),
            'valid_until' => $this->date($data['valid_until'] ?? null),
            'client_name' => $this->nullable($data['client_name'] ?? null, 160),
            'client_company' => $this->nullable($data['client_company'] ?? null, 160),
            'client_email' => $this->nullable($data['client_email'] ?? null, 190),
            'client_phone' => $this->nullable($data['client_phone'] ?? null, 40),
            'client_address' => $this->nullable($data['client_address'] ?? null, 1000),
            'notes' => $this->nullable($data['notes'] ?? null, 5000),
            'terms' => $this->nullable($data['terms'] ?? null, 8000),
            'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
            'ai_prompt' => $this->nullable($data['ai_prompt'] ?? null, 4000),
            'subtotal' => $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'discount_type' => $totals['discount_type'],
            'discount_value' => $totals['discount_value'],
            'discount_total' => $totals['discount_total'],
            'total' => $totals['total'],
        ];

        $documentId = $this->insertWithNumber($userId, $type, $row);

        $this->items->replaceForDocument($documentId, $totals['items']);

        ActivityLog::record($userId, 'document.created', $row['title'], 'document', $documentId);

        return $documentId;
    }

    /**
     * Insert the document, retrying if two requests grabbed the same number.
     */
    private function insertWithNumber(int $userId, string $type, array $row): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row['document_number'] = $this->documents->nextNumber($userId, $type);

            try {
                return $this->documents->create($row);
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'Duplicate') && (int) $e->getCode() !== 23000) {
                    throw $e;
                }
                usleep(50000);
            }
        }

        throw new HttpException(500, 'Could not allocate a unique document number. Please try again.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $document, array $data): void
    {
        $documentId = (int) $document['id'];

        $totals = $this->calculate(
            (array) ($data['items'] ?? []),
            (string) ($data['discount_type'] ?? $document['discount_type']),
            (float) ($data['discount_value'] ?? $document['discount_value'])
        );

        $status = (string) ($data['status'] ?? '');

        $row = [
            'client_id' => ($data['client_id'] ?? null) ?: null,
            'title' => mb_substr(trim((string) ($data['title'] ?? $document['title'])) ?: (string) $document['title'], 0, 200),
            'summary' => $this->nullable($data['summary'] ?? $document['summary'], 1000),
            'status' => in_array($status, Document::STATUSES, true) ? $status : (string) $document['status'],
            'template' => $this->template((string) ($data['template'] ?? $document['template'])),
            'currency' => $this->currency((string) ($data['currency'] ?? $document['currency'])),
            'issue_date' => $this->date($data['issue_date'] ?? null) ?? (string) $document['issue_date'],
            'valid_until' => $this->date($data['valid_until'] ?? null),
            'client_name' => $this->nullable($data['client_name'] ?? null, 160),
            'client_company' => $this->nullable($data['client_company'] ?? null, 160),
            'client_email' => $this->nullable($data['client_email'] ?? null, 190),
            'client_phone' => $this->nullable($data['client_phone'] ?? null, 40),
            'client_address' => $this->nullable($data['client_address'] ?? null, 1000),
            'notes' => $this->nullable($data['notes'] ?? null, 5000),
            'terms' => $this->nullable($data['terms'] ?? null, 8000),
            'subtotal' => $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'discount_type' => $totals['discount_type'],
            'discount_value' => $totals['discount_value'],
            'discount_total' => $totals['discount_total'],
            'total' => $totals['total'],
        ];

        if (!empty($data['ai_generated'])) {
            $row['ai_generated'] = 1;
        }

        Database::transaction(function () use ($documentId, $row, $totals): void {
            $this->documents->updateById($documentId, $row);
            $this->items->replaceForDocument($documentId, $totals['items']);
        });

        // The stored PDF no longer matches the document.
        $this->invalidatePdf($document);

        ActivityLog::record((int) $document['user_id'], 'document.updated', (string) $row['title'], 'document', $documentId);
    }

    public function duplicate(array $document): int
    {
        $items = $this->documents->items((int) $document['id']);

        $data = [
            'client_id' => $document['client_id'],
            'document_type' => $document['document_type'],
            'title' => mb_substr((string) $document['title'] . ' (copy)', 0, 200),
            'summary' => $document['summary'],
            'status' => 'draft',
            'template' => $document['template'],
            'currency' => $document['currency'],
            'issue_date' => date('Y-m-d'),
            'valid_until' => $document['valid_until'],
            'client_name' => $document['client_name'],
            'client_company' => $document['client_company'],
            'client_email' => $document['client_email'],
            'client_phone' => $document['client_phone'],
            'client_address' => $document['client_address'],
            'notes' => $document['notes'],
            'terms' => $document['terms'],
            'ai_generated' => $document['ai_generated'],
            'discount_type' => $document['discount_type'],
            'discount_value' => $document['discount_value'],
            'items' => array_map(static fn (array $item): array => [
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'rate' => $item['rate'],
                'tax_percent' => $item['tax_percent'],
            ], $items),
        ];

        return $this->create((int) $document['user_id'], $data);
    }

    public function delete(array $document): void
    {
        $this->deletePdfFile($document);
        $this->documents->deleteById((int) $document['id']);

        ActivityLog::record(
            (int) $document['user_id'],
            'document.deleted',
            (string) $document['document_number'],
            'document',
            (int) $document['id']
        );
    }

    public function updateStatus(array $document, string $status): void
    {
        if (!in_array($status, Document::STATUSES, true)) {
            throw new HttpException(422, 'Invalid document status.');
        }

        $this->documents->updateById((int) $document['id'], ['status' => $status]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    public function invalidatePdf(array $document): void
    {
        if (empty($document['pdf_path'])) {
            return;
        }

        $this->deletePdfFile($document);
        $this->documents->updateById((int) $document['id'], ['pdf_path' => null, 'pdf_generated_at' => null]);
    }

    private function deletePdfFile(array $document): void
    {
        $path = (string) ($document['pdf_path'] ?? '');

        if ($path === '') {
            return;
        }

        $full = storage_path('generated/' . basename($path));

        if (is_file($full)) {
            @unlink($full);
        }
    }

    public function documentType(string $type): string
    {
        return array_key_exists($type, document_types()) ? $type : 'quotation';
    }

    public function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return array_key_exists($currency, currencies()) ? $currency : 'INR';
    }

    public function template(string $slug): string
    {
        $slug = trim($slug);

        if ($slug !== '' && $this->templates->findBySlug($slug) !== null) {
            return $slug;
        }

        return $this->templates->defaultSlug();
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function nullable(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * Build the item array from a posted form (items[0][description] style).
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemsFromRequest(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        return $this->normalizeItems(array_values($posted));
    }
}
