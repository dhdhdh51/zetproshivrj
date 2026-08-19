<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Creating and updating loan accounts.
 *
 * Extracted from the Excel importer once accounts could also be entered by hand.
 * Both routes have to produce an identical row — the same defaults, the same
 * duplicate handling — or an account typed in by a clerk would behave differently
 * from the same account loaded from a sheet: reports would disagree, allocation
 * would skip it, and the cause would be invisible.
 *
 * The defaults on insert (`status`, `recovery_status`, `loan_category`) live here
 * and nowhere else.
 */
final class LoanAccounts
{
    /**
     * Insert or refresh an account, matched on its account number.
     *
     * An existing account is updated rather than duplicated, keeping its id so
     * visits, allocation history and recovery entries stay attached. Re-uploading
     * last month's sheet must not orphan a year of field work.
     *
     * `$data` uses the keys from SystemFields; anything absent falls back to the
     * same value the importer would have used.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, created: bool}
     */
    public static function upsert(array $data, int $branchId, ?int $importId = null): array
    {
        $accountNumber = (string) $data['account_number'];

        $existing = Database::selectOne(
            'SELECT id FROM loan_accounts WHERE account_number = :n LIMIT 1',
            ['n' => $accountNumber]
        );

        $payload = [
            'cif' => $data['cif'] ?? null,
            'borrower_name' => $data['borrower_name'],
            'father_name' => $data['father_name'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'alternate_mobile' => $data['alternate_mobile'] ?? null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'aadhaar_last4' => $data['aadhaar_last4'] ?? null,
            'pan_number' => $data['pan_number'] ?? null,
            'village' => $data['village'] ?? null,
            'gram_panchayat' => $data['gram_panchayat'] ?? null,
            'tehsil' => $data['tehsil'] ?? null,
            'district' => $data['district'] ?? null,
            'state' => $data['state'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'address' => $data['address'] ?? null,
            'branch_id' => $branchId,
            'branch_code_raw' => $data['branch_code_raw'] ?? null,
            'bc_code_raw' => $data['bc_code_raw'] ?? null,
            'loan_type' => $data['loan_type'] ?? null,
            'sanction_date' => $data['sanction_date'] ?? null,
            'npa_date' => $data['npa_date'] ?? null,
            'limit_amount' => $data['limit_amount'] ?? 0.0,
            'drawing_power' => $data['drawing_power'] ?? null,
            'outstanding' => $data['outstanding'] ?? 0.0,
            'interest_overdue' => $data['interest_overdue'] ?? null,
            'overdue' => $data['overdue'] ?? 0.0,
            'asset_classification' => $data['asset_classification'] ?? null,
            'updated_at' => now(),
        ];

        // Only an import sets this; a hand-entered account keeps it NULL, which is
        // what tells the two apart on the account screen.
        if ($importId !== null) {
            $payload['excel_import_id'] = $importId;
        }

        if ($existing !== null) {
            Database::update('loan_accounts', $payload, 'id = :id', ['id' => (int) $existing['id']]);

            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $id = Database::insert('loan_accounts', array_merge($payload, [
            'account_number' => $accountNumber,
            'status' => 'active',
            'recovery_status' => 'pending',
            'loan_category' => $data['loan_category'] ?? 'general',
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]));

        return ['id' => $id, 'created' => true];
    }

    /** True when an account number is already on the loan book. */
    public static function exists(string $accountNumber): bool
    {
        return Database::selectOne(
            'SELECT id FROM loan_accounts WHERE account_number = :n LIMIT 1',
            ['n' => $accountNumber]
        ) !== null;
    }
}
