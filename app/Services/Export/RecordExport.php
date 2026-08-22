<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Forms;
use App\Services\Gps;
use App\Services\Inspections;

/**
 * Single-record documents.
 *
 * The tabular reports cover lists; these are the printable per-record files a
 * branch actually files away: one customer visit report (TYPE A) and one BC
 * Supervisor inspection report (TYPE B), each with its photographs, GPS trail,
 * form answers and signatures.
 */
final class RecordExport
{
    /**
     * Customer Visit Report for a single visit.
     *
     * @return array{path:string, file_name:string}
     */
    public static function visitPdf(int $visitId): array
    {
        $visit = Database::selectOne(
            'SELECT v.*, a.account_number, a.cif, a.borrower_name, a.father_name, a.mobile, a.village,
                    a.address, a.loan_type, a.outstanding, a.overdue, a.limit_amount, a.npa_date, a.sanction_date,
                    b.name AS branch_name, b.code AS branch_code,
                    u.name AS supervisor_name, s.bc_code, s.mobile AS supervisor_mobile,
                    f.name AS form_name, approver.name AS approver_name
               FROM visits v
               JOIN loan_accounts a ON a.id = v.loan_account_id
               JOIN branches b ON b.id = v.branch_id
               JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
          LEFT JOIN visit_forms f ON f.id = v.form_id
          LEFT JOIN users approver ON approver.id = v.approved_by
              WHERE v.id = :id',
            ['id' => $visitId]
        );

        if ($visit === null) {
            throw new HttpException(404, 'Visit not found.');
        }

        $photos = Database::select('SELECT * FROM visit_photos WHERE visit_id = :id ORDER BY id', ['id' => $visitId]);
        $points = Database::select('SELECT * FROM visit_gps WHERE visit_id = :id ORDER BY id', ['id' => $visitId]);
        $answers = Forms::values(Forms::KIND_VISIT, $visitId);
        $recoveries = Database::select('SELECT * FROM recoveries WHERE visit_id = :id ORDER BY id', ['id' => $visitId]);
        $promises = Database::select('SELECT * FROM promises WHERE visit_id = :id ORDER BY id', ['id' => $visitId]);

        $pdf = new PdfWriter('portrait');
        $pdf->header(
            'Customer Visit Report',
            org_name(),
            [
                sprintf('Visit reference: %s', $visit['uuid']),
                sprintf(
                    'Branch: %s (%s)   •   BCA: %s (%s)',
                    $visit['branch_name'],
                    $visit['branch_code'],
                    $visit['supervisor_name'],
                    $visit['bc_code']
                ),
            ]
        );

        $pdf->heading('Account and borrower');
        $pdf->keyValues([
            'Account number' => (string) $visit['account_number'],
            'CIF' => (string) $visit['cif'],
            'Borrower' => (string) $visit['borrower_name'],
            'Father / guardian' => (string) $visit['father_name'],
            'Mobile' => (string) $visit['mobile'],
            'Village' => (string) $visit['village'],
            'Loan type' => (string) $visit['loan_type'],
            'Sanction date' => format_date($visit['sanction_date']),
            'Limit' => money((float) $visit['limit_amount']),
            'Outstanding' => money((float) $visit['outstanding']),
            'Overdue' => money((float) $visit['overdue']),
            'NPA date' => format_date($visit['npa_date']),
        ]);

        $pdf->heading('Visit');
        $pdf->keyValues([
            'Visit date' => format_date((string) $visit['visit_date']),
            'Started at' => format_datetime($visit['started_at']),
            'Submitted at' => format_datetime($visit['submitted_at']),
            'Visit status' => visit_status_label($visit['visit_status']),
            'Customer available' => self::yesNo($visit['customer_available']),
            'Family met' => self::yesNo($visit['family_met']),
            'Phone contact' => self::yesNo($visit['phone_contact']),
            'House locked' => self::yesNo($visit['house_locked']),
            'Recovery possibility' => enum_label($visit['recovery_possibility']),
            'Current address' => (string) $visit['current_address'],
            'Occupation' => (string) $visit['occupation'],
            'Submission' => (int) $visit['is_late'] === 1 ? 'After the report deadline' : 'Within the deadline',
            'Record status' => enum_label((string) $visit['status']),
            'GPS verified' => (int) $visit['gps_verified'] === 1 ? 'Yes' : 'No',
        ]);

        if (!empty($visit['remarks'])) {
            $pdf->heading('Remarks', 10);
            $pdf->paragraph((string) $visit['remarks']);
        }

        if (!empty($visit['recommendation'])) {
            $pdf->heading('BCA recommendation', 10);
            $pdf->paragraph((string) $visit['recommendation']);
        }

        if ($answers !== []) {
            $pdf->heading('Visit form answers');
            $pdf->table(
                ['Question', 'Answer'],
                array_map(
                    static fn (array $row): array => [(string) ($row['label'] ?: $row['field_key']), (string) $row['value']],
                    $answers
                ),
                [1.2, 1.0]
            );
        }

        if ($recoveries !== [] || $promises !== []) {
            $pdf->heading('Money recorded on this visit');

            $rows = [];

            foreach ($recoveries as $recovery) {
                $rows[] = [
                    'Recovery',
                    money((float) $recovery['amount']),
                    (string) $recovery['payment_mode'],
                    (string) ($recovery['receipt_number'] ?: '-'),
                    format_date((string) $recovery['recovery_date']),
                ];
            }

            foreach ($promises as $promise) {
                $rows[] = [
                    'Promise to pay',
                    money((float) $promise['promise_amount']),
                    enum_label((string) $promise['status']),
                    '-',
                    format_date((string) $promise['promise_date']),
                ];
            }

            $pdf->table(['Type', 'Amount', 'Mode / status', 'Receipt', 'Date'], $rows, [1.0, 0.8, 1.0, 0.8, 0.8], ['left', 'right', 'left', 'left', 'left']);
        }

        if ($points !== []) {
            $pdf->heading('GPS trail');
            $pdf->table(
                ['Event', 'Latitude', 'Longitude', 'Accuracy', 'Captured', 'Valid', 'Note'],
                array_map(static fn (array $point): array => [
                    enum_label((string) $point['event']),
                    number_format((float) $point['latitude'], 6),
                    number_format((float) $point['longitude'], 6),
                    $point['accuracy'] === null ? '-' : number_format((float) $point['accuracy'], 0) . ' m',
                    format_datetime($point['captured_at']),
                    (int) $point['is_valid'] === 1 ? 'Yes' : 'No',
                    (string) $point['validation_note'],
                ], $points),
                [0.7, 0.9, 0.9, 0.6, 1.1, 0.5, 1.4],
                ['left', 'right', 'right', 'right', 'left', 'center', 'left']
            );
        }

        if ($photos !== []) {
            $pdf->heading('Photographic evidence');
            $pdf->imageGrid(array_map(static fn (array $photo): array => [
                'path' => storage_path((string) $photo['file_path']),
                'caption' => sprintf(
                    '%s — %s',
                    photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type']),
                    format_datetime($photo['captured_at'])
                ),
            ], $photos), 3);
        }

        self::signatures($pdf, [
            'Borrower signature' => $visit['borrower_signature'],
            'BCA signature' => $visit['supervisor_signature'],
        ]);

        $pdf->verification(url('/admin/visits/' . $visitId), [
            sprintf('Visit reference: %s', (string) $visit['uuid']),
            sprintf(
                'Account %s   •   %s   •   %s',
                (string) $visit['account_number'],
                (string) $visit['borrower_name'],
                format_date((string) $visit['visit_date'])
            ),
        ]);

        $fileName = sprintf('visit-report-%s-%s.pdf', $visit['account_number'], (string) $visit['visit_date']);
        $path = storage_path('generated/' . $fileName);
        $pdf->save($path);

        Audit::log(Audit::REPORT_EXPORTED, [
            'entity_type' => 'visit',
            'entity_id' => $visitId,
            'description' => sprintf('Customer Visit Report PDF generated for account %s.', $visit['account_number']),
        ]);

        return ['path' => $path, 'file_name' => $fileName];
    }

