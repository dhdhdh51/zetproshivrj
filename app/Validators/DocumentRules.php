<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Document;

/**
 * Shared validation rule sets for documents, clients and document delivery.
 * Keeping them here means the same rules apply wherever the data enters the app.
 */
final class DocumentRules
{
    /**
     * Rules for creating or updating a document (items are validated separately
     * by DocumentService::normalizeItems()).
     *
     * @return array<string, string>
     */
    public static function document(): array
    {
        return [
            'document_type' => 'required|in:' . implode(',', array_keys(document_types())),
            'title' => 'required|max:200',
            'currency' => 'required|in:' . implode(',', array_keys(currencies())),
            'issue_date' => 'required|date',
            'valid_until' => 'nullable|date',
            'client_name' => 'required|max:160',
            'client_email' => 'nullable|email|max:190',
            'client_company' => 'nullable|max:160',
            'client_phone' => 'nullable|max:40',
            'client_address' => 'nullable|max:1000',
            'discount_type' => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'summary' => 'nullable|max:1000',
            'notes' => 'nullable|max:5000',
            'terms' => 'nullable|max:8000',
            'status' => 'nullable|in:' . implode(',', Document::STATUSES),
            'template' => 'nullable|max:30',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function documentMessages(): array
    {
        return [
            'client_name.required' => 'Please choose a client or enter the client name.',
            'title.required' => 'Give the document a title.',
            'issue_date.required' => 'Please set the document date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function client(): array
    {
        return [
            'name' => 'required|min:2|max:160',
            'company' => 'nullable|max:160',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'address' => 'nullable|max:1000',
            'notes' => 'nullable|max:3000',
        ];
    }

    /**
     * Rules for the “Send to client” form.
     *
     * @return array<string, string>
     */
    public static function send(): array
    {
        return [
            'email' => 'required|email|max:190',
            'subject' => 'required|max:200',
            'message' => 'required|max:5000',
        ];
    }
}
