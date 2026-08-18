<?php

declare(strict_types=1);

/**
 * Baseline data every LRMS installation needs: roles, report types, default
 * settings and the two default configurable forms — plus an optional demo data
 * set used by the smoke tests.
 *
 * Loaded by database/migrate.php; safe to re-run.
 */

use App\Core\Auth;
use App\Core\Database;

function lrms_seed(bool $demo = false): void
{
    echo "Seeding baseline data...\n";

    lrms_seed_roles();
    lrms_seed_report_types();
    lrms_seed_settings();
    $visitFormId = lrms_seed_visit_form();
    $inspectionFormId = lrms_seed_inspection_form();

    // The two dedicated work streams each get their own Field Visit Verification
    // Report form. They are separate on purpose: the KRM OTS form carries no
    // renewal fields and the CKCC form carries no settlement fields.
    lrms_seed_krm_ots_form();
    lrms_seed_ckcc_form();

    App\Core\Settings::set('default_visit_form_id', (string) $visitFormId, 'forms');
    App\Core\Settings::set('default_inspection_form_id', (string) $inspectionFormId, 'forms');

    lrms_seed_admin();

    if ($demo) {
        lrms_seed_demo();
    }

    echo "  Seed complete.\n";
}

function lrms_seed_roles(): void
{
    $roles = [
        ['slug' => Auth::ROLE_ADMIN, 'name' => 'Admin / Supervisor', 'description' => 'Full control. Monitors and inspects BC Supervisors; does not perform customer recovery visits.'],
        ['slug' => Auth::ROLE_MANAGER, 'name' => 'Branch Manager', 'description' => 'Read and report access limited to their own branch.'],
        ['slug' => Auth::ROLE_BC, 'name' => 'BC Supervisor', 'description' => 'Field officer. Performs customer recovery visits through the Android app.'],
    ];

    foreach ($roles as $role) {
        if (Database::selectOne('SELECT id FROM roles WHERE slug = :slug', ['slug' => $role['slug']]) !== null) {
            continue;
        }

        Database::insert('roles', $role + ['created_at' => now(), 'updated_at' => now()]);
    }

    echo "  roles: " . Database::scalar('SELECT COUNT(*) FROM roles') . "\n";
}