    /**
     * BCA Inspection Report — deliberately a separate document from the
     * customer visit report.
     *
     * @return array{path:string, file_name:string}
     */
    /**
     * The BCA inspection, printed in the format the client issued.
     *
     * Driven by the form definition rather than a hardcoded list of the 27 items. Three
     * things fall out of that and all of them matter: an inspection recorded before the
     * format changed prints the questions it was actually answered against, a question
     * the BC Supervisor adds in the form builder appears here without anyone editing this file,
     * and an item left blank still prints — the page is a form somebody signs, so a gap
     * has to be visible as a gap rather than silently absent.
     *
     * Walking the definition is what makes the blanks possible: Forms::values() returns
     * only the answers that exist, so a report built from those alone would quietly drop
     * every unanswered item and every section heading.
     */
    public static function inspectionPdf(int $inspectionId): array
    {
        $detail = Inspections::detail($inspectionId);
        $inspection = $detail['inspection'];

        // Forms::values() hands back a list; the walk below needs to look answers up.
        $answers = [];

        foreach ($detail['answers'] as $row) {
            $answers[(string) $row['field_key']] = (string) $row['value'];
        }

        $pdf = new PdfWriter('portrait');
        $pdf->documentHeader(
            org_name(),
            'BCA Inspection',
            [
                sprintf('Reference: %s', $inspection['uuid']),
                sprintf(
                    'BCA: %s (%s)   •   Branch: %s',
                    $inspection['supervisor_name'],
                    $inspection['bc_code'],
                    $inspection['branch_name']
                ),
            ]
        );

        $pdf->keyValues([
            'Inspection date' => format_date((string) $inspection['inspection_date']),
            // The panel account that carried out the inspection. Named BC Supervisor because
            // that is who they are: the BCA is the agent at the outlet, below.
            'BC Supervisor' => (string) $inspection['inspector_name'],
            'Submitted at' => format_datetime($inspection['submitted_at']),
            // Item 24 of the form. Printed under its own name so the page and the paper agree.
            'Observation (item 24)' => $inspection['result'] === null
                ? 'Not recorded'
                : inspection_result_label((string) $inspection['result']),
            // The BCA whose outlet this is, next to the official who visited it. Item 25 of
            // the form carries the visiting official's own name and number; this is the
            // account the inspection is filed against, which is what anybody checking the
            // register looks for first.
            'BCA' => trim(
                (string) $inspection['supervisor_name']
                . ' (' . (string) $inspection['bc_code'] . ')'
            ),
            'Status' => enum_label((string) $inspection['status']),
            'Form used' => (string) $inspection['form_name'],
        ]);

        $fields = $inspection['form_id'] === null
            ? []
            : Forms::fields(Forms::KIND_INSPECTION, (int) $inspection['form_id']);

        self::inspectionItems($pdf, $fields, $answers);

        /* Item 23's photographs, and anything the BCA's own visit carried. */
        if ($detail['photos'] !== []) {
            $pdf->heading('Photographs at the BC point');
            $pdf->imageGrid(array_map(static fn (array $photo): array => [
                'path' => storage_path((string) $photo['file_path']),
                'caption' => sprintf(
                    '%s — %s',
                    inspection_photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type']),
                    format_datetime($photo['captured_at'])
                ),
            ], $detail['photos']), 3);
        }

        if ($detail['visit_photos'] !== []) {
            $pdf->heading('Photographs submitted by the BCA');
            $pdf->imageGrid(array_map(static fn (array $photo): array => [
                'path' => storage_path((string) $photo['file_path']),
                'caption' => sprintf(
                    '%s — %s',
                    photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type']),
                    format_datetime($photo['captured_at'])
                ),
            ], $detail['visit_photos']), 3);
        }

        /* The inspector's own remarks, kept separate from item 22 and item 27. */
        $pdf->heading('BC Supervisor remarks');
        $pdf->paragraph((string) ($inspection['remarks'] ?: 'No remarks recorded.'));

        /*
         * Where the inspector was standing. Not on the printed form, but the report is
         * the record of an inspection somebody signs their name to, and the position it
         * was filed from is part of that.
         */
        if ($detail['gps'] !== []) {
            $pdf->heading('BC Supervisor position');
            $pdf->table(
                ['Event', 'Latitude', 'Longitude', 'Accuracy', 'Captured', 'Valid'],
                array_map(static fn (array $point): array => [
                    enum_label((string) $point['event']),
                    number_format((float) $point['latitude'], 6),
                    number_format((float) $point['longitude'], 6),
                    $point['accuracy'] === null ? '-' : number_format((float) $point['accuracy'], 0) . ' m',
                    format_datetime($point['captured_at']),
                    (int) $point['is_valid'] === 1 ? 'Yes' : 'No',
                ], $detail['gps']),
                [0.7, 0.9, 0.9, 0.6, 1.1, 0.5],
                ['left', 'right', 'right', 'right', 'left', 'center']
            );
        }

        /*
         * Item 26. Ruled lines, because the form is signed by hand on the printed copy —
         * the same reason the field visit report stopped trying to capture a signature.
         */
        /*
         * The client's form calls item 26 "the visiting official", and that wording stays
         * because the printed paper says it. Who that official is, though, is named next to
         * the line: the person who visits the outlet and signs this sheet is the BC
         * Supervisor. The BCA is the agent being inspected and does not sign here.
         */
        $pdf->heading('26. Signature of the visiting official');
        $pdf->signatureLines(['Visiting official (BC Supervisor)', 'Date']);

        // From the letterhead of the form the client issued, so a printed copy carries
        // the office it belongs to rather than looking like a document of our own.
        $pdf->noticeBox(
            'eef2f8',
            [
                '9, Arera Hills, Jail Road, Bhopal.  Telephone: 0755-2552023.  '
                . 'Email: fibhopzo@centralbank.co.in',
                'Toll free helpline: 1800 233 4035',
            ],
            'Central Bank of India — Financial Inclusion, Bhopal Zonal Office'
        );

        $pdf->verification(url('/admin/inspections/' . $inspectionId), [
            sprintf('Inspection reference: %s', (string) $inspection['uuid']),
            sprintf(
                'BCA %s (%s)   •   %s   •   %s',
                (string) $inspection['supervisor_name'],
                (string) $inspection['bc_code'],
                (string) $inspection['branch_name'],
                format_date((string) $inspection['inspection_date'])
            ),
        ]);

        $fileName = sprintf(
            'inspection-report-%s-%s.pdf',
            $inspection['bc_code'],
            (string) $inspection['inspection_date']
        );
        $path = storage_path('generated/' . $fileName);
        $pdf->save($path);

        Audit::log(Audit::REPORT_EXPORTED, [
            'entity_type' => 'inspection',
            'entity_id' => $inspectionId,
            'description' => sprintf(
                'BCA Inspection PDF generated for %s (%s).',
                $inspection['supervisor_name'],
                $inspection['bc_code']
            ),
        ]);

        return ['path' => $path, 'file_name' => $fileName];
    }

