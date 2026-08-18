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
            'village' => [
                'label' => 'Village',
                'required' => false,
                'type' => 'string',
                'aliases' => ['village', 'village name', 'vill', 'place', 'gram', 'panchayat', 'locality'],
            ],
            'address' => [
                'label' => 'Address',
                'required' => false,
                'type' => 'string',
                'aliases' => ['address', 'full address', 'residential address', 'addr', 'permanent address',
                    'borrower address', 'address line'],
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
                'label' => 'Limit',
                'required' => false,
                'type' => 'amount',
                'aliases' => ['limit', 'limit amount', 'sanction limit', 'sanctioned limit', 'drawing power',
                    'sanction amount', 'sanctioned amount', 'limit_amt', 'loan amount'],
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
