<?php

declare(strict_types=1);

/**
 * Hindi strings for the web panel.
 *
 * Banking terms that staff actually use in the branch are kept as they are said
 * aloud rather than translated into unfamiliar Sanskritised forms — a clerk looks
 * for "ओटीएस" and "एनपीए", not a literal rendering nobody would recognise. Codes
 * that appear on paperwork (KRM OTS, CKCC OD-2, PTP) stay in Latin script for the
 * same reason: they must match the forms and spreadsheets on the desk.
 *
 * Any key missing here falls back to the English string in en.php, so this file
 * can grow screen by screen without ever leaving a blank label on a page.
 */
return [
    /* Language switcher -------------------------------------------------- */
    'locale.label' => 'भाषा',
    'locale.switch' => 'भाषा बदलें',
    'locale.changed' => 'भाषा :language कर दी गई।',

    /* Shared actions ----------------------------------------------------- */
    'action.save' => 'सहेजें',
    'action.cancel' => 'रद्द करें',
    'action.search' => 'खोजें',
    'action.filter' => 'छाँटें',
    'action.reset' => 'रीसेट',
    'action.back' => 'वापस',
    'action.add' => 'जोड़ें',
    'action.edit' => 'बदलें',
    'action.delete' => 'हटाएँ',
    'action.view' => 'देखें',
    'action.export' => 'एक्सपोर्ट',
    'action.download' => 'डाउनलोड',
    'action.print' => 'प्रिंट',
    'action.submit' => 'जमा करें',
    'action.close' => 'बंद करें',
    'action.confirm' => 'पुष्टि करें',
    'action.upload' => 'अपलोड',
    'action.refresh' => 'ताज़ा करें',

    /* Shared words ------------------------------------------------------- */
    'common.yes' => 'हाँ',
    'common.no' => 'नहीं',
    'common.all' => 'सभी',
    'common.none' => 'कोई नहीं',
    'common.total' => 'कुल',
    'common.actions' => 'कार्रवाई',
    'common.status' => 'स्थिति',
    'common.date' => 'दिनांक',
    'common.from' => 'से',
    'common.to' => 'तक',
    'common.branch' => 'शाखा',
    'common.no_records' => 'कोई रिकॉर्ड नहीं मिला।',
    'common.loading' => 'लोड हो रहा है…',
    'common.optional' => 'वैकल्पिक',
    'common.required' => 'आवश्यक',

    /* Sign-in and account ------------------------------------------------ */
    'auth.sign_in' => 'साइन इन',
    'auth.sign_in_and_get_code' => 'साइन इन कर कोड पाएँ',
    'auth.sign_out' => 'साइन आउट',
    'auth.intro' => 'एडमिन/सुपरवाइज़र और शाखा प्रबंधक खाते।',
    'auth.login_field' => 'ईमेल, यूज़रनेम, कर्मचारी कोड या BCBF कोड',
    'auth.password' => 'पासवर्ड',
    'auth.app_only_hint' => 'बीसी सुपरवाइज़र LRMS एंड्रॉइड ऐप में काम करते हैं —',
    'auth.app_only_link' => 'जानकारी यहाँ',
    'auth.footer_notice' => 'केवल अधिकृत उपयोगकर्ता। हर गतिविधि दर्ज की जाती है।',
    'auth.change_password' => 'पासवर्ड बदलें',

    /* The notice a BC Supervisor sees if they try the web panel ---------- */
    'app_only.title' => 'LRMS एंड्रॉइड ऐप इस्तेमाल करें',
    'app_only.intro' => 'बीसी सुपरवाइज़र का फ़ील्ड कार्य — ग्राहक विज़िट, जीपीएस, फ़ोटो, वसूली, PTP, '
        . 'हाज़िरी और दैनिक रिपोर्ट — LRMS एंड्रॉइड ऐप में होता है। ऐप बिना इंटरनेट भी चलता है और '
        . 'कनेक्शन आने पर डेटा अपने-आप भेज देता है।',
    'app_only.device_note' => 'ऐप में उसी BCBF कोड और पासवर्ड से साइन इन करें। आपका खाता एक ही डिवाइस से '
        . 'जुड़ा रहता है; नया फ़ोन लेने पर अपने एडमिन/सुपरवाइज़र से डिवाइस बाइंडिंग रीसेट करवाएँ।',
    'app_only.back' => 'साइन इन पर वापस',

    /* Navigation --------------------------------------------------------- */
    'nav.section.loan_book' => 'ऋण खाता-बही',
    'nav.section.field_work' => 'फ़ील्ड कार्य',
    'nav.section.organisation' => 'संगठन',
    'nav.section.reporting' => 'रिपोर्टिंग',
    'nav.section.configuration' => 'कॉन्फ़िगरेशन',
    'nav.section.account' => 'खाता',

    'nav.dashboard' => 'डैशबोर्ड',
    'nav.loan_accounts' => 'ऋण खाते',
    'nav.accounts' => 'खाते',
    'nav.excel_import' => 'एक्सेल इम्पोर्ट',
    'nav.allocation' => 'आवंटन',
    'nav.customer_visits' => 'ग्राहक विज़िट',
    'nav.visits' => 'विज़िट',
    'nav.bc_inspections' => 'बीसी निरीक्षण',
    'nav.live_monitoring' => 'लाइव निगरानी',
    'nav.krm_ots' => 'KRM OTS',
    'nav.ckcc' => 'CKCC OD-2',
    'nav.sss' => 'SSS नामांकन',
    'nav.sss_targets' => 'SSS लक्ष्य',
    'nav.branches' => 'शाखाएँ',
    'nav.branch_managers' => 'शाखा प्रबंधक',
    'nav.bc_supervisors' => 'बीसी सुपरवाइज़र',
    'nav.targets' => 'लक्ष्य',
    'nav.reports' => 'रिपोर्ट',
    'nav.report_deadline' => 'रिपोर्ट की समय-सीमा',
    'nav.visit_form_builder' => 'विज़िट फ़ॉर्म बिल्डर',
    'nav.inspection_form_builder' => 'निरीक्षण फ़ॉर्म बिल्डर',
    'nav.settings' => 'सेटिंग्स',
    'nav.audit_log' => 'ऑडिट लॉग',
    'nav.notifications' => 'सूचनाएँ',
    'nav.recovery_ptp' => 'वसूली और PTP',
    'nav.pending_accounts' => 'लंबित खाते',
    'nav.performance' => 'प्रदर्शन',

    /* Roles -------------------------------------------------------------- */
    'role.admin' => 'एडमिन / सुपरवाइज़र',
    'role.branch_manager' => 'शाखा प्रबंधक',
    'role.bc_supervisor' => 'बीसी सुपरवाइज़र',

    /* Top bar ------------------------------------------------------------ */
    'topbar.menu' => 'मेन्यू',
    'topbar.deadline_passed' => 'समय-सीमा बीत चुकी',
    'topbar.report_deadline' => 'रिपोर्ट समय-सीमा :time',
    'topbar.non_working_day' => 'अवकाश का दिन',
];