    /**
     * Print the form's items in the order they appear on the paper.
     *
     * Consecutive plain answers are collected and printed as one block rather than a row
     * at a time, which is what keeps the page looking like the two-column table the form
     * actually is instead of a ladder of single rows.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, string> $answers
     */
    private static function inspectionItems(PdfWriter $pdf, array $fields, array $answers): void
    {
        /** @var array<string, string> $pending */
        $pending = [];

        $flush = static function () use ($pdf, &$pending): void {
            if ($pending !== []) {
                $pdf->keyValues($pending);
                $pending = [];
            }
        };

        foreach ($fields as $field) {
            $key = (string) $field['field_key'];
            $type = (string) $field['field_type'];
            $label = (string) ($field['label'] ?: $key);
            $value = trim($answers[$key] ?? '');
            $options = is_array($field['option_list'] ?? null) ? $field['option_list'] : [];

            switch ($type) {
                case 'section':
                    $flush();

                    // Section labels carry their item numbers — "1-6. Business
                    // Correspondent Agent" — so the band shows the same numbering the
                    // inspector is reading off the paper.
                    [$number, $title] = self::splitItemNumber($label);
                    $pdf->sectionBand($number, $title);

                    if (!empty($field['help_text'])) {
                        $pdf->paragraph((string) $field['help_text']);
                    }

                    break;

                case 'yes_no':
                    $flush();
                    $pdf->yesNoRow($label, self::boolFromAnswer($value));

                    break;

                case 'checkbox':
                    $flush();
                    $pdf->checkboxes(
                        $options,
                        $value === '' ? [] : array_map('trim', explode(',', $value)),
                        3,
                        $label
                    );

                    break;

                case 'dropdown':
                case 'radio':
                    $flush();

                    // Printed as a tick row rather than a line of text: the form offers a
                    // fixed set of words and shows which one was chosen.
                    if ($options !== []) {
                        $pdf->checkboxes($options, $value === '' ? [] : [$value], 4, $label);
                    } else {
                        $pdf->keyValues([$label => $value]);
                    }

                    break;

                case 'textarea':
                case 'remarks':
                    $flush();
                    $pdf->fieldLabel($label);

                    if ($value === '') {
                        // Ruled space, so an unanswered box can be written on by hand.
                        $pdf->writingBox();
                    } else {
                        $pdf->paragraph($value);
                    }

                    break;

                case 'photo':
                    $flush();

                    // The photographs themselves are printed further down, but the item
                    // keeps its place in the sequence: a page that runs 22, 24 sends
                    // whoever is reading it against the paper looking for what is missing.
                    $pdf->fieldLabel($label);
                    $pdf->paragraph($value === '' ? 'None attached.' : $value . ', shown below.');

                    break;

                case 'gps':
                case 'signature':
                    // Recorded elsewhere on the report, not as a row of text.
                    break;

                default:
                    $pending[$label] = self::inspectionValue($type, $value);
            }
        }

        $flush();
    }

