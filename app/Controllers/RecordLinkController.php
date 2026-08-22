<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

/**
 * The other end of the QR code printed on every exported PDF.
 *
 * WHY A REDIRECT AND NOT THE PANEL PATH ITSELF
 *
 * The same visit report is printed from two places: a BC Supervisor at
 * `/admin/visits/{id}/pdf` and a Branch Manager at `/manager/visits/{id}/pdf`. If the code
 * carried the path of whoever printed it, then a sheet printed in the office and scanned at
 * the branch would open a page the reader is not allowed on — a 403 for doing exactly what
 * the caption told them to do. And the paper long outlives the session that produced it, so
 * there is no moment at which the right path is known.
 *
 * So the code carries a path that means "this record", and where that goes is decided when
 * somebody scans it, from who they turn out to be.
 *
 * A signed-out scan hits the auth middleware, which stores the intended URL and sends them
 * to sign in — so they arrive at the record after signing in rather than at a dashboard,
 * which is the whole point of scanning it.
 */
final class RecordLinkController extends Controller
{
    /**
     * Where each kind of record lives in each panel.
     *
     * A Branch Manager has no inspection screen: inspections are a BC Supervisor's work and
     * the branch portal is deliberately read-only reporting. That is a null here rather than
     * a missing key, so the refusal below can say so instead of falling through to a 404 that
     * suggests the record does not exist.
     *
     * @var array<string, array{admin: string, manager: ?string, label: string}>
     */
    private const TARGETS = [
        'visit' => [
            'admin' => '/admin/visits/%s',
            'manager' => '/manager/visits/%s',
            'label' => 'visit',
        ],
        'inspection' => [
            'admin' => '/admin/inspections/%s',
            'manager' => null,
            'label' => 'inspection',
        ],
        'report' => [
            'admin' => '/admin/reports/%s',
            'manager' => '/manager/reports/%s',
            'label' => 'report',
        ],
    ];

    public function show(Request $request): void
    {
        $type = (string) $request->param('type');
        $reference = (string) $request->param('reference');

        if (!isset(self::TARGETS[$type])) {
            $this->abort(404, 'That is not a kind of record this system has.');
        }

        $target = self::TARGETS[$type];

        // A record link is only worth following if the record is still there. Without this a
        // scan of a sheet whose visit was deleted would land on the panel's own 404, which
        // reads like the link is broken rather than like the record is gone.
        if ($type !== 'report' && !$this->exists($type, $reference)) {
            $this->abort(404, sprintf(
                'That %s is no longer in the system. The printed sheet is the only copy left of it.',
                $target['label']
            ));
        }

        if (Auth::isAdmin()) {
            $path = sprintf($target['admin'], $reference);
        } elseif (Auth::is(Auth::ROLE_MANAGER)) {
            if ($target['manager'] === null) {
                $this->info(sprintf(
                    'The branch portal does not show %ss. Ask your BC Supervisor to open it for you — '
                    . 'the reference is printed under the code on the sheet.',
                    $target['label']
                ));
                $this->redirect('/manager');

                return;
            }

            $path = sprintf($target['manager'], $reference);
        } else {
            // A BCA. The panel is not theirs to browse; the app is.
            $this->redirect('/app-only');

            return;
        }

        // Carry the filters through, so a report code opens the report it was printed from
        // rather than that report with no dates on it.
        $this->redirect($path . query_string());
    }

    private function exists(string $type, string $reference): bool
    {
        if (!ctype_digit($reference)) {
            return false;
        }

        $table = $type === 'inspection' ? 'inspections' : 'visits';

        return Database::scalar(
            sprintf('SELECT id FROM `%s` WHERE id = :id', $table),
            ['id' => (int) $reference]
        ) !== null;
    }
}
