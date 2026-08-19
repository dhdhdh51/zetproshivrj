<?php

declare(strict_types=1);

namespace App\Services\Excel;

/**
 * The canonical loan-account fields the importer understands, with the header
 * spellings seen in real bank exports.
 *
 * `aliases` drive automatic column matching; `required` drives preview
 * validation. Adding a new recognised spelling is a one line change here.
 */
final class SystemFields
{
    /**
     * @return array<string, array{
     *   label: string,
     *   required: bool,
     *   type: string,
     *   aliases: array<int, string>,
     *   help?: string
     * }>
     */
    public static function all(): array
    {
        return [
            'account_number' => [
                'label' => 'Account Number',
                'required' => true,
                'type' => 'string',
                'aliases' => ['account no', 'a/c no', 'ac no', 'acno', 'account number', 'loan account',
                    'loan a/c no', 'loan account no', 'loan account number', 'account', 'acct no',
                    'a/c number', 'accountno', 'loan acno', 'acc no', 'ac number'],
                'help' => 'Unique per account; used to detect duplicates on re-upload.',
            ],
            'cif' => [
                'label' => 'CIF',
                'required' => false,
                'type' => 'string',
                'aliases' => ['cif', 'cif no', 'cif number', 'cif id', 'customer id', 'cust id', 'cifno'],
            ],
            'borrower_name' => [
                'label' => 'Borrower Name',
                'required' => true,
                'type' => 'string',
                'aliases' => ['borrower name', 'customer name', 'name of borrower', 'borrower', 'name',
                    'cust name', 'customer', 'account holder', 'applicant name', 'borrower_name'],
            ],
            'father_name' => [
                'label' => 'Father Name',
                'required' => false,
                'type' => 'string',
                'aliases' => ["father's name", 'father name', 'fathers name', 'f name', 'father',
                    'guardian name', 'father/husband name', 'father / husband name', 's/o'],
            ],
            'mobile' => [
                'label' => 'Mobile',
                'required' => false,
                'type' => 'mobile',
                'aliases' => ['mobile', 'mobile no', 'mobile number', 'phone', 'phone no', 'contact',
                    'contact no', 'contact number', 'cell', 'mob no', 'mobileno'],
            ],
            'alternate_mobile' => [
                'label' => 'Alternate Mobile',
                'required' => false,
                'type' => 'mobile',
                'aliases' => ['alternate mobile', 'alternate mobile no', 'alt mobile', 'alternate number',
                    'alternate contact', 'second mobile', 'other mobile', 'mobile 2', 'mobile2'],
            ],
            'gender' => [
                'label' => 'Gender',
                'required' => false,
                'type' => 'gender',
                'aliases' => ['gender', 'sex', 'm/f', 'gender (m/f)'],
                'help' => 'Male / Female / Other, also read from M, F, O.',
            ],
            'date_of_birth' => [
                'label' => 'Date of Birth',
                'required' => false,
                'type' => 'date',
                'aliases' => ['date of birth', 'dob', 'birth date', 'd.o.b', 'dob date', 'date_of_birth'],
            ],
            'aadhaar_last4' => [
                'label' => 'Aadhaar (last 4 digits)',
                'required' => false,
                'type' => 'aadhaar4',
                'aliases' => ['aadhaar', 'aadhar', 'aadhaar no', 'aadhar no', 'aadhaar number',
                    'aadhar number', 'aadhaar last 4', 'uid', 'uid no'],
                'help' => 'Only the last four digits are stored, which is all the report prints.',
            ],
            'pan_number' => [
                'label' => 'PAN',
                'required' => false,
                'type' => 'pan',
                'aliases' => ['pan', 'pan no', 'pan number', 'pan card', 'pan card no', 'pancard'],
            ],
            'village' => [
                'label' => 'Village',
                'required' => false,
                'type' => 'string',
                'aliases' => ['village', 'village name', 'vill', 'place', 'locality'],
            ],
            'gram_panchayat' => [
                'label' => 'Gram Panchayat',
                'required' => false,
                'type' => 'string',
                'aliases' => ['gram panchayat', 'panchayat', 'gram', 'gp', 'gram_panchayat'],
            ],
            'tehsil' => [
                'label' => 'Tehsil',
                'required' => false,
                'type' => 'string',
                'aliases' => ['tehsil', 'tahsil', 'taluka', 'taluk', 'block', 'mandal'],
            ],
            'district' => [
                'label' => 'District',
                'required' => false,
                'type' => 'string',
                'aliases' => ['district', 'dist', 'district name', 'dist name'],
            ],
            'state' => [
                'label' => 'State',
                'required' => false,
                'type' => 'string',
                'aliases' => ['state', 'state name', 'st'],
            ],
            'pincode' => [
                'label' => 'PIN Code',
                'required' => false,
                'type' => 'string',
                'aliases' => ['pin code', 'pincode', 'pin', 'postal code', 'zip', 'zip code'],
            ],
            'address' => [
                'label' => 'Address',
                'required' => false,
                'type' => 'string',
                'aliases' => ['address', 'full address', 'residential address', 'addr', 'permanent address',
                    'borrower address', 'address line', 'complete residential address'],
            ],
            'branch_name' => [
                'label' => 'Branch Name',
                'required' => false,
                'type' => 'string',
                'aliases' => ['branch name', 'branch', 'br name', 'branch_name', 'name of branch'],
                'help' => 'Used to resolve the branch when the branch code is missing.',
            ],
            'branch_code' => [
                'label' => 'Branch Code',
                'required' => false,
                'type' => 'string',
                'aliases' => ['branch code', 'br code', 'branch cd', 'branch id', 'sol id', 'solid',
                    'branch_code', 'brcode', 'branch no'],
                'help' => 'Preferred way to resolve the branch.',
            ],
            'loan_type' => [
                'label' => 'Loan Type',
                'required' => false,
                'type' => 'string',
                'aliases' => ['loan type', 'scheme', 'scheme name', 'product', 'product name', 'facility',
                    'loan scheme', 'type of loan', 'loan_type'],
            ],
            'sanction_date' => [
                'label' => 'Sanction Date',
                'required' => false,
                'type' => 'date',
                'aliases' => ['sanction date', 'sanction dt', 'date of sanction', 'sanctioned on',
                    'disbursement date', 'disb date', 'sanction_date', 'sanc date'],
            ],
            'outstanding' => [
                'label' => 'Outstanding',
                'required' => false,
                'type' => 'amount',
                'aliases' => ['outstanding', 'outstanding amt', 'outstanding amount', 'os', 'os amount',
                    'os amt', 'balance', 'principal outstanding', 'total outstanding', 'ledger balance',
                    'outstanding_balance', 'bal'],
            ],
            'interest_overdue' => [
                'label' => 'Interest Overdue',
                'required' => false,
                'type' => 'amount',
                'aliases' => ['interest overdue', 'overdue interest', 'interest due', 'int overdue',
                    'int due', 'unpaid interest', 'interest arrears', 'interest_overdue'],
            ],
            'overdue' => [
                'label' => 'Overdue',
                'required' => false,
                'type' => 'amount',
                'aliases' => ['overdue', 'overdue amt', 'overdue amount', 'od amount', 'od amt', 'od',
                    'arrears', 'arrear amount', 'total overdue', 'irregularity', 'dues'],
            ],
            'npa_date' => [
                'label' => 'NPA Date',
                'required' => false,
                'type' => 'date',
                'aliases' => ['npa date', 'npa dt', 'date of npa', 'npa_date', 'npa since', 'npa on',
                    'classification date', 'doa'],
            ],
            'limit_amount' => [
                'label' => 'Sanction Limit',
                'required' => false,
                'type' => 'amount',
                // "drawing power" deliberately not listed here: it is its own
                // field on the report and a separate column in the loan book.
                'aliases' => ['limit', 'limit amount', 'sanction limit', 'sanctioned limit',
                    'sanction amount', 'sanctioned amount', 'limit_amt', 'loan amount'],
            ],
            'drawing_power' => [
                'label' => 'Drawing Power',
                'required' => false,
                'type' => 'amount',
                'aliases' => ['drawing power', 'dp', 'drawing power amount', 'dp amount', 'drawing_power',
                    'dp limit'],
            ],
            'asset_classification' => [
                'label' => 'Asset Classification',
                'required' => false,
                'type' => 'asset_class',
                'aliases' => ['asset classification', 'asset class', 'classification', 'iracp',
                    'asset category', 'npa classification', 'sma', 'sma category', 'asset_classification'],
                'help' => 'Standard, SMA-0, SMA-1, SMA-2 or NPA.',
            ],
            'bc_code' => [
                'label' => 'BC Code',
                'required' => false,
                'type' => 'string',
                'aliases' => ['bc code', 'bc id', 'bc', 'bc_code', 'bccode', 'business correspondent',
                    'bc name', 'csp code', 'csp id', 'agent code'],
                'help' => 'When present the account is allocated to that BC Supervisor directly.',
            ],
        ];
    }