    /**
     * A stored answer as it should read on paper.
     *
     * A date comes out of the database as 2019-06-01 and a decimal as 4250.5, neither of
     * which is how the figure was written on the form. Decimals are grouped to two places
     * rather than run through money(): the form builder can put a decimal field anywhere,
     * and a percentage printed with a rupee sign in front of it would be worse than an
     * unformatted one.
     */
    private static function inspectionValue(string $type, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return match ($type) {
            'date' => format_date($value),
            'decimal' => number_format((float) $value, 2),
            default => $value,
        };
    }

    /**
     * Split "1-6. Business Correspondent Agent" into its number and its title.
     *
     * @return array{0:string, 1:string}
     */
    private static function splitItemNumber(string $label): array
    {
        if (preg_match('/^([0-9]+(?:\s*-\s*[0-9]+)?)\.\s*(.+)$/', $label, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return ['', $label];
    }

    /**
     * A stored Yes / No answer as a boolean, or null when the question was not answered.
     *
     * Null matters: it prints as an unticked pair rather than as a No, so the report does
     * not answer on the inspector's behalf.
     */
    private static function boolFromAnswer(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'yes', '1', 'true' => true,
            'no', '0', 'false' => false,
            default => null,
        };
    }

    /**
     * @param array<string, string|null> $signatures
     */
    private static function signatures(PdfWriter $pdf, array $signatures): void
    {
        $present = array_filter($signatures, static fn (?string $path): bool => $path !== null && $path !== '');

        if ($present === []) {
            return;
        }

        $pdf->heading('Signatures');
        $pdf->imageGrid(array_map(
            static fn (string $path, string $label): array => [
                'path' => storage_path($path),
                'caption' => $label,
            ],
            array_values($present),
            array_keys($present)
        ), 2);
    }

    private static function yesNo(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not recorded';
        }

        return (int) $value === 1 ? 'Yes' : 'No';
    }
}
