<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Excel\ColumnMatcher;
use App\Services\Excel\LoanImporter;
use App\Services\Excel\SampleSheet;
use App\Services\Excel\SystemFields;

/**
 * The Excel upload wizard: upload → map columns → preview → import.
 *
 * Nothing is written to the loan book until the BC Supervisor confirms the
 * mapping and the preview, and the same translation code powers both the preview
 * and the import so there are no surprises.
 */
final class ImportController extends BaseController
{
    public function index(Request $request): void
    {
        $this->page('admin.imports.index', [
            'title' => 'Excel imports',
            'imports' => Database::select(
                'SELECT i.*, u.name AS uploaded_by, t.name AS template_name
                   FROM excel_imports i
                   JOIN users u ON u.id = i.user_id
              LEFT JOIN excel_mapping_templates t ON t.id = i.template_id
                  ORDER BY i.id DESC
                  LIMIT 50'
            ),
            'templates' => Database::select(
                'SELECT t.*, u.name AS created_by_name
                   FROM excel_mapping_templates t
              LEFT JOIN users u ON u.id = t.created_by
                  ORDER BY t.usage_count DESC, t.name ASC'
            ),
        ]);
    }

    /**
     * Downloads a demo sheet in the shape the importer expects.
     *
     * Generated rather than shipped as a static file, for two reasons. The header
     * row comes from SystemFields, so it can never fall behind the columns the
     * importer actually reads. And the branch and BC columns are filled from this
     * installation's own records, so the file imports cleanly instead of failing
     * on "Branch X is not set up in LRMS" — a sample that cannot be imported
     * teaches the wrong thing about the format.
     *
     * The account numbers are prefixed SAMPLE- so demo rows are obvious in the
     * loan book, and easy to find and remove afterwards.
     */
    public function sample(Request $request): void
    {
        $format = strtolower((string) $request->input('format', 'xlsx')) === 'csv' ? 'csv' : 'xlsx';
        $path = SampleSheet::write($format);

        Response::download($path, 'LRMS-sample-loan-import.' . $format, SampleSheet::contentType($format));

        // A generated demo file has no reason to accumulate on disk.
        @unlink($path);
    }

    public function create(Request $request): void
    {
        $branches = (int) Database::scalar("SELECT COUNT(*) FROM branches WHERE status = 'active'");

        $this->page('admin.imports.create', [
            'title' => 'Upload loan Excel',
            'branchCount' => $branches,
            'supervisorCount' => (int) Database::scalar("SELECT COUNT(*) FROM bc_supervisors WHERE status = 'active'"),
            'templates' => Database::select('SELECT id, name, description FROM excel_mapping_templates ORDER BY name'),
            'systemFields' => SystemFields::all(),
        ]);
    }

    public function store(Request $request): void
    {
        $file = $request->file('file');

        if ($file === null) {
            $this->error('Choose an .xlsx or .csv file to upload.');
            $this->redirect('/admin/imports/create');

            return;
        }

        $importer = new LoanImporter();
        $import = $importer->store($file);

        // Apply a saved mapping straight away when one was chosen.
        $templateId = (int) $request->input('template_id', 0);

        if ($templateId > 0) {
            $result = $importer->applyTemplate((int) $import['id'], $templateId);

            if ($result['missing_columns'] !== []) {
                $this->info(sprintf(
                    'Template applied, but %d column(s) from it were not found in this file: %s. Check the mapping below.',
                    count($result['missing_columns']),
                    implode(', ', array_slice($result['missing_columns'], 0, 5))
                ));
            } else {
                $this->success('Saved mapping applied. Review it and continue.');
            }
        }

        $this->redirect('/admin/imports/' . (int) $import['id'] . '/mapping');
    }

    /**
     * Step 2 — the column mapping screen.
     */
    public function mapping(Request $request): void
    {
        $importer = new LoanImporter();
        $import = $importer->find($request->paramInt('id'));
        $headers = $importer->headers($import);
        $mapping = $importer->mapping($import);

        // Re-run detection so confidence and warnings can be shown next to each row.
        $matches = ColumnMatcher::match($headers);
        $reader = $importer->reader($import);
        $sample = $reader->preview($import['sheet_name'], (int) $import['header_row'], 5);

        $this->page('admin.imports.mapping', [
            'title' => 'Map Excel columns',
            'import' => $import,
            'headers' => $headers,
            'mapping' => $mapping,
            'matches' => $matches,
            'uncertain' => ColumnMatcher::uncertainFields($matches),
            'missingRequired' => ColumnMatcher::missingRequired($matches),
            'systemFields' => SystemFields::all(),
            'sheets' => $reader->sheetNames(),
            'sample' => $sample,
            'templates' => Database::select('SELECT id, name FROM excel_mapping_templates ORDER BY name'),
        ]);
    }

    public function saveMapping(Request $request): void
    {
        $importId = $request->paramInt('id');
        $importer = new LoanImporter();

        $raw = $request->raw('mapping');
        $mapping = [];

        if (is_array($raw)) {
            foreach ($raw as $field => $header) {
                if (is_string($field) && is_string($header)) {
                    $mapping[$field] = $header;
                }
            }
        }

        $importer->saveMapping($importId, $mapping);

        $this->success('Mapping saved. Review the preview before importing.');
        $this->redirect('/admin/imports/' . $importId . '/preview');
    }

