#!/usr/bin/env php
<?php

declare(strict_types=1);

// LRMS scheduled tasks.
//
// Add one cron entry running every five minutes; the script decides what is due.
// (Line comments, not a docblock: a crontab's leading "*/5" would close a block
// comment and make this file unparseable.)
//
//   */5 * * * * /usr/bin/php /path/to/lrms/bin/cron.php >> /path/to/lrms/storage/logs/cron.log 2>&1
//
// Individual tasks can also be run by hand:
//
//   php bin/cron.php reminders     pre-deadline reminders to supervisors
//   php bin/cron.php lock          lock yesterday's unsubmitted reports
//   php bin/cron.php promises      mark promises whose date has passed as broken
//   php bin/cron.php followups     remind supervisors of follow-ups due today
//   php bin/cron.php attendance    mark absentees for yesterday
//   php bin/cron.php housekeeping  purge expired OTPs and stale exports

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\Attendance;
use App\Services\Deadline;
use App\Services\Followups;
use App\Services\Otp;
use App\Services\Promises;

$task = $argv[1] ?? 'all';
$started = microtime(true);
$log = static function (string $message): void {
    printf("[%s] %s\n", now(), $message);
};

if (!Database::isConnected()) {
    fwrite(STDERR, sprintf("[%s] Database unavailable: %s\n", now(), (string) Database::lastError()));
    exit(1);
};

try {
    if ($task === 'all' || $task === 'reminders') {
        $sent = Deadline::sendReminders();
        $log(sprintf('Deadline reminders: %d sent.', $sent));
    }

    if ($task === 'all' || $task === 'followups') {
        // Once a day, shortly after the working day starts.
        if ($task === 'followups' || (int) date('H') === 9 && (int) date('i') < 5) {
            $log(sprintf('Follow-up reminders: %d sent.', Followups::remindDue()));
        }
    }

    if ($task === 'all' || $task === 'promises') {
        // Promises are only judged after their date has fully passed.
        if ($task === 'promises' || (int) date('H') === 1) {
            $log(sprintf('Promises swept: %d marked broken or partly kept.', Promises::sweepOverdue()));
        }
    }

    if ($task === 'all' || $task === 'lock') {
        // Close yesterday's register once the day is over.
        if ($task === 'lock' || (int) date('H') === 1) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $log(sprintf('Reports locked for %s: %d.', $yesterday, Deadline::lockOverdue($yesterday)));
        }
    }

    if ($task === 'all' || $task === 'attendance') {
        if ($task === 'attendance' || (int) date('H') === 1) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $log(sprintf('Absentees marked for %s: %d.', $yesterday, Attendance::markAbsentees($yesterday)));
        }
    }

    if ($task === 'all' || $task === 'housekeeping') {
        if ($task === 'housekeeping' || (int) date('H') === 2) {
            $log(sprintf('Expired OTPs purged: %d.', Otp::purgeExpired()));

            // Generated exports are re-creatable on demand; keep the folder small.
            $removed = 0;
            $directory = storage_path('generated');

            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < time() - (14 * 86400)) {
                    @unlink($file);
                    $removed++;
                }
            }

            Database::delete(
                'report_exports',
                'created_at < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - (60 * 86400))]
            );

            $log(sprintf('Old export files removed: %d.', $removed));
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Cron task \"%s\" failed: %s\n", now(), $task, $e->getMessage()));
    App\Core\Logger::error('Cron failure: ' . $e->getMessage());
    exit(1);
}

$log(sprintf('Task "%s" finished in %.2Fs.', $task, microtime(true) - $started));
