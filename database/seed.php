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
        ['slug' => Auth::ROLE_ADMIN, 'name' => 'BC Supervisor', 'description' => 'Full control. Monitors and inspects BCAs; does not perform customer recovery visits.'],
        ['slug' => Auth::ROLE_MANAGER, 'name' => 'Branch Manager', 'description' => 'Read and report access limited to their own branch.'],
        ['slug' => Auth::ROLE_BC, 'name' => 'BCA', 'description' => 'Business Correspondent Agent. Performs customer recovery visits through the Android app.'],
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
        ['daily_field_report', 'Daily Field Report', 'The BCA day-end submission the report deadline applies to.', 1],
        ['customer_visit', 'Customer Visit Report', 'TYPE A — BCA customer recovery visits.', 0],
        ['bc_inspection', 'BCA Inspection Report', 'TYPE B — BC Supervisor verification of BCA field work.', 0],
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
        ['bc_performance', 'BCA Performance', 'BCA level visits, recovery and inspection outcomes.', 0],
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
            // English by default; each user can switch to Hindi from the top bar
            // and that choice sticks on their browser.
            'default_locale' => 'en',
            'maintenance_mode' => '0',
            'supervisor_offline_minutes' => '15',
        ],
        // The office printed at the foot of the BCA inspection form. It is the client's own
        // letterhead, and it has already moved once, so it is a setting rather than a
        // constant somebody has to be asked to change.
        'office' => [
            'office_name' => 'Central Bank of India — Regional Office, Agra',
            'office_address' => '37/2/4, First Floor, Sanjay Place, Agra',
            'office_phone' => '0562-2521342',
            'office_email' => 'rdagraro@centralbank.bank.in',
            'office_helpline' => '1800 233 4035',
        ],
        'reports' => [
            'report_deadline_time' => '18:00',
            'report_working_days' => '1,2,3,4,5,6',
            'report_reminder_minutes' => '60,30,10',
            'allow_late_submission_requests' => '1',
        ],
        'field' => [
            'min_visit_photos' => '0',
            'min_inspection_photos' => '0',
            'watermark_photos' => '1',
            'payment_modes' => 'UPI,Bank Transfer,Cheque,Other',
            // How far back the app may record SSS enrolments. Wider than a day because
            // the app is offline-first and a supervisor can be out of signal for a while.
            'sss_backdate_days' => '30',
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
 * TYPE A — the customer visit form the BCA fills in the Android app.
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
        'description' => 'Default TYPE A form: BCA customer field visit.',
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
            'Branch, BCA, borrower, father name, address, loan account, loan type, outstanding, overdue and NPA date are filled in by the app.'],
        ['visit_status', 'Visit status', 'dropdown', 0,
            "Customer met\nFamily met\nPhone contact only\nHouse locked\nCustomer not available\nAddress not found\nDeceased\nShifted\nRefused to pay\nOther", null],
        ['customer_available', 'Customer available', 'yes_no', 0, null, null],
        ['family_met', 'Family member met', 'yes_no', 0, null, null],
        ['phone_contact', 'Contacted over phone', 'yes_no', 0, null, null],
        ['house_locked', 'House locked', 'yes_no', 0, null, null],
        ['is_alive', 'Borrower alive', 'yes_no', 0, null, 'Record "No" only when confirmed by the family.'],
        ['current_address', 'Current address', 'textarea', 0, null, 'Fill in when the borrower has shifted.'],
        ['occupation', 'Present occupation', 'text', 0, null, null],
        ['recovery_section', 'Recovery assessment', 'section', 0, null, null],
        ['recovery_possibility', 'Recovery possibility', 'dropdown', 0, "High\nMedium\nLow\nNil", null],
        ['promise_amount', 'Promise amount', 'decimal', 0, null, 'Leave blank when the customer gave no promise.'],
        ['promise_date', 'Promise date', 'date', 0, null, null],
        ['reason', 'Reason for non-payment', 'textarea', 0, null, null],
        ['recommendation', 'BCA recommendation', 'textarea', 0, null, null],
        ['remarks', 'Remarks', 'remarks', 0, null, null],
        ['evidence_section', 'Evidence', 'section', 0, null, null],
        ['gps', 'Visit location', 'gps', 0, null, 'Captured automatically when the visit starts.'],
        ['photo', 'Photographs', 'photo', 0, null, 'Customer / house / shop / land / document.'],
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
 * TYPE B — the BCA inspection form used by the BC Supervisor on the web.
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
        ['customer_available', 'Borrower met', 'yes_no', 0, null, null],
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
        // Reveals when "Other" is chosen, as the reference app does. What is typed
        // here becomes the stored occupation, so the report prints "Occupation as
        // recorded: Tailoring" beside the ticked Other box instead of a bare tick
        // that says nothing.
        ['occupation_other', 'Which other occupation', 'text', 0, null, null,
            ['occupation', 'equals', 'Other']],
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
            ['remarks', 'Observations', 'remarks', 0, null,
                'What was seen and said in the field, in your own words.'],

            ['recommendation_section', '9. Recommendation', 'section', 0, null, null],
        ],
        $streamRecommendation,
        [
            ['recommendation', 'General recommendation', 'textarea', 0, null, null],

            ['evidence_section', '10. Evidence attached', 'section', 0, null, null],
            ['gps', 'GPS location', 'gps', 0, null, 'Captured automatically when the visit starts.'],
            ['photo', 'Photographs', 'photo', 0, null,
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
        ['declaration_accepted', 'I accept the declaration above', 'yes_no', 0, null,
            'The report cannot be submitted without accepting it.'],

        ['certification_section', '12. Certification', 'section', 0, null,
            'Your name, BC code, DRA ID and mobile number are printed from your profile. '
            . 'The BC Supervisor countersigns when the report is approved.'],
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
            ['ots_eligible', 'Eligible for KRM OTS', 'yes_no', 0, null, null],
            ['scheme', 'Applicable scheme', 'dropdown', 0, "KRM OTS\nGeneral OTS\nOther", null],
            ['scheme_other', 'Other scheme', 'text', 0, null, null, ['scheme', 'equals', 'Other']],
            ['ots_amount', 'Proposed settlement', 'decimal', 0, null,
                'Cannot be more than the outstanding balance.'],
            ['borrower_share', 'Borrower\'s share', 'decimal', 0, null, null],
            ['initial_deposit_required', 'Initial deposit required', 'decimal', 0, null, null],
            ['customer_response', 'Customer response', 'dropdown', 0,
                "Agreed for OTS\nRequested Time\nFinancial Difficulty\nRefused OTS\nNot Eligible", null],
            ['promise_date', 'Expected deposit date', 'date', 0, null, null,
                ['customer_response', 'equals', 'Agreed for OTS']],
        ],
        lrms_report_verification_fields(),
        lrms_report_evidence_fields('krm_ots', [
            ['ots_recommendation', 'KRM OTS recommendation', 'dropdown', 0,
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
            ['renewal_eligible', 'Eligible for renewal', 'yes_no', 0, null, null],
            ['renewal_due_bucket', 'Renewal due', 'dropdown', 0,
                "Within 30 Days\nWithin 15 Days\nWithin 7 Days\nOverdue", null],
            ['renewal_due_date', 'Renewal due date', 'date', 0, null, null],
            ['expected_npa_date', 'Expected NPA date', 'date', 0, null, null],
            ['days_remaining', 'Days remaining', 'number', 0, null, null],
            ['kyc_status', 'KYC status', 'dropdown', 0, "Complete\nPending", null],
            ['aadhaar_seeded', 'Aadhaar seeded', 'yes_no', 0, null, null],
            ['mobile_linked', 'Mobile linked', 'yes_no', 0, null, null],
            ['aadhaar_authentication', 'Aadhaar authentication', 'dropdown', 0, "Completed\nPending", null],
            ['renewal_consent', 'Borrower willing to renew', 'yes_no', 0, null, null],
            ['renewal_form_signed', 'Renewal form signed', 'yes_no', 0, null, null,
                ['renewal_consent', 'equals', 'Yes']],
            ['biometrics_completed', 'Biometrics completed', 'yes_no', 0, null, null,
                ['renewal_consent', 'equals', 'Yes']],
        ],
        lrms_report_verification_fields(),
        lrms_report_evidence_fields('ckcc_od2', [
            ['renewal_recommendation', 'CKCC renewal recommendation', 'dropdown', 0,
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

/**
 * TYPE B — the BC Supervisor's inspection of a BCA, in the format the client issued.
 *
 * This replaces an eleven-question form that asked whether the BCA had done a
 * particular customer visit properly. That is not what the Bank's form is for: this one
 * inspects the BC outlet itself — who the agent is, whether they are certified and hold an
 * appointment letter, what is on the walls, how many transactions ran yesterday, which of
 * the 39 services are offered, which registers and equipment exist, what the agent earned
 * over three months, and what the villagers say about them. A visit-quality checklist
 * cannot answer any of that, so the questions are replaced rather than extended.
 *
 * Field types follow the paper. Y/N where the form prints "(Y/N)", the four-point grade at
 * item 24 because the form names those four words, and free text everywhere the form leaves
 * the box open — inventing a dropdown there would force an inspector to pick from options
 * the Bank never wrote.
 *
 * Item numbers are kept in the labels. The inspector is holding the printed form, and a
 * question they cannot find on it is a question they will answer wrongly.
 */
function lrms_seed_inspection_form(): int
{
    // A live database already has the previous form as its default, with real inspections
    // recorded against it. Installing this one there is database/upgrade.php's job, which
    // adds it as a new version instead of rewriting the old one.
    $existing = Database::selectOne('SELECT id FROM inspection_forms WHERE is_default = 1 LIMIT 1');

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $formId = Database::insert('inspection_forms', [
        'name' => 'BCA Inspection',
        'description' => 'TYPE B: the BC Supervisor inspection of a BC outlet and its agent.',
        'version' => 2,
        'is_active' => 1,
        'is_default' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    lrms_insert_inspection_fields($formId, lrms_inspection_fields());

    echo "  inspection_forms: BCA inspection #{$formId} with "
        . count(lrms_inspection_fields()) . " fields\n";

    return $formId;
}

/**
 * The 27 items of the printed form.
 *
 * @return array<int, array{0:string, 1:string, 2:string, 3:int, 4:?string, 5:?string, 6?:array{0:string,1:string,2:string}}>
 */
function lrms_inspection_fields(): array
{
    return [
        /* 1-6. Who the agent is ------------------------------------------------ */
        ['bca_section', '1-6. Business Correspondent Agent', 'section', 0, null, null],
        ['bca_name', '1. Name of Business Correspondent Agent (BCA)', 'text', 0, null, null],
        ['branch_name', '2. Branch name', 'text', 0, null, null],
        ['cbc_name', '3. Name of CBC (Corporate Business Correspondent)', 'text', 0, null, null],
        ['bca_qualification', '4. Qualification of the BCA', 'text', 0, null, null],
        ['bca_age', '5. Age', 'number', 0, null, null],
        ['bca_address_contact', '6. Address with contact number', 'textarea', 0, null, null],

        /* 7-12. Appointment, identity and the area worked ---------------------- */
        ['appointment_section', '7-12. Appointment, identity and area', 'section', 0, null, null],
        ['iibf_certified', '7. BC certification (IIBF)', 'yes_no', 0, null, null],
        ['iibf_certificate_no', 'IIBF certificate number', 'text', 0, null, null,
            ['iibf_certified', 'equals', 'Yes']],
        ['bc_working_since', '8. Working at this BC outlet since', 'date', 0, null, null],
        ['appointment_letter', '9. Appointment letter from the Bank / CBC', 'yes_no', 0, null, null],
        ['identity_card', '10. Identity card available', 'yes_no', 0, null, null],
        ['coordinator_contact', '11. District Coordinator / BC Supervisor, with contact number', 'text', 0, null, null],
        ['ssa_name', '12. SSA / Non-SSA', 'text', 0, null, null],
        ['villages_covered', 'Number of villages covered', 'number', 0, null, null],

        /* 13. What is on display at the outlet --------------------------------- */
        ['outlet_section', '13. Boards and display at the BC point', 'section', 0, null, null],
        ['board_available', '13. Board of the CBC / Bank available', 'yes_no', 0, null, null],
        ['dos_donts_board', "Do's and Don'ts board displayed", 'yes_no', 0, null, null,
            ['board_available', 'equals', 'Yes']],
        ['services_list_displayed', 'List of services offered at the BC point displayed', 'yes_no', 0, null, null,
            ['board_available', 'equals', 'Yes']],
        ['sign_board', 'Sign board at the BC point', 'yes_no', 0, null, null,
            ['board_available', 'equals', 'Yes']],
        ['board_bank_name', 'Bank name shown', 'text', 0, null, null,
            ['board_available', 'equals', 'Yes']],
        ['board_link_branch', 'Link branch name shown', 'text', 0, null, null,
            ['board_available', 'equals', 'Yes']],
        ['board_branch_contact', 'Branch contact number shown', 'text', 0, null, null,
            ['board_available', 'equals', 'Yes']],
        ['outlet_working_hours', 'Business / working hours of the BC outlet', 'text', 0, null, null,
            ['board_available', 'equals', 'Yes']],

        /* 14-16. The business the outlet actually does ------------------------- */
        ['business_section', '14-16. Business at the outlet', 'section', 0, null, null],
        ['transactions_previous_day', '14. Number of transactions on the previous day', 'number', 0, null, null],
        ['transaction_types', 'Kinds of transaction', 'checkbox', 0,
            "Cash Deposit\nCash Payment\nFund Transfer\nBalance Enquiry\nOther", null],
        ['transactions_improvement', 'If fewer than 50, how it will be improved', 'textarea', 0, null,
            'The form asks this only when the previous day was below 50.'],
        ['services_provided_count', '15. Services provided at the BC point, out of the 39', 'number', 0, null, null],
        ['services_provided_list', 'Which services', 'textarea', 0, null, null],
        ['sss_awareness', "16. BC awareness of Social Security Schemes and the Bank's products", 'textarea', 0, null, null],

        /* 17-18. Registers and equipment --------------------------------------- */
        ['facilities_section', '17-18. Mandatory registers and equipment', 'section', 0, null, null],
        ['complaint_register', '17. Complaint box / complaint register', 'yes_no', 0, null, null],
        ['transactions_register', 'Transactions register', 'yes_no', 0, null, null],
        ['visit_register', 'Visit register', 'yes_no', 0, null, null],
        ['other_register', 'Other register', 'text', 0, null, null],
        ['equipment_available', '18. Equipment available', 'checkbox', 0,
            "Laptop / Desktop\nBiometric device\nPIN pad device\nReceipt generating machine\nPrinter", null],

        /* 19. What the agent earned ------------------------------------------- */
        ['remuneration_section', '19. Remuneration earned over the last three months', 'section', 0, null, null],
        ['remuneration_month_1', 'First month', 'text', 0, null, null],
        ['remuneration_amount_1', 'Earned in the first month', 'decimal', 0, null, null],
        ['remuneration_month_2', 'Second month', 'text', 0, null, null],
        ['remuneration_amount_2', 'Earned in the second month', 'decimal', 0, null, null],
        ['remuneration_month_3', 'Third month', 'text', 0, null, null],
        ['remuneration_amount_3', 'Earned in the third month', 'decimal', 0, null, null],

        /* 20-24. What the inspector found ------------------------------------- */
        ['findings_section', '20-24. Findings', 'section', 0, null, null],
        ['villager_feedback', '20. Feedback from villagers about the BC service', 'textarea', 0, null,
            'The form asks for at least one account holder\'s details.'],
        ['working_in_allotted_location', '21. The BC is working in the allotted location', 'yes_no', 0, null, null],
        ['actual_location', 'If not, where are they working', 'text', 0, null, null,
            ['working_in_allotted_location', 'equals', 'No']],
        ['other_information', '22. Other information, if any', 'textarea', 0, null, null],
        ['photo', '23. Photographs / selfie at the BC point', 'photo', 0, null,
            'Uploaded on this screen. Each one is stamped with your position and the time.'],
        ['observation', '24. Observation', 'dropdown', 0,
            "Excellent\nGood\nSatisfactory\nPoor", null],

        /* 25-27. Who did the inspection --------------------------------------- */
        ['official_section', '25-27. Visiting official', 'section', 0, null, null],
        ['visiting_official', '25. Name and contact number of the visiting official', 'text', 0, null, null],
        ['signature_section', '26. Signature of the visiting official', 'section', 0, null,
            'Signed by hand on the printed copy, so the report prints ruled lines for the '
            . 'signature and the date rather than capturing one here.'],
        // The printed form asks for "Other Information (if any)" twice, at 22 and again at
        // 27. Both are kept so the report matches the paper an inspector signs.
        ['other_information_final', '27. Other information, if any', 'textarea', 0, null, null],
    ];
}

/**
 * Insert an inspection form's fields, resolving conditional visibility once every field
 * has an id. Mirrors lrms_insert_form_fields, which does the same for a visit form.
 *
 * @param array<int, array<mixed>> $fields
 * @return array<string, int> field key => id
 */
function lrms_insert_inspection_fields(int $formId, array $fields): array
{
    $ids = [];
    $conditions = [];
    $order = 0;

    foreach ($fields as $field) {
        [$key, $label, $type, $required, $options, $help] = $field;
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

        if (isset($field[6]) && is_array($field[6])) {
            $conditions[$key] = $field[6];
        }
    }

    foreach ($conditions as $key => [$dependsOn, $operator, $value]) {
        if (!isset($ids[$dependsOn])) {
            continue;
        }

        Database::update('inspection_form_fields', [
            'condition_field_id' => $ids[$dependsOn],
            'condition_operator' => $operator,
            'condition_value' => $value,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $ids[$key]]);
    }

    return $ids;
}

/**
 * First BC Supervisor account. The password must be changed at first login.
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
        // Trimmed to the column width: this can arrive from an environment
        // variable, so it is not necessarily something a form has checked.
        'mobile' => ($mobile = trim((string) (getenv('LRMS_ADMIN_MOBILE') ?: ''))) === ''
            ? null
            : mb_substr($mobile, 0, 20),
        'password' => Auth::hashPassword($password),
        'status' => 'active',
        'must_change_password' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo "  users: created BC Supervisor {$email} (password must be changed at first login)\n";
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

    // Two BCAs per branch.
    $bcIds = [];
    $n = 0;

    foreach ($branchIds as $code => $branchId) {
        for ($i = 1; $i <= 2; $i++) {
            $n++;
            $bcCode = sprintf('BC%03d', $n);

            $userId = Database::insert('users', [
                'role_id' => $bcRole,
                'branch_id' => $branchId,
                'name' => 'BCA ' . $n,
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

    /*
     * One submitted inspection.
     *
     * Not decoration: the smoke suite opens the inspection report page and downloads its
     * PDF only when a submitted inspection exists, and none ever did, so both checks
     * reported themselves as skipped and the printed inspection was never generated by
     * any suite. The format could have been broken by anything and nothing would have
     * said so.
     *
     * Written with the same Forms::saveValues the panel uses, so the answers carry the
     * field ids, labels and types a real submission would.
     */
    $adminId = (int) Database::scalar(
        'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = :slug ORDER BY u.id LIMIT 1',
        ['slug' => Auth::ROLE_ADMIN]
    );

    $firstBcCode = (string) array_key_first($bcIds);
    $firstBcId = (int) $bcIds[$firstBcCode];
    $form = App\Services\Forms::defaultForm(App\Services\Forms::KIND_INSPECTION);

    if ($adminId > 0 && $form !== null) {
        $inspectionId = Database::insert('inspections', [
            'uuid' => sprintf('%s-%s', 'demo-inspection', $firstBcCode),
            'admin_user_id' => $adminId,
            'bc_supervisor_id' => $firstBcId,
            'branch_id' => (int) Database::scalar(
                'SELECT branch_id FROM bc_supervisors WHERE id = :id',
                ['id' => $firstBcId]
            ),
            'form_id' => (int) $form['id'],
            'inspection_date' => date('Y-m-d', strtotime('-2 days')),
            'started_at' => now(),
            'submitted_at' => now(),
            'result' => 'work_verified',
            'remarks' => 'Outlet visited, registers seen, board on display.',
            'followup_required' => 0,
            'status' => 'submitted',
            'gps_verified' => 1,
            'photo_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        App\Services\Forms::saveValues(
            App\Services\Forms::KIND_INSPECTION,
            $inspectionId,
            App\Services\Forms::fields(App\Services\Forms::KIND_INSPECTION, (int) $form['id']),
            [
                'bca_name' => 'DEMO BC AGENT',
                'branch_name' => 'Katihar Main',
                'bca_qualification' => 'B.Com',
                'bca_age' => '31',
                'iibf_certified' => 'Yes',
                'iibf_certificate_no' => 'IIBF/DEMO/1001',
                'bc_working_since' => date('Y-m-d', strtotime('-3 years')),
                'appointment_letter' => 'Yes',
                'identity_card' => 'Yes',
                'villages_covered' => '5',
                'board_available' => 'Yes',
                'dos_donts_board' => 'Yes',
                'sign_board' => 'Yes',
                'transactions_previous_day' => '64',
                'transaction_types' => 'Cash Deposit, Cash Payment, Fund Transfer',
                'services_provided_count' => '24',
                'complaint_register' => 'Yes',
                'transactions_register' => 'Yes',
                'visit_register' => 'No',
                'equipment_available' => 'Laptop / Desktop, Biometric device, Printer',
                'remuneration_month_1' => date('F', strtotime('-3 months')),
                'remuneration_amount_1' => '5120.00',
                'villager_feedback' => 'Two account holders confirmed deposits were handled the same day.',
                'working_in_allotted_location' => 'Yes',
                'observation' => 'Good',
                'visiting_official' => 'System Administrator, 9111100000',
            ]
        );

        echo "  demo: 1 submitted BCA inspection on form #{$form['id']}\n";
    }

    echo "  demo: " . count($branchIds) . " branches, " . count($bcIds) . " BCAs\n";
}