    /**
     * Storage shape and demo values per field.
     *
     * `maxlength` mirrors the column width in database/schema.sql. It lives here
     * rather than being repeated at each call site so the importer's truncation,
     * the manual entry form's `maxlength` attribute, the validation rules and the
     * downloadable template cannot drift apart — a form that accepts 200
     * characters for a column holding 190 is a silent data-loss bug.
     *
     * `options` are the ENUM values the column accepts, stored value => label.
     *
     * `samples` are three demo rows for the downloadable template, so the file
     * shows the expected *shape* of each column — a date that reads 2019-06-14
     * rather than an empty cell someone has to guess at.
     *
     * @var array<string, array{maxlength?: int, options?: array<string, string>, samples: array<int, string>}>
     */
    private const SHAPE = [
        'account_number' => ['maxlength' => 60, 'samples' => ['SAMPLE-0001', 'SAMPLE-0002', 'SAMPLE-0003']],
        'cif' => ['maxlength' => 60, 'samples' => ['900112233', '900112244', '900112255']],
        'borrower_name' => ['maxlength' => 190, 'samples' => ['Ramesh Kumar', 'Sunita Devi', 'Mohan Lal']],
        'father_name' => ['maxlength' => 190, 'samples' => ['Shyam Lal', 'Ram Prasad', 'Kishan Lal']],
        'mobile' => ['maxlength' => 20, 'samples' => ['9876543210', '9812345678', '9765432109']],
        'alternate_mobile' => ['maxlength' => 20, 'samples' => ['9123456780', '', '9012345678']],
        'gender' => [
            'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
            'samples' => ['Male', 'Female', 'Male'],
        ],
        'date_of_birth' => ['samples' => ['1978-04-12', '1985-11-30', '1969-01-05']],
        'aadhaar_last4' => ['maxlength' => 4, 'samples' => ['4321', '8765', '1290']],
        'pan_number' => ['maxlength' => 12, 'samples' => ['ABCDE1234F', '', 'PQRSX6789L']],
        'village' => ['maxlength' => 160, 'samples' => ['Rampur', 'Bhojpura', 'Kanhaiyapur']],
        'gram_panchayat' => ['maxlength' => 160, 'samples' => ['Rampur GP', 'Bhojpura GP', 'Kanhaiyapur GP']],
        'tehsil' => ['maxlength' => 120, 'samples' => ['Sadar', 'Sadar', 'Kotwali']],
        'district' => ['maxlength' => 120, 'samples' => ['Jaipur', 'Jaipur', 'Ajmer']],
        'state' => ['maxlength' => 120, 'samples' => ['Rajasthan', 'Rajasthan', 'Rajasthan']],
        'pincode' => ['maxlength' => 12, 'samples' => ['302001', '302002', '305001']],
        'address' => [
            'maxlength' => 500,
            'samples' => ['House 12, Rampur, Jaipur', 'Near school, Bhojpura', 'Ward 4, Kanhaiyapur'],
        ],
        // Branch and BC columns are filled from the live database when the
        // template is generated, so the sample file imports without first
        // having to create anything.
        'branch_name' => ['maxlength' => 160, 'samples' => ['', '', '']],
        'branch_code' => ['maxlength' => 60, 'samples' => ['', '', '']],
        'loan_type' => ['maxlength' => 120, 'samples' => ['KCC', 'Crop Loan', 'KCC']],
        'sanction_date' => ['samples' => ['2019-06-14', '2020-02-20', '2018-09-01']],
        'outstanding' => ['samples' => ['145000.00', '98000.50', '210000.00']],
        'interest_overdue' => ['samples' => ['12500.00', '7300.00', '']],
        'overdue' => ['samples' => ['45000.00', '30000.00', '65000.00']],
        'npa_date' => ['samples' => ['2023-03-31', '2023-09-30', '2022-12-31']],
        'limit_amount' => ['samples' => ['150000.00', '100000.00', '250000.00']],
        'drawing_power' => ['samples' => ['140000.00', '', '240000.00']],
        'asset_classification' => [
            'options' => [
                'standard' => 'Standard',
                'sma_0' => 'SMA-0',
                'sma_1' => 'SMA-1',
                'sma_2' => 'SMA-2',
                'npa' => 'NPA',
            ],
            'samples' => ['NPA', 'SMA-2', 'NPA'],
        ],
        'bc_code' => ['maxlength' => 60, 'samples' => ['', '', '']],
    ];