    /**
     * Change the sheet or header row and re-detect.
     */
    public function redetect(Request $request): void
    {
        $importId = $request->paramInt('id');

        (new LoanImporter())->redetect(
            $importId,
            (string) $request->input('sheet_name', ''),
            (int) $request->input('header_row', 1)
        );

        $this->info('Columns re-detected for that sheet and header row.');
        $this->redirect('/admin/imports/' . $importId . '/mapping');
    }

    public function applyTemplate(Request $request): void
    {
        $importId = $request->paramInt('id');
        $templateId = (int) $request->input('template_id', 0);

        if ($templateId <= 0) {
            $this->error('Choose a saved mapping to apply.');
            $this->redirect('/admin/imports/' . $importId . '/mapping');

            return;
        }

        $result = (new LoanImporter())->applyTemplate($importId, $templateId);

        if ($result['missing_columns'] !== []) {
            $this->info(sprintf(
                'Applied, but these template columns are not in this file: %s.',
                implode(', ', array_slice($result['missing_columns'], 0, 6))
            ));
        } else {
            $this->success('Saved mapping applied.');
        }

        $this->redirect('/admin/imports/' . $importId . '/mapping');
    }

    public function saveTemplate(Request $request): void
    {
        $importId = $request->paramInt('id');

        $data = $this->validate($request, [
            'name' => 'required|max:160',
            'description' => 'nullable|max:255',
        ]);

        (new LoanImporter())->saveTemplate($importId, (string) $data['name'], (string) ($data['description'] ?? ''));

        $this->success(sprintf('Mapping saved as "%s" and will be offered on the next upload.', $data['name']));
        $this->back('/admin/imports/' . $importId . '/mapping');
    }

    public function deleteTemplate(Request $request): void
    {
        $id = $request->paramInt('id');
        $template = Database::selectOne('SELECT * FROM excel_mapping_templates WHERE id = :id', ['id' => $id]);

        if ($template === null) {
            $this->abort(404, 'Mapping template not found.');
        }

        Database::delete('excel_mapping_templates', 'id = :id', ['id' => $id]);

        Audit::log(Audit::MAPPING_DELETED, [
            'entity_type' => 'excel_mapping_template',
            'entity_id' => $id,
            'description' => sprintf('Mapping template "%s" deleted.', $template['name']),
        ]);

        $this->success('Mapping template deleted.');
        $this->back('/admin/imports');
    }

    /**
     * Step 3 — preview and validation summary.
     */
    public function preview(Request $request): void
    {
        $importer = new LoanImporter();
        $importId = $request->paramInt('id');
        $preview = $importer->preview($importId, 50);

        $this->page('admin.imports.preview', array_merge($preview, [
            'title' => 'Import preview',
            'systemFields' => SystemFields::all(),
        ]));
    }

    /**
     * Step 4 — write the accounts and allocate them.
     */
    public function run(Request $request): void
    {
        $importId = $request->paramInt('id');
        $autoAllocate = $request->boolean('auto_allocate') || $request->input('auto_allocate') === null;

        $stats = (new LoanImporter())->import($importId, $autoAllocate);

        $this->success(sprintf(
            'Import complete: %d row(s) processed — %d new, %d updated, %d allocated, %d skipped.',
            $stats['imported'],
            $stats['created'],
            $stats['updated'],
            $stats['assigned'],
            $stats['skipped']
        ));

        if ($stats['errors'] > 0) {
            $this->info(sprintf('%d row(s) reported a problem. Review the error list.', $stats['errors']));
        }

        $this->redirect('/admin/imports/' . $importId);
    }

    public function show(Request $request): void
    {
        $importId = $request->paramInt('id');
        $importer = new LoanImporter();
        $import = $importer->find($importId);

        $this->page('admin.imports.show', [
            'title' => 'Import #' . $importId,
            'import' => $import,
            'mapping' => $importer->mapping($import),
            'systemFields' => SystemFields::all(),
            'errors' => Database::select(
                // `row_number` must be quoted: MariaDB and MySQL 8 read the bare
                // word as the ROW_NUMBER() window function and fail on the next
                // token. The column name is fine in DDL, where it is already
                // quoted, which is why this only surfaced when the page was opened.
                'SELECT * FROM excel_import_errors WHERE import_id = :id ORDER BY `row_number` ASC LIMIT 500',
                ['id' => $importId]
            ),
            'errorCounts' => Database::select(
                'SELECT error_type, severity, COUNT(*) AS total
                   FROM excel_import_errors WHERE import_id = :id
                  GROUP BY error_type, severity ORDER BY total DESC',
                ['id' => $importId]
            ),
            'accounts' => Database::select(
                'SELECT a.id, a.account_number, a.borrower_name, a.outstanding, a.overdue,
                        b.name AS branch_name, s.bc_code, u.name AS supervisor_name
                   FROM loan_accounts a
                   JOIN branches b ON b.id = a.branch_id
              LEFT JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
              LEFT JOIN bc_supervisors s ON s.id = x.bc_supervisor_id
              LEFT JOIN users u ON u.id = s.user_id
                  WHERE a.excel_import_id = :id
                  ORDER BY a.id DESC LIMIT 100',
                ['id' => $importId]
            ),
        ]);
    }

    public function cancel(Request $request): void
    {
        $importId = $request->paramInt('id');
        (new LoanImporter())->cancel($importId);

        $this->info('Import cancelled and the uploaded file removed. No accounts were changed.');
        $this->redirect('/admin/imports');
    }
}
