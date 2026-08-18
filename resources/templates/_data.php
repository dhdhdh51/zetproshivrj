<?php
/**
 * Shared data preparation for the three document templates.
 *
 * Expects: $document, $items, $profile, $logo, $accent, $for_pdf
 * Provides: $currency, $docLabel, $bizLines, $clientLines, $money, $hasBank,
 *           $hasTax, $signatureName, $issueDate, $validUntil
 */

$currency = (string) ($document['currency'] ?? 'INR');
$accent = isset($accent) && $accent !== '' ? (string) $accent : '#4f46e5';
$forPdf = (bool) ($for_pdf ?? true);
$docLabel = strtoupper(document_type_label((string) $document['document_type']));

/** Money formatter for print output. */
$money = static function (float|int|string $amount) use ($currency): string {
    return money((float) $amount, $currency);
};

$number = static function (float|int|string $value): string {
    $value = (float) $value;

    return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.') ?: '0';
};

$bizLines = array_values(array_filter([
    trim((string) ($profile['address'] ?? '')),
    trim(implode(', ', array_filter([
        trim((string) ($profile['city'] ?? '')),
        trim((string) ($profile['state'] ?? '')),
    ]))) . (trim((string) ($profile['postal_code'] ?? '')) !== '' ? ' ' . (string) $profile['postal_code'] : ''),
    trim((string) ($profile['country'] ?? '')),
], static fn (string $line): bool => trim($line) !== ''));

$bizContact = array_values(array_filter([
    trim((string) ($profile['phone'] ?? '')),
    trim((string) ($profile['email'] ?? '')),
    trim((string) ($profile['website'] ?? '')),
], static fn (string $line): bool => $line !== ''));

$bizTax = array_values(array_filter([
    trim((string) ($profile['gstin'] ?? '')) !== '' ? 'GSTIN: ' . (string) $profile['gstin'] : '',
    trim((string) ($profile['tax_number'] ?? '')) !== '' ? 'Tax No: ' . (string) $profile['tax_number'] : '',
], static fn (string $line): bool => $line !== ''));

$clientLines = array_values(array_filter([
    trim((string) ($document['client_company'] ?? '')),
    trim((string) ($document['client_address'] ?? '')),
    trim((string) ($document['client_email'] ?? '')),
    trim((string) ($document['client_phone'] ?? '')),
], static fn (string $line): bool => $line !== ''));

$hasBank = trim((string) ($profile['bank_name'] ?? '')) !== ''
    || trim((string) ($profile['account_number'] ?? '')) !== ''
    || trim((string) ($profile['ifsc'] ?? '')) !== '';

$hasTax = (float) ($document['tax_total'] ?? 0) > 0;
$hasDiscount = (float) ($document['discount_total'] ?? 0) > 0;

$signatureName = trim((string) ($profile['signature_name'] ?? '')) !== ''
    ? (string) $profile['signature_name']
    : trim((string) ($profile['business_name'] ?? ''));

$businessName = trim((string) ($profile['business_name'] ?? '')) !== ''
    ? (string) $profile['business_name']
    : app_name();

$issueDate = format_date((string) ($document['issue_date'] ?? ''), 'd M Y');
$validUntil = empty($document['valid_until']) ? null : format_date((string) $document['valid_until'], 'd M Y');
$taxColumn = false;

foreach ($items as $item) {
    if ((float) $item['tax_percent'] > 0) {
        $taxColumn = true;
        break;
    }
}
