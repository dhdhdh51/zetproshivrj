<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\CkccRenewals;
use App\Services\KrmOts;
use App\Services\Visits;

/**
 * The client's official FIELD VISIT VERIFICATION REPORT, printed to PDF.
 *
 * There are two of these and they are deliberately separate documents:
 *
 *   KRM OTS      sections 1-3, 4, 6-13   (no renewal fields anywhere)
 *   CKCC OD-2    sections 1-3, 5, 6-13   (no settlement fields anywhere)
 *
 * Everything printed here is stored data — the loan book, the supervisor's
 * profile, the submitted visit and the work-stream record. Nothing is invented
 * at print time: a blank on the paper means a blank in the database, which is
 * what makes the document usable as evidence.
 *
 * The layout follows the Word original: a title block, numbered section bands,
 * label/value pairs and tick-box lists. Boxes are drawn as vector shapes because
 * the ballot-box characters do not exist in the standard PDF fonts.
 */
final class FieldVisitReport
{
    /** Section 7, "DOCUMENTS VERIFIED". The last entry differs per stream. */
    private const DOCUMENTS = [
        'Aadhaar Card', 'PAN Card', 'Passbook', 'Land Record', 'Khatauni',
        'Electricity Bill', 'Photograph', 'Mobile Verified',
    ];

    /** Section 10, "EVIDENCE ATTACHED". */
    private const EVIDENCE = [
        'Borrower Photograph', 'House Photograph', 'Land Photograph',
        'Aadhaar Copy', 'Passbook Copy', 'GPS Location',
    ];

    /** Section 3, "Loan Type". */
    private const LOAN_TYPES = ['CKCC', 'Agriculture Term Loan', 'OD', 'CC', 'MSME', 'Housing', 'Other'];

    /** Section 3, "Asset Classification". */
    private const ASSET_CLASSES = [
        'standard' => 'Standard',
        'sma_0' => 'SMA-0',
        'sma_1' => 'SMA-1',
        'sma_2' => 'SMA-2',
        'npa' => 'NPA',
    ];

