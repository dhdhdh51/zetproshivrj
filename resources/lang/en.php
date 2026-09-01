<?php

declare(strict_types=1);

/**
 * English strings for the web panel — the source of truth for translations.
 *
 * Keys are grouped by area with dots. When you add a key here, add it to hi.php
 * too; `tests/test-lang.php` fails when a translation has drifted, and anything
 * still missing falls back to the English text below rather than to a blank.
 *
 * Not covered yet (documented so the gap is not mistaken for a bug):
 *   - the individual admin/manager screens beyond their titles and shared widgets
 *   - the visit and inspection form labels, which admins author in the database
 *   - generated PDFs, which use a Latin-only core font
 */
return [
    /* Language switcher -------------------------------------------------- */
    'locale.label' => 'Language',
    'locale.switch' => 'Change language',
    'locale.changed' => 'Language changed to :language.',

    /* Shared actions ----------------------------------------------------- */
    'action.save' => 'Save',
    'action.cancel' => 'Cancel',
    'action.search' => 'Search',
    'action.filter' => 'Filter',
    'action.reset' => 'Reset',
    'action.back' => 'Back',
    'action.add' => 'Add',
    'action.edit' => 'Edit',
    'action.delete' => 'Delete',
    'action.view' => 'View',
    'action.export' => 'Export',
    'action.download' => 'Download',
    'action.print' => 'Print',
    'action.submit' => 'Submit',
    'action.close' => 'Close',
    'action.confirm' => 'Confirm',
    'action.upload' => 'Upload',
    'action.refresh' => 'Refresh',

    /* Shared words ------------------------------------------------------- */
    'common.yes' => 'Yes',
    'common.no' => 'No',
    'common.all' => 'All',
    'common.none' => 'None',
    'common.total' => 'Total',
    'common.actions' => 'Actions',
    'common.status' => 'Status',
    'common.date' => 'Date',
    'common.from' => 'From',
    'common.to' => 'To',
    'common.branch' => 'Branch',
    'common.no_records' => 'No records found.',
    'common.loading' => 'Loading…',
    'common.optional' => 'optional',
    'common.required' => 'required',

    /* Sign-in and account ------------------------------------------------ */
    'auth.sign_in' => 'Sign in',
    'auth.sign_in_and_get_code' => 'Sign in and get code',
    'auth.sign_out' => 'Sign out',
    'auth.intro' => 'BC Supervisor and Branch Manager accounts.',
    'auth.login_field' => 'Email, username, employee code, BCBF code or mobile number',
    'auth.password' => 'Password',
    'auth.app_only_hint' => 'BCAs work in the LRMS Android app —',
    'auth.app_only_link' => 'details here',
    'auth.footer_notice' => 'Authorised users only. All activity is logged.',
    'auth.change_password' => 'Change password',

    /* The notice a BCA sees if they try the web panel ---------- */
    'app_only.title' => 'Use the LRMS Android app',
    'app_only.intro' => 'BCA field work — customer visits, GPS, photographs, recovery, PTP, '
        . 'attendance and the daily report — is done in the LRMS Android app, which also works offline '
        . 'and syncs when a connection returns.',
    'app_only.device_note' => 'Sign in to the app with the same BCBF code and password. Your account is '
        . 'bound to one device; if you have a new handset, ask your BC Supervisor to reset the device '
        . 'binding.',
    'app_only.back' => 'Back to sign in',

    /* Navigation --------------------------------------------------------- */
    'nav.section.loan_book' => 'Loan book',
    'nav.section.field_work' => 'Field work',
    'nav.section.organisation' => 'Organisation',
    'nav.section.reporting' => 'Reporting',
    'nav.section.configuration' => 'Configuration',
    'nav.section.account' => 'Account',

    'nav.dashboard' => 'Dashboard',
    'nav.loan_accounts' => 'Loan accounts',
    'nav.accounts' => 'Accounts',
    'nav.excel_import' => 'Excel import',
    'nav.allocation' => 'Allocation',
    'nav.customer_visits' => 'Customer visits',
    'nav.visits' => 'Visits',
    'nav.bc_inspections' => 'BC inspections',
    'nav.live_monitoring' => 'Live monitoring',
    'nav.krm_ots' => 'KRM OTS',
    'nav.ckcc' => 'CKCC OD-2',
    'nav.sss' => 'SSS enrolments',
    'nav.sss_targets' => 'SSS targets',
    'nav.branches' => 'Branches',
    'nav.branch_managers' => 'Branch managers',
    'nav.bc_supervisors' => 'BCAs',
    'nav.targets' => 'Targets',
    'nav.reports' => 'Reports',
    'nav.report_deadline' => 'Report deadline',
    'nav.visit_form_builder' => 'Visit form builder',
    'nav.inspection_form_builder' => 'Inspection form builder',
    'nav.settings' => 'Settings',
    'nav.audit_log' => 'Audit log',
    'nav.notifications' => 'Notifications',
    'nav.recovery_ptp' => 'Recovery & PTP',
    'nav.pending_accounts' => 'Pending accounts',
    'nav.performance' => 'Performance',

    /* Roles -------------------------------------------------------------- */
    'role.admin' => 'BC Supervisor',
    'role.branch_manager' => 'Branch Manager',
    'role.bc_supervisor' => 'BCA',

    /* Top bar ------------------------------------------------------------ */
    'topbar.menu' => 'Menu',
    'topbar.deadline_passed' => 'Deadline passed',
    'topbar.report_deadline' => 'Report deadline :time',
    'topbar.non_working_day' => 'Non-working day',
];
