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

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
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