    /** How many demo rows the downloadable template carries. */
    public const SAMPLE_ROWS = 3;

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The column width for a field, or null where the value is not stored as
     * text (dates, amounts).
     */
    public static function maxlength(string $key): ?int
    {
        return self::SHAPE[$key]['maxlength'] ?? null;
    }

    /**
     * The truncation length for a text field, never null.
     *
     * Separate from maxlength() because the importer must always have a number to
     * cut at: a missing entry there would be a TypeError mid-import, halfway
     * through somebody's loan book.
     */
    public static function textLength(string $key, int $default = 255): int
    {
        return self::SHAPE[$key]['maxlength'] ?? $default;
    }

    /**
     * Accepted values for an ENUM-backed field, stored value => label. Empty for
     * everything else.
     *
     * @return array<string, string>
     */
    public static function options(string $key): array
    {
        return self::SHAPE[$key]['options'] ?? [];
    }

    /** The demo value for a field on a given template row. */
    public static function sample(string $key, int $row): string
    {
        return self::SHAPE[$key]['samples'][$row] ?? '';
    }

    /** @return array<int, string> */
    public static function requiredKeys(): array
    {
        $required = [];

        foreach (self::all() as $key => $field) {
            if ($field['required']) {
                $required[] = $key;
            }
        }

        return $required;
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? ucwords(str_replace('_', ' ', $key));
    }

    public static function type(string $key): string
    {
        return self::all()[$key]['type'] ?? 'string';
    }
}