    /**
     * Build the report for a submitted visit.
     *
     * @return array{path:string, file_name:string}
     */
    public static function pdf(int $visitId): array
    {
        $visit = self::load($visitId);
        $stream = (string) $visit['visit_type'];

        if (!in_array($stream, ['krm_ots', 'ckcc_od2'], true)) {
            throw new HttpException(
                422,
                'The field visit verification report covers KRM OTS and CKCC OD-2 cases. '
                . 'Use the Customer Visit Report for a recovery visit.'
            );
        }

        $case = $stream === 'krm_ots'
            ? Database::selectOne(
                'SELECT * FROM krm_ots_cases WHERE visit_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $visitId]
            )
            : Database::selectOne(
                'SELECT * FROM ckcc_renewals WHERE visit_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $visitId]
            );

        $photos = Database::select('SELECT * FROM visit_photos WHERE visit_id = :id ORDER BY id', ['id' => $visitId]);
        $point = Database::selectOne(
            'SELECT * FROM visit_gps WHERE visit_id = :id ORDER BY id LIMIT 1',
            ['id' => $visitId]
        );

        $pdf = new PdfWriter('portrait');

        // The four-line title block of the client's Word template. Each report
        // names only its own stream: the master template lists both because it is
        // one form for both cases, but these are issued separately, and the
        // client's own filled CKCC example carries just "(CKCC OD-2 Renewal /
        // Recovery Verification Report)".
        $pdf->documentHeader(
            org_name(),
            'FIELD VISIT VERIFICATION REPORT',
            [
                sprintf(
                    '(%s  /  Recovery Verification Report)',
                    $stream === 'krm_ots' ? 'KRM OTS' : 'CKCC OD-2 Renewal'
                ),
                'RBI Guidelines & Bank\'s Code of Conduct Compliant Format',
            ]
        );

        $pdf->header('FIELD VISIT VERIFICATION REPORT', org_name(), [
            sprintf('Report reference: %s', (string) $visit['uuid']),
        ]);

        self::sectionGeneral($pdf, $visit, $point);
        self::sectionBorrower($pdf, $visit);
        self::sectionLoanAccount($pdf, $visit);

        if ($stream === 'krm_ots') {
            self::sectionKrmOts($pdf, $visit, $case);
        } else {
            self::sectionCkcc($pdf, $visit, $case);
        }

        self::sectionPhysicalVerification($pdf, $visit);
        self::sectionDocuments($pdf, $visit, $stream);
        self::sectionObservations($pdf, $visit);
        self::sectionRecommendation($pdf, $visit, $case, $stream);
        self::sectionEvidence($pdf, $visit, $stream, $photos);
        self::sectionDeclaration($pdf, $visit);
        self::sectionCertification($pdf, $visit);
        self::sectionFinalStatus($pdf, $case, $stream);
        self::closingNote($pdf, $stream);

        $fileName = sprintf(
            '%s-verification-report-%s-%s.pdf',
            $stream === 'krm_ots' ? 'krm-ots' : 'ckcc-od2',
            preg_replace('/[^A-Za-z0-9]+/', '', (string) $visit['account_number']),
            date('Ymd-His')
        );

        $path = $pdf->save(storage_path('generated/' . $fileName));

        Audit::log(Audit::REPORT_EXPORTED, [
            'entity_type' => 'visit',
            'entity_id' => $visitId,
            'description' => sprintf(
                '%s field visit verification report exported for account %s.',
                $stream === 'krm_ots' ? 'KRM OTS' : 'CKCC OD-2',
                (string) $visit['account_number']
            ),
        ]);

        return ['path' => $path, 'file_name' => $fileName];
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(int $visitId): array
    {
        $visit = Database::selectOne(
            'SELECT v.*,
                    a.account_number, a.cif, a.borrower_name, a.father_name, a.mobile, a.alternate_mobile,
                    a.gender, a.date_of_birth, a.aadhaar_last4, a.pan_number,
                    a.village, a.gram_panchayat, a.tehsil, a.district AS account_district,
                    a.state AS account_state, a.pincode, a.address,
                    a.loan_type, a.sanction_date, a.npa_date, a.limit_amount, a.drawing_power,
                    a.outstanding, a.interest_overdue, a.overdue, a.asset_classification,
                    b.name AS branch_name, b.code AS branch_code, b.region AS regional_office,
                    b.zone, b.district AS branch_district,
                    s.bc_code, s.sp_cbc_name, s.dra_id, s.iibf_number, s.designation AS bc_designation,
                    s.village AS bc_village, s.mobile AS supervisor_mobile,
                    u.name AS supervisor_name, u.employee_code AS supervisor_employee_code,
                    approver.name AS approver_name, approver.employee_code AS approver_employee_code
               FROM visits v
               JOIN loan_accounts a ON a.id = v.loan_account_id
               JOIN branches b ON b.id = v.branch_id
               JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
          LEFT JOIN users approver ON approver.id = v.approved_by
              WHERE v.id = :id',
            ['id' => $visitId]
        );

        if ($visit === null) {
            throw new HttpException(404, 'Visit not found.');
        }

        return $visit;
    }

    /* ------------------------------------------------------------------ */
    /* 1. General information                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     * @param array<string, mixed>|null $point
     */
    private static function sectionGeneral(PdfWriter $pdf, array $visit, ?array $point): void
    {
        $pdf->sectionBand('1', 'General information');

        $pdf->keyValues([
            'Visit Date' => format_date((string) $visit['visit_date']),
            'Visit Time' => $visit['visit_time'] === null ? '' : format_time((string) $visit['visit_time']),
        ]);

        // Case Type is a tick list on the paper form, so it prints as one.
        $caseLabels = Visits::CASE_TYPES;
        unset($caseLabels['customer']);
        $current = $caseLabels[(string) $visit['visit_type']] ?? '';

        $pdf->checkboxes(array_values($caseLabels), [$current], 3, 'Case Type');

        $pdf->keyValues([
            'Branch Name' => (string) $visit['branch_name'],
            'Branch Code' => (string) $visit['branch_code'],
            'Regional Office' => (string) $visit['regional_office'],
            'Zone' => (string) $visit['zone'],
            'SP / CBC Name' => (string) $visit['sp_cbc_name'],
            'BC Agent / DRA Name' => (string) $visit['supervisor_name'],
            'BC Code / DRA ID' => self::joinCodes((string) $visit['bc_code'], (string) $visit['dra_id']),
            'IIBF Number' => (string) $visit['iibf_number'],
            'Linked Branch' => (string) $visit['branch_name'],
            'District' => (string) ($visit['branch_district'] ?: $visit['account_district']),
            'Village / Location' => (string) ($visit['village'] ?: $visit['bc_village']),
            'GPS Latitude' => $point === null ? 'Not captured' : number_format((float) $point['latitude'], 6),
            'GPS Longitude' => $point === null ? 'Not captured' : number_format((float) $point['longitude'], 6),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 2. Borrower information                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    private static function sectionBorrower(PdfWriter $pdf, array $visit): void
    {
        $pdf->sectionBand('2', 'Borrower information');

        $pdf->keyValues([
            'Borrower Name' => (string) $visit['borrower_name'],
            "Father's / Husband's Name" => (string) $visit['father_name'],
        ]);

        $gender = (string) ($visit['gender'] ?? '');
        $pdf->checkboxes(
            ['Male', 'Female', 'Other'],
            $gender === '' ? [] : [ucfirst($gender)],
            3,
            'Gender'
        );

        $pdf->keyValues([
            'Date of Birth' => $visit['date_of_birth'] === null ? '' : format_date((string) $visit['date_of_birth']),
            'Mobile Number' => (string) $visit['mobile'],
            'Alternate Mobile' => (string) $visit['alternate_mobile'],
            // Only the last four digits are held, which is all this form shows.
            'Aadhaar (Last 4 Digits)' => $visit['aadhaar_last4'] === null || $visit['aadhaar_last4'] === ''
                ? 'XXXX-XXXX-'
                : 'XXXX-XXXX-' . $visit['aadhaar_last4'],
            'PAN Number' => (string) $visit['pan_number'],
        ]);

        $pdf->heading('Address', 9.0);
        $pdf->keyValues([
            'Village' => (string) $visit['village'],
            'Gram Panchayat' => (string) $visit['gram_panchayat'],
            'Tehsil' => (string) $visit['tehsil'],
            'District' => (string) $visit['account_district'],
            'State' => (string) $visit['account_state'],
            'PIN Code' => (string) $visit['pincode'],
        ]);

        $pdf->keyValues(['Complete Residential Address' => (string) $visit['address']], 1);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Loan account details                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    private static function sectionLoanAccount(PdfWriter $pdf, array $visit): void
    {
        $pdf->sectionBand('3', 'Loan account details');

        $pdf->keyValues([
            'Loan Account Number' => (string) $visit['account_number'],
            'CIF Number' => (string) $visit['cif'],
        ]);

        // The loan book stores free text, so an unrecognised product still
        // prints — as "Other" plus the stored wording.
        $loanType = trim((string) $visit['loan_type']);
        $matched = null;

        foreach (self::LOAN_TYPES as $option) {
            if (strcasecmp($option, $loanType) === 0) {
                $matched = $option;
                break;
            }
        }

        $pdf->checkboxes(
            self::LOAN_TYPES,
            $loanType === '' ? [] : [$matched ?? 'Other'],
            3,
            'Loan Type'
        );

        if ($matched === null && $loanType !== '') {
            $pdf->keyValues(['Loan type as recorded' => $loanType], 1);
        }

        $pdf->keyValues([
            'Sanction Date' => $visit['sanction_date'] === null ? '' : format_date((string) $visit['sanction_date']),
            'Sanction Limit' => money((float) $visit['limit_amount']),
            'Drawing Power' => self::optionalMoney($visit['drawing_power']),
            'Outstanding Amount' => money((float) $visit['outstanding']),
            'Interest Overdue' => self::optionalMoney($visit['interest_overdue']),
            'Overdue Amount' => money((float) $visit['overdue']),
            'NPA Date' => $visit['npa_date'] === null ? '' : format_date((string) $visit['npa_date']),
        ]);

        $assetClass = (string) ($visit['asset_classification'] ?? '');

        $pdf->checkboxes(
            array_values(self::ASSET_CLASSES),
            $assetClass === '' ? [] : [self::ASSET_CLASSES[$assetClass] ?? ''],
            3,
            'Asset Classification'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 4. KRM OTS details                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     * @param array<string, mixed>|null $case
     */
    private static function sectionKrmOts(PdfWriter $pdf, array $visit, ?array $case): void
    {
        $pdf->sectionBand('4', 'KRM OTS details');

        if ($case === null) {
            $pdf->paragraph('No settlement detail was recorded against this visit.');

            return;
        }

        $pdf->yesNoRow('OTS Eligibility - Eligible for KRM OTS', self::boolOrNull($case['ots_eligible']));

        $pdf->checkboxes(
            array_values(KrmOts::SCHEMES),
            [KrmOts::SCHEMES[(string) $case['scheme']] ?? ''],
            3,
            'Applicable Scheme'
        );

        if ((string) $case['scheme'] === 'other' && (string) $case['scheme_other'] !== '') {
            $pdf->keyValues(['Scheme as recorded' => (string) $case['scheme_other']], 1);
        }

        $pdf->keyValues([
            'Outstanding Amount' => money((float) $case['outstanding']),
            'Proposed Settlement' => money((float) $case['ots_amount']),
            "Borrower's Share" => self::optionalMoney($case['borrower_share']),
            'Initial Deposit Required' => self::optionalMoney($case['initial_deposit_required']),
            'Amount Paid So Far' => money((float) $case['paid_amount']),
            'Case Status' => KrmOts::STATUSES[(string) $case['ots_status']] ?? '',
        ]);

        $response = (string) ($case['customer_response'] ?? '');

        $pdf->checkboxes(
            array_values(KrmOts::CUSTOMER_RESPONSES),
            $response === '' ? [] : [KrmOts::CUSTOMER_RESPONSES[$response] ?? ''],
            3,
            'Customer Response'
        );

        $pdf->keyValues([
            'Expected Deposit Date' => $case['promise_date'] === null
                ? ''
                : format_date((string) $case['promise_date']),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 5. CKCC OD-2 renewal details                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     * @param array<string, mixed>|null $case
     */
    private static function sectionCkcc(PdfWriter $pdf, array $visit, ?array $case): void
    {
        $pdf->sectionBand('5', 'CKCC OD-2 renewal details');

        if ($case === null) {
            $pdf->paragraph('No renewal detail was recorded against this visit.');

            return;
        }

        $pdf->yesNoRow('Eligible for Renewal', self::boolOrNull($case['renewal_eligible']));

        $bucket = (string) ($case['renewal_due_bucket'] ?? '');

        $pdf->checkboxes(
            array_values(CkccRenewals::DUE_BUCKETS),
            $bucket === '' ? [] : [CkccRenewals::DUE_BUCKETS[$bucket] ?? ''],
            4,
            'Renewal Due'
        );

        $pdf->keyValues([
            'Renewal Due Date' => $case['renewal_due_date'] === null
                ? ''
                : format_date((string) $case['renewal_due_date']),
            'Expected NPA Date' => $case['expected_npa_date'] === null
                ? ''
                : format_date((string) $case['expected_npa_date']),
            'Days Remaining' => $case['days_remaining'] === null ? '' : (string) (int) $case['days_remaining'],
            'Limit Amount' => money((float) $case['limit_amount']),
            'Outstanding Amount' => money((float) $case['outstanding']),
            'Overdue Amount' => money((float) $case['overdue']),
        ]);

        $kyc = (string) ($case['kyc_status'] ?? '');

        $pdf->checkboxes(
            array_values(CkccRenewals::KYC_STATUSES),
            $kyc === '' ? [] : [CkccRenewals::KYC_STATUSES[$kyc] ?? ''],
            3,
            'KYC Status'
        );

        $pdf->yesNoRow('Aadhaar Seeded', self::boolOrNull($case['aadhaar_seeded']));
        $pdf->yesNoRow('Mobile Linked', self::boolOrNull($case['mobile_linked']));

        $authentication = (string) ($case['aadhaar_authentication'] ?? '');

        $pdf->checkboxes(
            array_values(CkccRenewals::AUTHENTICATION),
            $authentication === '' ? [] : [CkccRenewals::AUTHENTICATION[$authentication] ?? ''],
            3,
            'Aadhaar Authentication'
        );

        $pdf->yesNoRow('Renewal Consent - Borrower Willing to Renew', self::boolOrNull($case['renewal_consent']));
        $pdf->yesNoRow('Renewal Form Signed', self::boolOrNull($case['renewal_form_signed']));
        $pdf->yesNoRow('Biometrics Completed', self::boolOrNull($case['biometrics_completed']));

        $pdf->keyValues([
            'Renewal Status' => CkccRenewals::STATUSES[(string) $case['renewal_status']] ?? '',
            'Documents Status' => CkccRenewals::DOCUMENT_STATUSES[(string) $case['documents_status']] ?? '',
            'Renewed On' => $case['renewed_on'] === null ? '' : format_date((string) $case['renewed_on']),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 6. Physical verification                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    private static function sectionPhysicalVerification(PdfWriter $pdf, array $visit): void
    {
        $pdf->sectionBand('6', 'Physical verification');

        $pdf->yesNoRow('Borrower Met', self::boolOrNull($visit['customer_available']));
        $pdf->yesNoRow('Family Member Met', self::boolOrNull($visit['family_met']));
        $pdf->yesNoRow('House Locked', self::boolOrNull($visit['house_locked']));
        $pdf->yesNoRow('Borrower Alive', self::boolOrNull($visit['is_alive']));

        $shifted = self::boolOrNull($visit['address_shifted']);

        $pdf->checkboxes(
            ['Same', 'Shifted'],
            $shifted === null ? [] : [$shifted ? 'Shifted' : 'Same'],
            3,
            'Current Address'
        );

        if ($shifted === true && (string) $visit['current_address'] !== '') {
            $pdf->keyValues(['New address' => (string) $visit['current_address']], 1);
        }

        $pdf->yesNoRow('Mobile Contacted', self::boolOrNull($visit['phone_contact']));

        $residence = self::boolOrNull($visit['residence_verified']);
        $pdf->checkboxes(
            ['Confirmed', 'Not Confirmed'],
            $residence === null ? [] : [$residence ? 'Confirmed' : 'Not Confirmed'],
            3,
            'Residence Verification'
        );

        $neighbour = self::boolOrNull($visit['neighbour_verified']);
        $pdf->checkboxes(
            ['Conducted', 'Not Conducted'],
            $neighbour === null ? [] : [$neighbour ? 'Conducted' : 'Not Conducted'],
            3,
            'Neighbour Verification'
        );

        $occupation = trim((string) $visit['occupation']);
        $occupations = ['Agriculture', 'Dairy', 'Business', 'Labour', 'Service', 'Other'];
        $matched = null;

        foreach ($occupations as $option) {
            if (strcasecmp($option, $occupation) === 0) {
                $matched = $option;
                break;
            }
        }

        $pdf->checkboxes(
            $occupations,
            $occupation === '' ? [] : [$matched ?? 'Other'],
            3,
            'Current Occupation'
        );

        if ($matched === null && $occupation !== '') {
            $pdf->keyValues(['Occupation as recorded' => $occupation], 1);
        }
    }

    /* ------------------------------------------------------------------ */
    /* 7. Documents verified                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    private static function sectionDocuments(PdfWriter $pdf, array $visit, string $stream): void
    {
        $pdf->sectionBand('7', 'Documents verified');

        // The stream's own document, and never the other stream's.
        $options = array_merge(
            self::DOCUMENTS,
            [$stream === 'krm_ots' ? 'OTS Consent Letter' : 'Renewal Form', 'Other']
        );

        $pdf->checkboxes($options, self::ticked($visit['documents_verified']), 3);

        if ((string) $visit['documents_other'] !== '') {
            $pdf->keyValues(['Other document' => (string) $visit['documents_other']], 1);
        }
    }

    /* ------------------------------------------------------------------ */
    /* 8. Observations                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    private static function sectionObservations(PdfWriter $pdf, array $visit): void
    {
        $pdf->sectionBand('8', 'BC Agent / DRA observations');

        $remarks = trim((string) $visit['remarks']);

        if ($remarks === '') {
            // The template leaves a shaded panel here for handwriting. Printing
            // "No observations were recorded." instead would deny the branch the
            // space the form gives them.
            $pdf->writingBox(46.0);

            return;
        }

        $pdf->noticeBox('eef2f8', [$remarks], null, 9.0);
    }

    /* ------------------------------------------------------------------ */
    /* 9. Recommendation                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     * @param array<string, mixed>|null $case
     */
    private static function sectionRecommendation(PdfWriter $pdf, array $visit, ?array $case, string $stream): void
    {
        $pdf->sectionBand('9', 'Recommendation');

        $options = $stream === 'krm_ots' ? KrmOts::RECOMMENDATIONS : CkccRenewals::RECOMMENDATIONS;
        $selected = (string) ($case['recommendation'] ?? '');

        $pdf->checkboxes(
            array_values($options),
            $selected === '' ? [] : [$options[$selected] ?? ''],
            2,
            $stream === 'krm_ots' ? 'KRM OTS' : 'CKCC Renewal'
        );

        // "General Recommendation" is a bold sub-heading over a panel in the
        // template, not a label-and-value row.
        $general = trim((string) $visit['recommendation']);
        $pdf->fieldLabel('General Recommendation');

        if ($general === '') {
            $pdf->writingBox(40.0);

            return;
        }

        $pdf->noticeBox('eef2f8', [$general], null, 9.0);
    }

    /* ------------------------------------------------------------------ */
    /* 10. Evidence attached                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     * @param array<int, array<string, mixed>> $photos
     */
    private static function sectionEvidence(PdfWriter $pdf, array $visit, string $stream, array $photos): void
    {
        $pdf->sectionBand('10', 'Evidence attached');

        $options = array_merge(
            self::EVIDENCE,
            [$stream === 'krm_ots' ? 'OTS Consent' : 'Renewal Form', 'Other']
        );

        $pdf->checkboxes($options, self::ticked($visit['evidence_attached']), 3);

        if ((string) $visit['evidence_other'] !== '') {
            $pdf->keyValues(['Other evidence' => (string) $visit['evidence_other']], 1);
        }

        if ($photos === []) {
            return;
        }

        $pdf->heading('Photographs captured in the field', 9.0);
        $pdf->imageGrid(array_map(
            static fn (array $photo): array => [
                'path' => storage_path((string) $photo['file_path']),
                'caption' => sprintf(
                    '%s%s',
                    photo_types()[(string) $photo['photo_type']] ?? (string) $photo['photo_type'],
                    $photo['captured_at'] === null ? '' : ' - ' . format_datetime((string) $photo['captured_at'])
                ),
            ],
            $photos
        ), 3);
    }

    /* ------------------------------------------------------------------ */
    /* 11. Declaration                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    /**
     * The closing note the template prints under the last section.
     *
     * The wording is the client's, with only the case list narrowed to the stream
     * this report covers — their own CKCC example does exactly that.
     */
    private static function closingNote(PdfWriter $pdf, string $stream): void
    {
        $cases = $stream === 'krm_ots'
            ? 'KRM OTS, Recovery Follow-up, Pre-NPA Verification, and Post-NPA Verification cases'
            : 'CKCC OD-2 Renewal, Recovery Follow-up, Pre-NPA Verification, and Post-NPA Verification cases';

        $pdf->spacer(2);
        $pdf->noticeBox(
            'eef2f8',
            [
                'This report is designed for use in ' . $cases . '. It is intended to support field '
                . 'verification, customer due diligence, recovery monitoring, renewal processing, and timely '
                . 'preventive action in accordance with the applicable RBI guidelines, the Bank\'s internal '
                . 'policies, and the Fair Practices Code.',
            ],
            'Important Note',
            8.5
        );
    }

    private static function sectionDeclaration(PdfWriter $pdf, array $visit): void
    {
        $pdf->sectionBand('11', 'Declaration');

        // One cream block, as in the template, rather than loose paragraphs.
        $pdf->noticeBox('fbf3df', self::declarationParagraphs(), null, 8.5);

        $accepted = (int) ($visit['declaration_accepted'] ?? 0) === 1;

        $pdf->checkboxes(
            ['Declaration accepted by the BC Agent'],
            $accepted ? ['Declaration accepted by the BC Agent'] : [],
            1
        );

        if ($accepted && $visit['declared_at'] !== null) {
            $pdf->keyValues(['Accepted on' => format_datetime((string) $visit['declared_at'])]);
        }
    }

    /**
     * The declaration text, held here so the printed report and the form the
     * supervisor accepted cannot drift apart.
     *
     * @return array<int, string>
     */
    public static function declarationParagraphs(): array
    {
        return [
            'I hereby certify that the information contained in this report has been collected and verified during '
                . 'my personal physical field visit through direct interaction with the borrower and/or other '
                . 'reliable local sources, wherever applicable. The details recorded herein represent the factual '
                . 'position observed and verified during the visit and have been documented fairly, accurately, '
                . 'objectively, and in good faith to the best of my knowledge and belief.',
            'I further certify that no information has been intentionally concealed, altered, or misrepresented. '
                . 'The field verification has been conducted strictly in accordance with the applicable Reserve Bank '
                . 'of India (RBI) guidelines, the Bank\'s extant policies, operational instructions, the Fair '
                . 'Practices Code, and the prescribed Code of Conduct governing field verification, customer '
                . 'interaction, and recovery-related activities.',
            'This report is submitted solely for the purpose of assessment, verification, recovery follow-up, '
                . 'and/or renewal processing, as applicable, and shall be subject to verification and acceptance '
                . 'by the Bank.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* 12. Certification                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $visit
     */
    private static function sectionCertification(PdfWriter $pdf, array $visit): void
    {
        $pdf->sectionBand('12', 'Certification');

        $pdf->heading('BC Agent / DRA', 9.0);
        $pdf->keyValues([
            'Name' => (string) $visit['supervisor_name'],
            'BC Code / DRA ID' => self::joinCodes((string) $visit['bc_code'], (string) $visit['dra_id']),
            'Mobile Number' => (string) $visit['supervisor_mobile'],
            'Date' => $visit['submitted_at'] === null ? '' : format_datetime((string) $visit['submitted_at']),
        ]);

        // The Admin/Supervisor who approved the report is its verifier. Until
        // then the block prints empty, exactly as the paper form would be — an
        // unapproved report must not look countersigned.
        $approved = $visit['approved_at'] !== null && ($visit['approver_name'] ?? '') !== '';

        $pdf->heading('Supervisor Verification', 9.0);
        $pdf->keyValues([
            'Name' => $approved ? (string) $visit['approver_name'] : '',
            'Designation' => $approved ? 'Admin / Supervisor' : '',
            'Employee ID / DRA ID' => $approved ? (string) ($visit['approver_employee_code'] ?? '') : '',
            'Date' => $approved ? format_datetime((string) $visit['approved_at']) : '',
        ]);

        // Signed on paper, by hand. The app captures no signature and the
        // template carries ruled boxes, so that is what prints — a report that
        // showed nothing here gave the branch names and dates with nowhere to
        // sign.
        $pdf->signatureLines([
            'Signature — BC Agent / DRA',
            'Signature — Supervisor',
            'Signature — Borrower',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 13. Final report status                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed>|null $case
     */
    private static function sectionFinalStatus(PdfWriter $pdf, ?array $case, string $stream): void
    {
        $pdf->sectionBand('13', 'Final report status');

        $options = $stream === 'krm_ots' ? KrmOts::FINAL_STATUSES : CkccRenewals::FINAL_STATUSES;
        $selected = (string) ($case['final_status'] ?? '');

        $pdf->checkboxes(
            array_values($options),
            $selected === '' ? [] : [$options[$selected] ?? ''],
            3,
            $stream === 'krm_ots' ? 'KRM OTS' : 'CKCC OD-2 Renewal'
        );

        // The closing note is drawn by closingNote() as the template's shaded
        // "Important Note" block. The loose paragraph that used to sit here also
        // listed CKCC OD-2 Renewal on a KRM report, which these two separate
        // reports are specifically meant not to do.
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A stored checklist ("Aadhaar Card, Passbook") as the list of ticked values.
     *
     * @return array<int, string>
     */
    private static function ticked(mixed $value): array
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : (int) $value === 1;
    }

    /**
     * An amount that may legitimately be absent. A blank is not the same
     * statement as zero on a compliance report, so it prints blank.
     */
    private static function optionalMoney(mixed $value): string
    {
        return $value === null || $value === '' ? '' : money((float) $value);
    }

    private static function joinCodes(string $first, string $second): string
    {
        $parts = array_filter([trim($first), trim($second)], static fn (string $v): bool => $v !== '');

        return implode(' / ', $parts);
    }
}
