<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Photos;

/**
 * Serves stored evidence files.
 *
 * Nothing under storage/ is web accessible, so every photograph, signature and
 * export is streamed through here after an authorisation check. A Branch Manager
 * can only ever fetch files belonging to their own branch.
 */
final class FileController extends Controller
{
    public function visitPhoto(Request $request): void
    {
        $photo = Database::selectOne(
            'SELECT p.*, v.branch_id
               FROM visit_photos p
               JOIN visits v ON v.id = p.visit_id
              WHERE p.id = :id',
            ['id' => $request->paramInt('id')]
        );

        if ($photo === null) {
            $this->abort(404, 'Photo not found.');
        }

        Acl::authorize('photos.view');
        Acl::authorizeBranch((int) $photo['branch_id']);

        $this->stream((string) $photo['file_path'], (string) $photo['file_name'], (string) $photo['mime_type']);
    }

    public function inspectionPhoto(Request $request): void
    {
        $photo = Database::selectOne(
            'SELECT p.*, i.branch_id
               FROM inspection_photos p
               JOIN inspections i ON i.id = p.inspection_id
              WHERE p.id = :id',
            ['id' => $request->paramInt('id')]
        );

        if ($photo === null) {
            $this->abort(404, 'Photo not found.');
        }

        Acl::authorize('inspections.view');
        Acl::authorizeBranch((int) $photo['branch_id']);

        $this->stream((string) $photo['file_path'], (string) $photo['file_name'], (string) $photo['mime_type']);
    }

    /**
     * Signature images attached to a visit or an inspection.
     */
    public function signature(Request $request): void
    {
        $type = (string) $request->param('type');
        $which = (string) $request->param('which');
        $id = $request->paramInt('id');

        if ($type === 'visit') {
            $row = Database::selectOne(
                'SELECT branch_id, borrower_signature, supervisor_signature FROM visits WHERE id = :id',
                ['id' => $id]
            );

            $column = $which === 'supervisor' ? 'supervisor_signature' : 'borrower_signature';
        } elseif ($type === 'inspection') {
            $row = Database::selectOne(
                'SELECT branch_id, inspector_signature, bc_signature FROM inspections WHERE id = :id',
                ['id' => $id]
            );

            $column = $which === 'bc' ? 'bc_signature' : 'inspector_signature';
        } else {
            $this->abort(404, 'Signature not found.');
        }

        if ($row === null || empty($row[$column])) {
            $this->abort(404, 'Signature not found.');
        }

        Acl::authorizeBranch((int) $row['branch_id']);

        $this->stream((string) $row[$column], basename((string) $row[$column]), 'image/png');
    }

    public function attendanceSelfie(Request $request): void
    {
        $row = Database::selectOne(
            'SELECT branch_id, selfie_path FROM attendance WHERE id = :id',
            ['id' => $request->paramInt('id')]
        );

        if ($row === null || empty($row['selfie_path'])) {
            $this->abort(404, 'Selfie not found.');
        }

        Acl::authorize('attendance.view');
        Acl::authorizeBranch((int) $row['branch_id']);

        $this->stream((string) $row['selfie_path'], basename((string) $row['selfie_path']), 'image/png');
    }

    /**
     * Download a generated report export. Only the user who produced it (or an
     * BC Supervisor) may fetch it, because the file already contains data that
     * was filtered for that user.
     */
    public function export(Request $request): void
    {
        $export = Database::selectOne(
            'SELECT * FROM report_exports WHERE id = :id',
            ['id' => $request->paramInt('id')]
        );

        if ($export === null || $export['file_path'] === null) {
            $this->abort(404, 'Export not found.');
        }

        if (!Auth::isAdmin() && (int) $export['user_id'] !== (int) Auth::id()) {
            $this->abort(403, 'That export belongs to another user.');
        }

        $contentType = match ((string) $export['format']) {
            'pdf' => 'application/pdf',
            'excel' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv; charset=UTF-8',
        };

        Response::download(
            Photos::absolutePath((string) $export['file_path']),
            (string) $export['file_name'],
            $contentType
        );
    }

    private function stream(string $relativePath, string $fileName, string $contentType): void
    {
        $absolute = Photos::absolutePath($relativePath);

        // Evidence must not be cached by shared proxies.
        if (!headers_sent()) {
            header('Cache-Control: private, max-age=300');
        }

        Response::inline($absolute, $fileName, $contentType !== '' ? $contentType : 'application/octet-stream');
    }
}