function lrms_seed_report_types(): void
{
    $types = [
        ['daily_field_report', 'Daily Field Report', 'The BC Supervisor day-end submission the report deadline applies to.', 1],
        ['customer_visit', 'Customer Visit Report', 'TYPE A — BC Supervisor customer recovery visits.', 0],
        ['bc_inspection', 'BC Supervisor Inspection Report', 'TYPE B — Admin/Supervisor verification of BC field work.', 0],
        ['krm_ots', 'KRM OTS Report', 'One Time Settlement tracking.', 0],
        ['ckcc_od2', 'CKCC OD-2 Renewal Report', 'CKCC OD-2 renewal tracking.', 0],
        ['recovery', 'Recovery Report', 'Amounts collected by mode, date, branch and supervisor.', 0],
        ['ptp', 'PTP Report', 'Promise to pay register and outcomes.', 0],
        ['followup', 'Follow-up Report', 'Pending and completed follow-up actions.', 0],
        ['attendance', 'Attendance Report', 'Check in/out, working hours and visits per day.', 0],
        ['gps', 'GPS Report', 'Captured coordinates, accuracy and validation results.', 0],
        ['photo', 'Photo Report', 'Photographic evidence captured in the field.', 0],
        ['target', 'Target Report', 'Target versus achievement with pending and percentage.', 0],
        ['branch_performance', 'Branch Performance', 'Branch level visits, recovery and coverage.', 0],
        ['bc_performance', 'BC Supervisor Performance', 'Supervisor level visits, recovery and inspection outcomes.', 0],
    ];

    $order = 0;

    foreach ($types as [$slug, $name, $description, $isDaily]) {
        $order++;

        if (Database::selectOne('SELECT id FROM report_types WHERE slug = :slug', ['slug' => $slug]) !== null) {
            continue;
        }

        Database::insert('report_types', [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'is_daily' => $isDaily,
            'is_active' => 1,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    echo "  report_types: " . Database::scalar('SELECT COUNT(*) FROM report_types') . "\n";
}

function lrms_seed_settings(): void
{
    $defaults = [
        'general' => [
            'site_name' => 'LRMS',
            'organisation_name' => 'Loan Recovery Management System',
            'maintenance_mode' => '0',
            'supervisor_offline_minutes' => '15',
        ],
        'reports' => [
            'report_deadline_time' => '18:00',
            'report_working_days' => '1,2,3,4,5,6',
            'report_reminder_minutes' => '60,30,10',
            'allow_late_submission_requests' => '1',
        ],
        'field' => [
            'min_visit_photos' => '1',
            'min_inspection_photos' => '1',
            'require_borrower_signature' => '0',
            'watermark_photos' => '1',
            'payment_modes' => 'Cash,Bank Transfer,UPI,Cheque,Other',
        ],
        'gps' => [
            'gps_max_accuracy_metres' => '200',
            'gps_max_drift_metres' => '0',
            'gps_mock_location_allowed' => '0',
        ],
        'security' => [
            'otp_web_login' => '0',
            'otp_app_login' => '0',
            'device_binding' => '1',
            'api_token_ttl_days' => '30',
        ],
    ];

    foreach ($defaults as $group => $values) {
        foreach ($values as $key => $value) {
            $exists = Database::selectOne('SELECT id FROM system_settings WHERE `key` = :key', ['key' => $key]);

            if ($exists !== null) {
                continue;
            }

            Database::insert('system_settings', [
                'key' => $key,
                'value' => $value,
                'group' => $group,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    App\Core\Settings::flush();

    echo "  system_settings: " . Database::scalar('SELECT COUNT(*) FROM system_settings') . "\n";
}

/**
 * TYPE A — the customer visit form the BC Supervisor fills in the Android app.
 * Everything here is editable later from the Visit Form Builder.
 */
function lrms_seed_visit_form(): int
{
    $existing = Database::selectOne(
        "SELECT id FROM visit_forms WHERE visit_type = 'customer' AND is_default = 1 LIMIT 1"
    );

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $formId = Database::insert('visit_forms', [
        'name' => 'Customer Recovery Visit',
        'description' => 'Default TYPE A form: BC Supervisor customer field visit.',
        'visit_type' => 'customer',
        'version' => 1,
        'is_active' => 1,
        'is_default' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // [key, label, type, required, options, help]
    $fields = [
        ['account_context', 'Account details (auto-filled from the allocation)', 'section', 0, null,
            'Branch, BC Supervisor, borrower, father name, address, loan account, loan type, outstanding, overdue and NPA date are filled in by the app.'],
        ['visit_status', 'Visit status', 'dropdown', 1,
            "Customer met\nFamily met\nPhone contact only\nHouse locked\nCustomer not available\nAddress not found\nDeceased\nShifted\nRefused to pay\nOther", null],
        ['customer_available', 'Customer available', 'yes_no', 1, null, null],
        ['family_met', 'Family member met', 'yes_no', 0, null, null],
        ['phone_contact', 'Contacted over phone', 'yes_no', 0, null, null],
        ['house_locked', 'House locked', 'yes_no', 0, null, null],
        ['is_alive', 'Borrower alive', 'yes_no', 0, null, 'Record "No" only when confirmed by the family.'],
        ['current_address', 'Current address', 'textarea', 0, null, 'Fill in when the borrower has shifted.'],
        ['occupation', 'Present occupation', 'text', 0, null, null],
        ['recovery_section', 'Recovery assessment', 'section', 0, null, null],
        ['recovery_possibility', 'Recovery possibility', 'dropdown', 1, "High\nMedium\nLow\nNil", null],
        ['promise_amount', 'Promise amount', 'decimal', 0, null, 'Leave blank when the customer gave no promise.'],
        ['promise_date', 'Promise date', 'date', 0, null, null],
        ['reason', 'Reason for non-payment', 'textarea', 0, null, null],
        ['recommendation', 'BC Supervisor recommendation', 'textarea', 0, null, null],
        ['remarks', 'Remarks', 'remarks', 1, null, null],
        ['evidence_section', 'Evidence', 'section', 0, null, null],
        ['gps', 'Visit location', 'gps', 1, null, 'Captured automatically when the visit starts.'],
        ['photo', 'Photographs', 'photo', 1, null, 'Customer / house / shop / land / document.'],
        ['borrower_signature', 'Borrower signature', 'signature', 0, null, null],
        ['supervisor_signature', 'BC Supervisor signature', 'signature', 0, null, null],
    ];

    $ids = [];
    $order = 0;

    foreach ($fields as [$key, $label, $type, $required, $options, $help]) {
        $order += 10;
        $ids[$key] = Database::insert('visit_form_fields', [
            'form_id' => $formId,
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'options' => $options,
            'help_text' => $help,
            'is_required' => $required,
            'sort_order' => $order,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Conditional fields: promise details only make sense once someone was met.
    foreach (['promise_amount', 'promise_date'] as $key) {
        Database::update('visit_form_fields', [
            'condition_field_id' => $ids['customer_available'],
            'condition_operator' => 'equals',
            'condition_value' => 'Yes',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $ids[$key]]);
    }

    Database::update('visit_form_fields', [
        'condition_field_id' => $ids['customer_available'],
        'condition_operator' => 'equals',
        'condition_value' => 'No',
        'updated_at' => now(),
    ], 'id = :id', ['id' => $ids['current_address']]);

    echo "  visit_forms: default customer visit form #{$formId} with " . count($fields) . " fields\n";

    return $formId;
}

/**
 * TYPE B — the BC Supervisor inspection form used by Admin/Supervisor on the web.
 */
/* -------------------------------------------------------------------------- */
/* Field Visit Verification Report forms (KRM OTS and CKCC OD-2)              */
/* -------------------------------------------------------------------------- */

/**
 * The sections both work-stream reports share: 1-3 (auto-filled context),
 * 6 (physical verification), 7 (documents), 8 (observations), 9 (general
 * recommendation), 10 (evidence), 11 (declaration) and 12 (certification).
 *
 * Defined once so the two forms cannot drift apart. `$stream` decides the two
 * checklist entries that belong to one stream only — the OTS consent letter for
 * KRM OTS, the renewal form for CKCC — which is what keeps the reports separate.
 *
 * Field keys deliberately match the `visits` table columns, so the answers land
 * in typed columns and not only in the form-values table.
 *
 * @param 'krm_ots'|'ckcc_od2' $stream
 * @return array<int, array{0:string, 1:string, 2:string, 3:int, 4:?string, 5:?string, 6?:array{0:string,1:string,2:string}}>
 */
function lrms_report_context_fields(): array
{
    return [
        ['case_context', '1-3. General information, borrower and loan account', 'section', 0, null,
            'Visit date and time, branch, regional office, zone, SP/CBC name, BC Agent name, BC code, DRA ID, '
            . 'district, village and GPS are recorded automatically. Borrower name, father/husband name, gender, '
            . 'date of birth, mobile, Aadhaar (last 4), PAN, full address, loan account number, CIF, loan type, '
            . 'sanction date and limit, drawing power, outstanding, interest overdue, overdue, NPA date and asset '
            . 'classification come from the loan book. Correct anything that is wrong in the field below.'],
        ['alternate_mobile', 'Alternate mobile (if found in the field)', 'text', 0, null, null],
    ];
}

/**
 * @return array<int, array<mixed>>
 */
function lrms_report_verification_fields(): array
{
    return [
        ['verification_section', '6. Physical verification', 'section', 0, null, null],
        ['customer_available', 'Borrower met', 'yes_no', 1, null, null],
        ['family_met', 'Family member met', 'yes_no', 0, null, null],
        ['house_locked', 'House locked', 'yes_no', 0, null, null],
        ['is_alive', 'Borrower alive', 'yes_no', 0, null, 'Record "No" only when confirmed by the family.'],
        ['address_shifted', 'Current address — borrower has shifted', 'yes_no', 0, null,
            'Answer "No" when the borrower is still at the recorded address.'],
        ['current_address', 'New address', 'textarea', 0, null, null,
            ['address_shifted', 'equals', 'Yes']],
        ['phone_contact', 'Mobile contacted', 'yes_no', 0, null, null],
        ['residence_verified', 'Residence verification confirmed', 'yes_no', 0, null, null],
        ['neighbour_verified', 'Neighbour verification conducted', 'yes_no', 0, null, null],
        ['occupation', 'Current occupation', 'dropdown', 0,
            "Agriculture\nDairy\nBusiness\nLabour\nService\nOther", null],
    ];
}

/**
 * `$streamRecommendation` is the stream's own section 9 dropdown, which must sit
 * with the general recommendation rather than after the evidence checklist.
 *
 * @param 'krm_ots'|'ckcc_od2' $stream
 * @param array<int, array<mixed>> $streamRecommendation
 * @return array<int, array<mixed>>
 */
function lrms_report_evidence_fields(string $stream, array $streamRecommendation): array
{
    // The one entry in each checklist that belongs to this stream only. This is
    // what keeps a KRM OTS report free of renewal wording and the reverse.
    $streamDocument = $stream === 'krm_ots' ? 'OTS Consent Letter' : 'Renewal Form';
    $streamEvidence = $stream === 'krm_ots' ? 'OTS Consent' : 'Renewal Form';

    return array_merge(
        [
            ['documents_section', '7. Documents verified', 'section', 0, null, null],
            ['documents_verified', 'Documents verified', 'checkbox', 0,
                "Aadhaar Card\nPAN Card\nPassbook\nLand Record\nKhatauni\nElectricity Bill\nPhotograph\n"
                . "Mobile Verified\n" . $streamDocument . "\nOther", 'Tick every document actually seen.'],
            ['documents_other', 'Other document', 'text', 0, null, null,
                ['documents_verified', 'contains', 'Other']],

            ['observations_section', '8. BC Agent observations', 'section', 0, null, null],
            ['remarks', 'Observations', 'remarks', 1, null,
                'What was seen and said in the field, in your own words.'],

            ['recommendation_section', '9. Recommendation', 'section', 0, null, null],
        ],
        $streamRecommendation,
        [
            ['recommendation', 'General recommendation', 'textarea', 0, null, null],

            ['evidence_section', '10. Evidence attached', 'section', 0, null, null],
            ['gps', 'GPS location', 'gps', 1, null, 'Captured automatically when the visit starts.'],
            ['photo', 'Photographs', 'photo', 1, null,
                'Borrower, house, land, Aadhaar copy, passbook copy as applicable.'],
            ['evidence_attached', 'Evidence attached', 'checkbox', 0,
                "Borrower Photograph\nHouse Photograph\nLand Photograph\nAadhaar Copy\nPassbook Copy\n"
                . "GPS Location\n" . $streamEvidence . "\nOther", null],
            ['evidence_other', 'Other evidence', 'text', 0, null, null,
                ['evidence_attached', 'contains', 'Other']],
        ]
    );
}

/**
 * Sections 11 and 12. The declaration is the RBI / Fair Practices Code
 * certification the BC Agent makes; the report is refused unless it is accepted.
 *
 * @return array<int, array<mixed>>
 */
function lrms_report_declaration_fields(): array
{
    return [
        ['declaration_section', '11. Declaration', 'section', 0, null, lrms_report_declaration_text()],
        ['declaration_accepted', 'I accept the declaration above', 'yes_no', 1, null,
            'The report cannot be submitted without accepting it.'],

        ['certification_section', '12. Certification', 'section', 0, null,
            'Your name, BC code, DRA ID and mobile number are printed from your profile. '
            . 'The Admin/Supervisor countersigns when the report is approved.'],
        ['borrower_signature', 'Borrower signature', 'signature', 0, null, null],
        ['supervisor_signature', 'BC Agent signature', 'signature', 0, null, null],
    ];
}

/**
 * The declaration printed in section 11, held in one place because it is a
 * compliance text: the web form, the Android form and the PDF must all show the
 * same words.
 */
function lrms_report_declaration_text(): string
{
    return 'I hereby certify that the information contained in this report has been collected and verified during '
        . 'my personal physical field visit through direct interaction with the borrower and/or other reliable local '
        . 'sources, wherever applicable. The details recorded herein represent the factual position observed and '
        . 'verified during the visit and have been documented fairly, accurately, objectively, and in good faith to '
        . 'the best of my knowledge and belief. '
        . 'I further certify that no information has been intentionally concealed, altered, or misrepresented. The '
        . 'field verification has been conducted strictly in accordance with the applicable Reserve Bank of India '
        . '(RBI) guidelines, the Bank\'s extant policies, operational instructions, the Fair Practices Code, and the '
        . 'prescribed Code of Conduct governing field verification, customer interaction, and recovery-related '
        . 'activities. '
        . 'This report is submitted solely for the purpose of assessment, verification, recovery follow-up, and/or '
        . 'renewal processing, as applicable, and shall be subject to verification and acceptance by the Bank.';
}

/**
 * Insert a form's fields, resolving conditional visibility once every field has
 * an id.
 *
 * @param array<int, array<mixed>> $fields
 * @return array<string, int> field key => id
 */
function lrms_insert_form_fields(int $formId, array $fields): array
{
    $ids = [];
    $conditions = [];
    $order = 0;

    foreach ($fields as $field) {
        [$key, $label, $type, $required, $options, $help] = $field;
        $order += 10;

        $ids[$key] = Database::insert('visit_form_fields', [
            'form_id' => $formId,
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'options' => $options,
            'help_text' => $help,
            'is_required' => $required,
            'sort_order' => $order,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (isset($field[6]) && is_array($field[6])) {
            $conditions[$key] = $field[6];
        }
    }

    foreach ($conditions as $key => [$dependsOn, $operator, $value]) {
        if (!isset($ids[$dependsOn])) {
            continue;
        }

        Database::update('visit_form_fields', [
            'condition_field_id' => $ids[$dependsOn],
            'condition_operator' => $operator,
            'condition_value' => $value,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $ids[$key]]);
    }

    return $ids;
}

/**
 * KRM OTS Field Visit Verification Report: the shared sections plus section 4.
 * Carries no CKCC OD-2 renewal fields.
 */
function lrms_seed_krm_ots_form(): int
{
    $existing = Database::selectOne(
        "SELECT id FROM visit_forms WHERE visit_type = 'krm_ots' AND is_default = 1 LIMIT 1"
    );

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $formId = Database::insert('visit_forms', [
        'name' => 'KRM OTS Field Visit Verification Report',
        'description' => 'One Time Settlement field verification. RBI guidelines and Code of Conduct compliant format.',
        'visit_type' => 'krm_ots',
        'version' => 1,
        'is_active' => 1,
        'is_default' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fields = array_merge(
        lrms_report_context_fields(),
        [
            ['ots_section', '4. KRM OTS details', 'section', 0, null, null],
            ['ots_eligible', 'Eligible for KRM OTS', 'yes_no', 1, null, null],
            ['scheme', 'Applicable scheme', 'dropdown', 0, "KRM OTS\nGeneral OTS\nOther", null],
            ['scheme_other', 'Other scheme', 'text', 0, null, null, ['scheme', 'equals', 'Other']],
            ['ots_amount', 'Proposed settlement', 'decimal', 0, null,
                'Cannot be more than the outstanding balance.'],
            ['borrower_share', 'Borrower\'s share', 'decimal', 0, null, null],
            ['initial_deposit_required', 'Initial deposit required', 'decimal', 0, null, null],
            ['customer_response', 'Customer response', 'dropdown', 1,
                "Agreed for OTS\nRequested Time\nFinancial Difficulty\nRefused OTS\nNot Eligible", null],
            ['promise_date', 'Expected deposit date', 'date', 0, null, null,
                ['customer_response', 'equals', 'Agreed for OTS']],
        ],
        lrms_report_verification_fields(),
        lrms_report_evidence_fields('krm_ots', [
            ['ots_recommendation', 'KRM OTS recommendation', 'dropdown', 1,
                "OTS Proposal Recommended\nFollow-up Required\nCustomer Refused\nNot Eligible", null],
        ]),
        lrms_report_declaration_fields(),
        [
            ['status_section', '13. Final report status', 'section', 0, null, null],
            ['ots_final_status', 'Final status', 'dropdown', 0,
                "Customer Contacted\nCustomer Verified\nOTS Accepted\nOTS Rejected\nInitial Deposit Received\n"
                . "OTS Closed\nFollow-up Required", null],
        ]
    );

    lrms_insert_form_fields($formId, $fields);

    echo "  visit_forms: KRM OTS report form #{$formId} with " . count($fields) . " fields\n";

    return $formId;
}

/**
 * CKCC OD-2 Renewal Field Visit Verification Report: the shared sections plus
 * section 5. Carries no KRM OTS settlement fields.
 */
function lrms_seed_ckcc_form(): int
{
    $existing = Database::selectOne(
        "SELECT id FROM visit_forms WHERE visit_type = 'ckcc_od2' AND is_default = 1 LIMIT 1"
    );

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $formId = Database::insert('visit_forms', [
        'name' => 'CKCC OD-2 Renewal Field Visit Verification Report',
        'description' => 'CKCC OD-2 renewal field verification. RBI guidelines and Code of Conduct compliant format.',
        'visit_type' => 'ckcc_od2',
        'version' => 1,
        'is_active' => 1,
        'is_default' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fields = array_merge(
        lrms_report_context_fields(),
        [
            ['renewal_section', '5. CKCC OD-2 renewal details', 'section', 0, null, null],
            ['renewal_eligible', 'Eligible for renewal', 'yes_no', 1, null, null],
            ['renewal_due_bucket', 'Renewal due', 'dropdown', 0,
                "Within 30 Days\nWithin 15 Days\nWithin 7 Days\nOverdue", null],
            ['renewal_due_date', 'Renewal due date', 'date', 0, null, null],
            ['expected_npa_date', 'Expected NPA date', 'date', 0, null, null],
            ['days_remaining', 'Days remaining', 'number', 0, null, null],
            ['kyc_status', 'KYC status', 'dropdown', 0, "Complete\nPending", null],
            ['aadhaar_seeded', 'Aadhaar seeded', 'yes_no', 0, null, null],
            ['mobile_linked', 'Mobile linked', 'yes_no', 0, null, null],
            ['aadhaar_authentication', 'Aadhaar authentication', 'dropdown', 0, "Completed\nPending", null],
            ['renewal_consent', 'Borrower willing to renew', 'yes_no', 1, null, null],
            ['renewal_form_signed', 'Renewal form signed', 'yes_no', 0, null, null,
                ['renewal_consent', 'equals', 'Yes']],
            ['biometrics_completed', 'Biometrics completed', 'yes_no', 0, null, null,
                ['renewal_consent', 'equals', 'Yes']],
        ],
        lrms_report_verification_fields(),
        lrms_report_evidence_fields('ckcc_od2', [
            ['renewal_recommendation', 'CKCC renewal recommendation', 'dropdown', 1,
                "Renewal Immediately Recommended\nDocuments Complete\nPending Documents\n"
                . "Customer Not Interested\nBranch Follow-up Required", null],
        ]),
        lrms_report_declaration_fields(),
        [
            ['status_section', '13. Final report status', 'section', 0, null, null],
            ['renewal_final_status', 'Final status', 'dropdown', 0,
                "Customer Contacted\nCustomer Verified\nDocuments Collected\nRenewal Submitted\n"
                . "Renewal Approved\nPending at Branch\nAccount Became NPA\nFollow-up Required", null],
        ]
    );

    lrms_insert_form_fields($formId, $fields);

    echo "  visit_forms: CKCC OD-2 report form #{$formId} with " . count($fields) . " fields\n";

    return $formId;
}

function lrms_seed_inspection_form(): int
{
    $existing = Database::selectOne('SELECT id FROM inspection_forms WHERE is_default = 1 LIMIT 1');

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $formId = Database::insert('inspection_forms', [
        'name' => 'BC Supervisor Field Work Inspection',
        'description' => 'Default TYPE B form: Admin/Supervisor verification that the BC Supervisor performed the allocated field work correctly.',
        'version' => 1,
        'is_active' => 1,
        'is_default' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fields = [
        ['bc_visited_customer', 'Did the BC Supervisor visit the customer?', 'yes_no', 1, null, null],
        ['customer_available', 'Was the customer available?', 'yes_no', 1, null, null],
        ['customer_contacted', 'Was the customer contacted?', 'yes_no', 1, null, null],
        ['location_correct', 'Was the location correct?', 'yes_no', 1, null, 'Compare with the address on the account.'],
        ['gps_verified', 'Was the visit GPS verified?', 'yes_no', 1, null, 'The system shows the distance between your point and the BC Supervisor point.'],
        ['photos_taken', 'Were the required photographs taken?', 'yes_no', 1, null, null],
        ['information_correct', 'Was the information recorded correctly?', 'yes_no', 1, null, null],
        ['recovery_recorded_correctly', 'Was recovery / promise information correctly recorded?', 'yes_no', 1, null, null],
        ['customer_confirmation', 'What did the customer confirm?', 'dropdown', 0,
            "Confirmed the visit\nDenied the visit\nCould not recall\nCustomer not available", null],
        ['followup_required', 'Is follow-up required?', 'yes_no', 1, null, null],
        ['inspector_remarks', 'Inspector remarks', 'remarks', 1, null, null],
    ];

    $order = 0;
    $ids = [];

    foreach ($fields as [$key, $label, $type, $required, $options, $help]) {
        $order += 10;
        $ids[$key] = Database::insert('inspection_form_fields', [
            'form_id' => $formId,
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'options' => $options,
            'help_text' => $help,
            'is_required' => $required,
            'sort_order' => $order,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    Database::update('inspection_form_fields', [
        'condition_field_id' => $ids['bc_visited_customer'],
        'condition_operator' => 'equals',
        'condition_value' => 'Yes',
        'updated_at' => now(),
    ], 'id = :id', ['id' => $ids['customer_confirmation']]);

    echo "  inspection_forms: default inspection form #{$formId} with " . count($fields) . " fields\n";

    return $formId;
}

/**
 * First Admin/Supervisor account. The password must be changed at first login.
 * Override with LRMS_ADMIN_EMAIL / LRMS_ADMIN_PASSWORD environment variables.
 */
function lrms_seed_admin(): void
{
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = :slug', ['slug' => Auth::ROLE_ADMIN]);
    $email = getenv('LRMS_ADMIN_EMAIL') ?: 'admin@lrms.local';

    if (Database::selectOne('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null) {
        echo "  users: admin already present ({$email})\n";

        return;
    }

    $password = getenv('LRMS_ADMIN_PASSWORD') ?: 'ChangeMe@123';

    Database::insert('users', [
        'role_id' => $roleId,
        'branch_id' => null,
        'name' => 'System Administrator',
        'email' => $email,
        'username' => 'admin',
        'employee_code' => 'ADM001',
        'mobile' => getenv('LRMS_ADMIN_MOBILE') ?: null,
        'password' => Auth::hashPassword($password),
        'status' => 'active',
        'must_change_password' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo "  users: created Admin/Supervisor {$email} (password must be changed at first login)\n";
}

/**
 * Small but realistic demo data set: branches, managers, supervisors, accounts
 * and allocations. Used by tests/smoke.php.
 */
function lrms_seed_demo(): void
{
    if ((int) Database::scalar('SELECT COUNT(*) FROM branches') > 0) {
        echo "  demo: branches already exist, skipping\n";

        return;
    }

    $managerRole = (int) Database::scalar('SELECT id FROM roles WHERE slug = :s', ['s' => Auth::ROLE_MANAGER]);
    $bcRole = (int) Database::scalar('SELECT id FROM roles WHERE slug = :s', ['s' => Auth::ROLE_BC]);
    $password = Auth::hashPassword('Demo@1234');

    $branches = [
        ['code' => 'BR001', 'name' => 'Katihar Main', 'district' => 'Katihar', 'state' => 'Bihar', 'latitude' => 25.5389, 'longitude' => 87.5719],
        ['code' => 'BR002', 'name' => 'Barsoi', 'district' => 'Katihar', 'state' => 'Bihar', 'latitude' => 25.5833, 'longitude' => 87.9167],
        ['code' => 'BR003', 'name' => 'Manihari', 'district' => 'Katihar', 'state' => 'Bihar', 'latitude' => 25.3417, 'longitude' => 87.6333],
    ];

    $branchIds = [];

    foreach ($branches as $index => $branch) {
        $branchIds[$branch['code']] = Database::insert('branches', $branch + [
            'region' => 'Purnia',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = Database::insert('users', [
            'role_id' => $managerRole,
            'branch_id' => $branchIds[$branch['code']],
            'name' => 'Branch Manager ' . $branch['name'],
            'email' => 'bm' . ($index + 1) . '@lrms.local',
            'username' => 'bm' . ($index + 1),
            'employee_code' => 'BM00' . ($index + 1),
            'mobile' => '90000000' . ($index + 1),
            'password' => $password,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Database::insert('branch_managers', [
            'user_id' => $userId,
            'branch_id' => $branchIds[$branch['code']],
            'designation' => 'Branch Manager',
            'contact_number' => '90000000' . ($index + 1),
            'status' => 'active',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Two BC Supervisors per branch.
    $bcIds = [];
    $n = 0;

    foreach ($branchIds as $code => $branchId) {
        for ($i = 1; $i <= 2; $i++) {
            $n++;
            $bcCode = sprintf('BC%03d', $n);

            $userId = Database::insert('users', [
                'role_id' => $bcRole,
                'branch_id' => $branchId,
                'name' => 'BC Supervisor ' . $n,
                'email' => 'bc' . $n . '@lrms.local',
                'username' => 'bc' . $n,
                'employee_code' => $bcCode,
                'mobile' => '9111100' . sprintf('%03d', $n),
                'password' => $password,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $bcIds[$bcCode] = Database::insert('bc_supervisors', [
                'user_id' => $userId,
                'branch_id' => $branchId,
                'bc_code' => $bcCode,
                'mobile' => '9111100' . sprintf('%03d', $n),
                'village' => 'Village ' . $n,
                'joined_on' => date('Y-m-d', strtotime('-1 year')),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    echo "  demo: " . count($branchIds) . " branches, " . count($bcIds) . " BC Supervisors\n";
}
