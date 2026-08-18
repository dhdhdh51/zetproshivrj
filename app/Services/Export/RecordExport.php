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
                    'Branch: %s (%s)   •   BC Supervisor: %s (%s)',
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
            $pdf->heading('BC Supervisor recommendation', 10);
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
            'BC Supervisor signature' => $visit['supervisor_signature'],
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
     * BC Supervisor Inspection Report — deliberately a separate document from the
     * customer visit report.
     *
     * @return array{path:string, file_name:string}
     */
    public static function inspectionPdf(int $inspectionId): array
    {
        $detail = Inspections::detail($inspectionId);
        $inspection = $detail['inspection'];

        $pdf = new PdfWriter('portrait');
        $pdf->header(
            'BC Supervisor Inspection Report',
            org_name(),
            [
                sprintf('Inspection reference: %s', $inspection['uuid']),
                sprintf(
                    'Inspector: %s   •   BC Supervisor: %s (%s)   •   Branch: %s',
                    $inspection['inspector_name'],
                    $inspection['supervisor_name'],
                    $inspection['bc_code'],
                    $inspection['branch_name']
                ),
                'Purpose: verification of BC Supervisor field work. This is not a customer recovery visit.',
            ]
        );

        $pdf->heading('Inspection');
        $pdf->keyValues([
            'Inspection date' => format_date((string) $inspection['inspection_date']),
            'Started at' => format_datetime($inspection['started_at']),
            'Submitted at' => format_datetime($inspection['submitted_at']),
            'Result' => inspection_result_label($inspection['result']),
            'Follow-up required' => (int) $inspection['followup_required'] === 1 ? 'Yes' : 'No',
            'Status' => enum_label((string) $inspection['status']),
            'Inspector GPS verified' => (int) $inspection['gps_verified'] === 1 ? 'Yes' : 'No',
            'Photographs' => (string) (int) $inspection['photo_count'],
            'Form used' => (string) $inspection['form_name'],
        ]);

        if ($inspection['account_number'] !== null) {
            $pdf->heading('Account inspected');
            $pdf->keyValues([
                'Account number' => (string) $inspection['account_number'],
                'CIF' => (string) $inspection['cif'],
                'Borrower' => (string) $inspection['borrower_name'],
                'Father / guardian' => (string) $inspection['father_name'],
                'Village' => (string) $inspection['village'],
                'Loan type' => (string) $inspection['loan_type'],
                'Outstanding' => money((float) $inspection['outstanding']),
                'Overdue' => money((float) $inspection['overdue']),
            ]);
        }

        if ($inspection['visit_id'] !== null) {
            $distance = null;

            foreach ($detail['gps'] as $point) {
                if ($point['distance_to_visit_metres'] !== null) {
                    $distance = (float) $point['distance_to_visit_metres'];
                    break;
                }
            }

            $pdf->heading('BC Supervisor visit being verified');
            $pdf->keyValues([
                'Visit date' => format_date((string) $inspection['visit_date']),
                'Visit submitted' => format_datetime($inspection['visit_submitted_at']),
                'Reported visit status' => visit_status_label($inspection['visit_status']),
                'Photographs on the visit' => (string) (int) $inspection['visit_photo_count'],
                'Distance between inspector and visit point' => $distance === null
                    ? 'Not comparable'
                    : number_format($distance, 0) . ' m',
                'Visit remarks' => (string) $inspection['visit_remarks'],
            ]);
        }

        if ($detail['answers'] !== []) {
            $pdf->heading('Inspection questionnaire');
            $pdf->table(
                ['Question', 'Answer'],
                array_map(
                    static fn (array $row): array => [(string) ($row['label'] ?: $row['field_key']), (string) $row['value']],
                    $detail['answers']
                ),
                [1.4, 0.8]
            );
        }

        $pdf->heading('Inspector remarks');
        $pdf->paragraph((string) ($inspection['remarks'] ?: 'No remarks recorded.'));

        if ($detail['gps'] !== []) {
            $pdf->heading('Inspector GPS');
            $pdf->table(
                ['Event', 'Latitude', 'Longitude', 'Accuracy', 'Distance to visit', 'Captured', 'Valid'],
                array_map(static fn (array $point): array => [
                    enum_label((string) $point['event']),
                    number_format((float) $point['latitude'], 6),
                    number_format((float) $point['longitude'], 6),
                    $point['accuracy'] === null ? '-' : number_format((float) $point['accuracy'], 0) . ' m',
                    $point['distance_to_visit_metres'] === null
                        ? '-'
                        : number_format((float) $point['distance_to_visit_metres'], 0) . ' m',
                    format_datetime($point['captured_at']),
                    (int) $point['is_valid'] === 1 ? 'Yes' : 'No',
                ], $detail['gps']),
                [0.7, 0.9, 0.9, 0.6, 0.9, 1.1, 0.5],
                ['left', 'right', 'right', 'right', 'right', 'left', 'center']
            );
        }

        if ($detail['photos'] !== []) {
            $pdf->heading('Inspection photographs');
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
            $pdf->heading('Photographs submitted by the BC Supervisor');
            $pdf->imageGrid(array_map(static fn (array $photo): array => [
                'path' => storage_path((string) $photo['file_path']),
                'caption' => sprintf(
                    '%s — %s',
                    photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type']),
                    format_datetime($photo['captured_at'])
                ),
            ], $detail['visit_photos']), 3);
        }

        self::signatures($pdf, [
            'Inspector signature' => $inspection['inspector_signature'],
            'BC Supervisor signature' => $inspection['bc_signature'],
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
                'BC Supervisor Inspection Report PDF generated for %s (%s).',
                $inspection['supervisor_name'],
                $inspection['bc_code']
            ),
        ]);

        return ['path' => $path, 'file_name' => $fileName];
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
