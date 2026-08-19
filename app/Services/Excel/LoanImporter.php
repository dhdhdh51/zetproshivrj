<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Allocation;
use App\Services\Audit;
use App\Services\LoanAccounts;
use RuntimeException;

/**
 * The loan-account Excel pipeline: upload → detect → map → preview → import.
 *
 * Nothing is written to `loan_accounts` until the Admin/Supervisor has confirmed
 * the column mapping on the preview screen. The preview and the import share the
 * same row-translation code (`translateRow`), so what the reviewer sees is
 * exactly what gets stored.
 */
final class LoanImporter
{
    private const PREVIEW_ROWS = 50;

    /* ------------------------------------------------------------------ */
    /* Step 1 — upload and detect                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Store the uploaded file and record what we detected in it.
     *
     * @param array{name:string, tmp_name:string, size:int, error:int} $file
     * @return array<string, mixed> the excel_imports row
     */
    public function store(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The file upload failed. Please try again.');
        }

        $originalName = (string) ($file['name'] ?? 'upload');

        if (!SpreadsheetReader::isSupported($originalName)) {
            throw new HttpException(422, 'Upload an .xlsx or .csv file. Legacy .xls must be re-saved first.');
        }

        $directory = storage_path('uploads/imports/' . date('Y-m'));

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The import directory could not be created.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = date('Ymd-His') . '-' . str_random(8) . '.' . $extension;
        $storedPath = $directory . '/' . $storedName;

        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $storedPath)
            : rename($file['tmp_name'], $storedPath);

        if (!$moved) {
            throw new RuntimeException('The uploaded file could not be saved.');
        }

        @chmod($storedPath, 0640);

        $reader = new SpreadsheetReader($storedPath, $originalName);
        $sheets = $reader->sheetNames();
        $sheet = $sheets[0] ?? null;
        $headers = $reader->headers($sheet, 1);

        if ($headers === []) {
            @unlink($storedPath);
            throw new HttpException(422, 'No column headers were found in the first row of the sheet.');
        }

        $matches = ColumnMatcher::match($headers);

        $importId = Database::insert('excel_imports', [
            'user_id' => (int) Auth::id(),
            'original_name' => mb_substr($originalName, 0, 255),
            // Relative to storage/ so the row stays valid if the app moves host.
            'stored_path' => 'uploads/imports/' . date('Y-m') . '/' . $storedName,
            'file_size' => (int) ($file['size'] ?? filesize($storedPath)),
            'sha256' => hash_file('sha256', $storedPath) ?: null,
            'sheet_name' => $sheet,
            'header_row' => 1,
            'detected_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode(ColumnMatcher::toMapping($matches), JSON_UNESCAPED_UNICODE),
            'status' => 'uploaded',
            'total_rows' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Audit::log(Audit::EXCEL_UPLOADED, [
            'entity_type' => 'excel_import',
            'entity_id' => $importId,
            'description' => sprintf('Uploaded %s (%s).', $originalName, self::humanSize((int) $file['size'])),
            'new' => ['sheet' => $sheet, 'columns' => count($headers)],
        ]);

        return $this->find($importId);
    }

    public function find(int $id): array
    {
        $row = Database::selectOne('SELECT * FROM excel_imports WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            throw new HttpException(404, 'Import not found.');
        }

        return $row;
    }

    public function reader(array $import): SpreadsheetReader
    {
        $path = storage_path($import['stored_path']);

        if (!is_file($path)) {
            throw new HttpException(410, 'The uploaded file is no longer available. Please upload it again.');
        }

        return new SpreadsheetReader($path, $import['original_name']);
    }

    /** @return array<int, string> */
    public function headers(array $import): array
    {
        $decoded = json_decode((string) ($import['detected_headers'] ?? '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> */
    public function mapping(array $import): array
    {
        $decoded = json_decode((string) ($import['mapping'] ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /* ------------------------------------------------------------------ */
    /* Step 2 — mapping                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Re-read headers for a different sheet or header row and re-run matching.
     */
    public function redetect(int $importId, ?string $sheet, int $headerRow): array
    {
        $import = $this->find($importId);
        $reader = $this->reader($import);
        $headers = $reader->headers($sheet, max(1, $headerRow));

        if ($headers === []) {
            throw new HttpException(422, 'No headers were found on that row. Check the header row number.');
        }

        $matches = ColumnMatcher::match($headers);

        Database::update('excel_imports', [
            'sheet_name' => $sheet,
            'header_row' => max(1, $headerRow),
            'detected_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode(ColumnMatcher::toMapping($matches), JSON_UNESCAPED_UNICODE),
            'status' => 'uploaded',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        return $this->find($importId);
    }

    /**
     * Persist the mapping the Admin confirmed on screen.
     *
     * @param array<string, string> $mapping system field => header caption
     */
    public function saveMapping(int $importId, array $mapping): array
    {
        $import = $this->find($importId);
        $headers = $this->headers($import);
        $clean = [];

        foreach (SystemFields::keys() as $key) {
            $header = trim((string) ($mapping[$key] ?? ''));

            if ($header === '') {
                continue;
            }

            if (!in_array($header, $headers, true)) {
                throw new HttpException(422, sprintf('Column "%s" is not present in the uploaded sheet.', $header));
            }

            $clean[$key] = $header;
        }

        foreach (SystemFields::requiredKeys() as $required) {
            if (!isset($clean[$required])) {
                throw new HttpException(
                    422,
                    sprintf('%s is required. Choose the Excel column that holds it.', SystemFields::label($required))
                );
            }
        }

        if (!isset($clean['branch_code']) && !isset($clean['branch_name'])) {
            throw new HttpException(
                422,
                'Map either Branch Code or Branch Name so each account can be linked to its branch.'
            );
        }

        Database::update('excel_imports', [
            'mapping' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'status' => 'mapped',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        Audit::log(Audit::EXCEL_MAPPED, [
            'entity_type' => 'excel_import',
            'entity_id' => $importId,
            'description' => sprintf('Column mapping confirmed for %s.', $import['original_name']),
            'new' => $clean,
        ]);

        return $this->find($importId);
    }

    /* ------------------------------------------------------------------ */
    /* Step 3 — preview                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Validate the first N rows and summarise what an import would do.
     *
     * @return array{
     *   headers: array<int,string>,
     *   mapping: array<string,string>,
     *   columns: array<string,int>,
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, int>,
     *   issues: array<int, string>
     * }
     */
    public function preview(int $importId, int $limit = self::PREVIEW_ROWS): array
    {
        $import = $this->find($importId);
        $headers = $this->headers($import);
        $mapping = $this->mapping($import);
        $columns = ColumnMatcher::resolveMapping($mapping, $headers);
        $reader = $this->reader($import);

        $branchIndex = $this->branchIndex();
        $allocation = new Allocation();

        $rows = [];
        $seenAccounts = [];
        $summary = [
            'previewed' => 0,
            'ready' => 0,
            'new' => 0,
            'existing' => 0,
            'duplicate_in_file' => 0,
            'missing_required' => 0,
            'invalid_data' => 0,
            'unknown_branch' => 0,
            'invalid_bc' => 0,
        ];

        foreach ($reader->rows($import['sheet_name'], $limit, (int) $import['header_row']) as $rowNumber => $values) {
            $summary['previewed']++;
            $translated = $this->translateRow($values, $columns, $branchIndex, $allocation, $seenAccounts, false);

            $accountNumber = $translated['data']['account_number'] ?? null;

            if ($accountNumber !== null) {
                $seenAccounts[$accountNumber] = ($seenAccounts[$accountNumber] ?? 0) + 1;
            }

            foreach ($translated['flags'] as $flag) {
                if (isset($summary[$flag])) {
                    $summary[$flag]++;
                }
            }

            if ($translated['errors'] === []) {
                $summary['ready']++;
                $summary[$translated['exists'] ? 'existing' : 'new']++;
            }

            $rows[] = [
                'row' => $rowNumber,
                'data' => $translated['data'],
                'errors' => $translated['errors'],
                'warnings' => $translated['warnings'],
                'exists' => $translated['exists'],
                'branch_name' => $translated['branch_name'],
                'bc_target' => $translated['bc_target'],
            ];
        }

        // Total row count is needed so the Admin knows how much is beyond the preview.
        $totalRows = $reader->countRows($import['sheet_name'], (int) $import['header_row']);

        Database::update('excel_imports', [
            'total_rows' => $totalRows,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        $issues = [];

        if ($summary['missing_required'] > 0) {
            $issues[] = sprintf('%d row(s) are missing a required field and will be skipped.', $summary['missing_required']);
        }

        if ($summary['invalid_data'] > 0) {
            $issues[] = sprintf('%d row(s) contain a value that could not be parsed.', $summary['invalid_data']);
        }

        if ($summary['unknown_branch'] > 0) {
            $issues[] = sprintf('%d row(s) reference a branch that does not exist in LRMS.', $summary['unknown_branch']);
        }

        if ($summary['invalid_bc'] > 0) {
            $issues[] = sprintf(
                '%d row(s) carry a BC Code that is not an active BC Supervisor; those accounts will be allocated by workload instead.',
                $summary['invalid_bc']
            );
        }

        if ($summary['duplicate_in_file'] > 0) {
            $issues[] = sprintf('%d duplicate account number(s) inside the file; only the first occurrence is imported.', $summary['duplicate_in_file']);
        }

        if ($summary['existing'] > 0) {
            $issues[] = sprintf('%d account(s) already exist and will be updated, not duplicated.', $summary['existing']);
        }

        return [
            'import' => $this->find($importId),
            'headers' => $headers,
            'mapping' => $mapping,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
            'issues' => $issues,
            'total_rows' => $totalRows,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Step 4 — import                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Run the import. Each row is independent: a bad row is logged to
     * `excel_import_errors` and the rest still land, which is what an operator
     * uploading a 5,000 line sheet needs.
     *
     * @return array<string, int>
     */
    public function import(int $importId, bool $autoAllocate = true): array
    {
        $import = $this->find($importId);

        if (in_array((string) $import['status'], ['completed', 'importing'], true)) {
            throw new HttpException(422, 'This file has already been imported.');
        }

        $mapping = $this->mapping($import);

        if ($mapping === []) {
            throw new HttpException(422, 'Confirm the column mapping before importing.');
        }

        $headers = $this->headers($import);
        $columns = ColumnMatcher::resolveMapping($mapping, $headers);
        $reader = $this->reader($import);
        $branchIndex = $this->branchIndex();
        $allocation = new Allocation();

        Database::update('excel_imports', [
            'status' => 'importing',
            'started_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        Database::delete('excel_import_errors', 'import_id = :id', ['id' => $importId]);

        $stats = [
            'total' => 0,
            'imported' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'duplicates' => 0,
            'assigned' => 0,
        ];

        $seenAccounts = [];

        try {
            foreach ($reader->rows($import['sheet_name'], 0, (int) $import['header_row']) as $rowNumber => $values) {
                $stats['total']++;

                $translated = $this->translateRow($values, $columns, $branchIndex, $allocation, $seenAccounts, true);
                $data = $translated['data'];
                $accountNumber = $data['account_number'] ?? null;

                if ($translated['errors'] !== []) {
                    $stats['skipped']++;
                    $stats['errors']++;

                    if (in_array('duplicate_in_file', $translated['flags'], true)) {
                        $stats['duplicates']++;
                    }

                    foreach ($translated['errors'] as $error) {
                        $this->recordError($importId, $rowNumber, $accountNumber, $error, $values);
                    }

                    continue;
                }

                if ($accountNumber !== null) {
                    $seenAccounts[$accountNumber] = ($seenAccounts[$accountNumber] ?? 0) + 1;
                }

                foreach ($translated['warnings'] as $warning) {
                    $this->recordError($importId, $rowNumber, $accountNumber, [
                        'type' => $warning['type'],
                        'message' => $warning['message'],
                        'column' => $warning['column'] ?? null,
                    ], $values, 'warning');
                }

                $accountId = $this->upsertAccount($data, $translated['branch_id'], $importId, $stats);

                if ($autoAllocate) {
                    $result = $allocation->allocateImported(
                        $accountId,
                        $translated['branch_id'],
                        $data['bc_code_raw'] ?? null,
                        $importId
                    );

                    if ($result['assigned']) {
                        $stats['assigned']++;
                    } elseif ($result['note'] !== '') {
                        $this->recordError($importId, $rowNumber, $accountNumber, [
                            'type' => 'invalid_bc',
                            'message' => $result['note'],
                            'column' => null,
                        ], $values, 'warning');
                    }
                }

                $stats['imported']++;
            }
        } catch (\Throwable $e) {
            Database::update('excel_imports', [
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 255),
                'completed_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $importId]);

            throw $e;
        }

        Database::update('excel_imports', [
            'status' => 'completed',
            'total_rows' => $stats['total'],
            'imported_rows' => $stats['imported'],
            'created_accounts' => $stats['created'],
            'updated_accounts' => $stats['updated'],
            'skipped_rows' => $stats['skipped'],
            'error_rows' => $stats['errors'],
            'duplicate_rows' => $stats['duplicates'],
            'assigned_rows' => $stats['assigned'],
            'completed_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        Audit::log(Audit::EXCEL_IMPORTED, [
            'entity_type' => 'excel_import',
            'entity_id' => $importId,
            'description' => sprintf(
                '%s: %d rows imported (%d new, %d updated, %d skipped, %d allocated).',
                $import['original_name'],
                $stats['imported'],
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
                $stats['assigned']
            ),
            'new' => $stats,
        ]);

        return $stats;
    }

    /* ------------------------------------------------------------------ */
    /* Row translation — shared by preview and import                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int, string>       $values
     * @param array<string, int>       $columns
     * @param array<string, int>       $branchIndex
     * @param array<string, int>       $seenAccounts
     * @return array{
     *   data: array<string, mixed>,
     *   branch_id: int,
     *   branch_name: ?string,
     *   bc_target: ?string,
     *   errors: array<int, array{type:string, message:string, column:?string}>,
     *   warnings: array<int, array{type:string, column:?string, message:string}>,
     *   flags: array<int, string>,
     *   exists: bool
     * }
     */
    private function translateRow(
        array $values,
        array $columns,
        array $branchIndex,
        Allocation $allocation,
        array $seenAccounts,
        bool $forImport
    ): array {
        $errors = [];
        $warnings = [];
        $flags = [];
        $data = [];

        $get = static function (string $field) use ($values, $columns): ?string {
            $index = $columns[$field] ?? null;

            if ($index === null) {
                return null;
            }

            $value = $values[$index] ?? '';

            return $value === '' ? null : (string) $value;
        };

        /* Account number ------------------------------------------------ */
        $data['account_number'] = ValueParser::accountNumber($get('account_number'));

        if ($data['account_number'] === null) {
            $errors[] = ['type' => 'missing_required', 'message' => 'Account Number is empty.', 'column' => 'account_number'];
            $flags[] = 'missing_required';
        } elseif (isset($seenAccounts[$data['account_number']])) {
            $errors[] = [
                'type' => 'duplicate',
                'message' => sprintf('Account %s appears earlier in this file.', $data['account_number']),
                'column' => 'account_number',
            ];
            $flags[] = 'duplicate_in_file';
        }

        /* Borrower ------------------------------------------------------ */
        $data['borrower_name'] = ValueParser::text($get('borrower_name'), SystemFields::textLength('borrower_name'));

        if ($data['borrower_name'] === null) {
            $errors[] = ['type' => 'missing_required', 'message' => 'Borrower Name is empty.', 'column' => 'borrower_name'];
            $flags[] = 'missing_required';
        }

        $data['cif'] = ValueParser::text($get('cif'), SystemFields::textLength('cif'));
        $data['father_name'] = ValueParser::text($get('father_name'), SystemFields::textLength('father_name'));
        $data['village'] = ValueParser::text($get('village'), SystemFields::textLength('village'));
        $data['gram_panchayat'] = ValueParser::text($get('gram_panchayat'), SystemFields::textLength('gram_panchayat'));
        $data['tehsil'] = ValueParser::text($get('tehsil'), SystemFields::textLength('tehsil'));
        $data['district'] = ValueParser::text($get('district'), SystemFields::textLength('district'));
        $data['state'] = ValueParser::text($get('state'), SystemFields::textLength('state'));
        $data['pincode'] = ValueParser::text($get('pincode'), SystemFields::textLength('pincode'));
        $data['address'] = ValueParser::text($get('address'), SystemFields::textLength('address'));
        $data['loan_type'] = ValueParser::text($get('loan_type'), SystemFields::textLength('loan_type'));

        /* Mobiles ------------------------------------------------------- */
        foreach (['mobile' => 'mobile', 'alternate_mobile' => 'alternate_mobile'] as $field => $column) {
            [$mobile, $mobileError] = ValueParser::mobile($get($column));
            $data[$field] = $mobile;

            if ($mobileError !== '') {
                $warnings[] = [
                    'type' => 'invalid_data',
                    'message' => SystemFields::label($column) . ': ' . $mobileError,
                    'column' => $column,
                ];
            }
        }

        /* Identity fields printed in section 2 of the report ------------- */
        // These are warnings, never errors: a malformed PAN must not stop a loan
        // account being loaded and allocated.
        foreach ([
            'gender' => [ValueParser::class, 'gender'],
            'pan_number' => [ValueParser::class, 'pan'],
            'aadhaar_last4' => [ValueParser::class, 'aadhaarLast4'],
            'asset_classification' => [ValueParser::class, 'assetClassification'],
        ] as $field => $parser) {
            [$value, $warning] = $parser($get($field));
            $data[$field] = $value;

            if ($warning !== '') {
                $warnings[] = ['type' => 'invalid_data', 'message' => $warning, 'column' => $field];
            }
        }

        /* Amounts ------------------------------------------------------- */
        foreach ([
            'outstanding' => 'outstanding',
            'overdue' => 'overdue',
            'limit_amount' => 'limit_amount',
        ] as $field => $column) {
            [$amount, $error] = ValueParser::amount($get($column));

            if ($error !== '') {
                $errors[] = ['type' => 'invalid_data', 'message' => SystemFields::label($column) . ': ' . $error, 'column' => $column];
                $flags[] = 'invalid_data';
                continue;
            }

            $data[$field] = $amount ?? 0.0;
        }

        // Drawing power and interest overdue stay NULL when absent rather than
        // becoming 0.00: on a compliance report "not supplied" and "nil" are
        // different statements.
        foreach (['drawing_power', 'interest_overdue'] as $field) {
            [$amount, $error] = ValueParser::amount($get($field));

            if ($error !== '') {
                $errors[] = ['type' => 'invalid_data', 'message' => SystemFields::label($field) . ': ' . $error, 'column' => $field];
                $flags[] = 'invalid_data';
                continue;
            }

            $data[$field] = $amount;
        }

        /* Dates --------------------------------------------------------- */
        foreach (['sanction_date', 'npa_date', 'date_of_birth'] as $field) {
            [$date, $error] = ValueParser::date($get($field));

            if ($error !== '') {
                $errors[] = ['type' => 'invalid_data', 'message' => SystemFields::label($field) . ': ' . $error, 'column' => $field];
                $flags[] = 'invalid_data';
                continue;
            }

            $data[$field] = $date;
        }

        /* Branch -------------------------------------------------------- */
        $branchCode = ValueParser::text($get('branch_code'), SystemFields::textLength('branch_code'));
        $branchName = ValueParser::text($get('branch_name'), SystemFields::textLength('branch_name'));
        $data['branch_code_raw'] = $branchCode;
        $branchId = 0;

        if ($branchCode !== null) {
            $branchId = $branchIndex['code:' . ColumnMatcher::normalise($branchCode)] ?? 0;
        }

        if ($branchId === 0 && $branchName !== null) {
            $branchId = $branchIndex['name:' . ColumnMatcher::normalise($branchName)] ?? 0;
        }

        if ($branchId === 0) {
            $errors[] = [
                'type' => 'unknown_branch',
                'message' => sprintf(
                    'Branch "%s" is not set up in LRMS. Create the branch first, then re-import.',
                    $branchCode ?? $branchName ?? '(blank)'
                ),
                'column' => 'branch_code',
            ];
            $flags[] = 'unknown_branch';
        }

        /* BC Code ------------------------------------------------------- */
        $bcCode = ValueParser::text($get('bc_code'), SystemFields::textLength('bc_code'));
        $data['bc_code_raw'] = $bcCode;
        $bcTarget = null;

        if ($bcCode !== null) {
            $supervisor = $allocation->findByCode($bcCode);

            if ($supervisor === null) {
                $flags[] = 'invalid_bc';
                $warnings[] = [
                    'type' => 'invalid_bc',
                    'column' => 'bc_code',
                    'message' => sprintf(
                        'BC Code "%s" is not an active BC Supervisor; the account will be allocated by workload.',
                        $bcCode
                    ),
                ];
            } elseif ($branchId > 0 && $supervisor['branch_id'] !== $branchId) {
                $flags[] = 'invalid_bc';
                $warnings[] = [
                    'type' => 'invalid_bc',
                    'column' => 'bc_code',
                    'message' => sprintf(
                        'BC Code "%s" belongs to a different branch; the account will be allocated by workload.',
                        $bcCode
                    ),
                ];
            } else {
                $bcTarget = $supervisor['bc_code'];
            }
        }

        /* Existing account? -------------------------------------------- */
        $exists = false;

        if ($data['account_number'] !== null) {
            $exists = Database::selectOne(
                'SELECT id FROM loan_accounts WHERE account_number = :n LIMIT 1',
                ['n' => $data['account_number']]
            ) !== null;
        }

        return [
            'data' => $data,
            'branch_id' => $branchId,
            'branch_name' => $branchId > 0
                ? (string) Database::scalar('SELECT name FROM branches WHERE id = :id', ['id' => $branchId])
                : ($branchName ?? $branchCode),
            'bc_target' => $bcTarget,
            'errors' => $errors,
            'warnings' => $warnings,
            'flags' => array_values(array_unique($flags)),
            'exists' => $exists,
        ];
    }

    /**
     * Insert or update the account. Existing accounts keep their id (and so keep
     * their visits and allocation history) and only have their figures refreshed.
     */
    /**
     * Delegates to the shared service so a sheet and the manual entry form
     * produce the same row, with the same defaults.
     */
    private function upsertAccount(array $data, int $branchId, int $importId, array &$stats): int
    {
        $result = LoanAccounts::upsert($data, $branchId, $importId);

        if ($result['created']) {
            $stats['created']++;
        } else {
            $stats['updated']++;
        }

        return $result['id'];
    }

    /**
     * @param array{type:string, message:string, column:?string} $error
     * @param array<int, string>                                 $values
     */
    private function recordError(
        int $importId,
        int $rowNumber,
        ?string $accountNumber,
        array $error,
        array $values,
        string $severity = 'error'
    ): void {
        Database::insert('excel_import_errors', [
            'import_id' => $importId,
            'row_number' => $rowNumber,
            'column_name' => $error['column'] ?? null,
            'account_number' => $accountNumber,
            'error_type' => $error['type'],
            'severity' => $severity,
            'message' => mb_substr($error['message'], 0, 255),
            'raw_row' => json_encode(array_slice($values, 0, 40), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    /**
     * Branch lookup by normalised code and name.
     *
     * @return array<string, int>
     */
    private function branchIndex(): array
    {
        $index = [];

        foreach (Database::select("SELECT id, code, name FROM branches WHERE status = 'active'") as $branch) {
            $index['code:' . ColumnMatcher::normalise((string) $branch['code'])] = (int) $branch['id'];
            $index['name:' . ColumnMatcher::normalise((string) $branch['name'])] = (int) $branch['id'];
        }

        return $index;
    }

    public function cancel(int $importId): void
    {
        $import = $this->find($importId);

        Database::update('excel_imports', [
            'status' => 'cancelled',
            'completed_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        $path = storage_path($import['stored_path']);

        if (is_file($path)) {
            @unlink($path);
        }

        Audit::log(Audit::EXCEL_CANCELLED, [
            'entity_type' => 'excel_import',
            'entity_id' => $importId,
            'description' => sprintf('Import of %s cancelled before writing any accounts.', $import['original_name']),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Mapping templates                                                  */
    /* ------------------------------------------------------------------ */

    public function saveTemplate(int $importId, string $name, string $description = ''): int
    {
        $import = $this->find($importId);
        $mapping = $this->mapping($import);

        if ($mapping === []) {
            throw new HttpException(422, 'Confirm a mapping before saving it as a template.');
        }

        $existing = Database::selectOne('SELECT id FROM excel_mapping_templates WHERE name = :name', ['name' => $name]);

        $payload = [
            'description' => $description !== '' ? mb_substr($description, 0, 255) : null,
            'mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
            'header_row' => (int) $import['header_row'],
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            $id = (int) $existing['id'];
            Database::update('excel_mapping_templates', $payload, 'id = :id', ['id' => $id]);
        } else {
            $id = Database::insert('excel_mapping_templates', array_merge($payload, [
                'name' => mb_substr($name, 0, 160),
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]));
        }

        Database::update('excel_imports', ['template_id' => $id, 'updated_at' => now()], 'id = :id', ['id' => $importId]);

        Audit::log(Audit::MAPPING_SAVED, [
            'entity_type' => 'excel_mapping_template',
            'entity_id' => $id,
            'description' => sprintf('Mapping template "%s" saved.', $name),
            'new' => $mapping,
        ]);

        return $id;
    }

    /**
     * Apply a saved template to the current upload.
     */
    public function applyTemplate(int $importId, int $templateId): array
    {
        $template = Database::selectOne('SELECT * FROM excel_mapping_templates WHERE id = :id', ['id' => $templateId]);

        if ($template === null) {
            throw new HttpException(404, 'Mapping template not found.');
        }

        $import = $this->find($importId);
        $mapping = json_decode((string) $template['mapping'], true);

        if (!is_array($mapping)) {
            throw new HttpException(422, 'That mapping template is corrupt and cannot be applied.');
        }

        $headerRow = max(1, (int) $template['header_row']);

        if ($headerRow !== (int) $import['header_row']) {
            $import = $this->redetect($importId, $import['sheet_name'], $headerRow);
        }

        $headers = $this->headers($import);
        $resolved = ColumnMatcher::resolveMapping($mapping, $headers);
        $missing = [];

        foreach ($mapping as $field => $header) {
            if (!isset($resolved[$field])) {
                $missing[] = (string) $header;
            }
        }

        // Keep only the fields whose columns actually exist in this file.
        $applied = [];

        foreach ($resolved as $field => $columnIndex) {
            $applied[$field] = $headers[$columnIndex];
        }

        Database::update('excel_imports', [
            'mapping' => json_encode($applied, JSON_UNESCAPED_UNICODE),
            'template_id' => $templateId,
            'status' => $applied === [] ? 'uploaded' : 'mapped',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $importId]);

        Database::update('excel_mapping_templates', [
            'usage_count' => (int) $template['usage_count'] + 1,
            'last_used_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $templateId]);

        return [
            'import' => $this->find($importId),
            'applied' => $applied,
            'missing_columns' => $missing,
        ];
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }

        return $bytes . ' B';
    }
}
