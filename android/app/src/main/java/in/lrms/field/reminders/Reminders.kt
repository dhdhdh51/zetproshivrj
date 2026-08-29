package `in`.lrms.field.reminders

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import `in`.lrms.field.R
import `in`.lrms.field.ServiceLocator
import `in`.lrms.field.ui.MainActivity
import java.util.TimeZone

/**
 * The phone's own reminders.
 *
 * WHY THE PHONE AND NOT THE SERVER
 *
 * The server already reminds people: a cron writes notification rows sixty, thirty and ten
 * minutes before the daily deadline, and again when a follow-up is due or a promise has gone
 * unkept. Every one of those reached the handset and then sat in a list, silently, because
 * nothing in the app had ever raised a system notification — there was no channel, no alarm, no
 * receiver. A reminder nobody is shown is not a reminder.
 *
 * So two things happen here. Reminders that can be worked out locally are armed as alarms, which
 * means they arrive with no signal — the case that matters most, since a BCA out in a village is
 * exactly the person who misses a deadline. And the server's own notification rows are announced
 * as they arrive, which is what finally makes the follow-up and promise reminders audible.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * No exact-alarm entitlement. USE_EXACT_ALARM is granted automatically but Google restricts it to
 * clock and calendar apps, and a deadline nudge is not one; claiming it risks the listing. So the
 * app asks for SCHEDULE_EXACT_ALARM, uses exact alarms when the user has allowed them, and falls
 * back to an inexact alarm otherwise. An inexact alarm may drift by a few minutes, which for
 * "your report is due in an hour" costs nothing.
 *
 * No foreground service, and no work while signed out. The receiver checks the session first, for
 * the same reason the database is deleted on sign-out: a shared handset must leak nothing.
 */
object Reminders {

    /** One channel. Two would make somebody choose which of their obligations to silence. */
    const val CHANNEL_ID = "lrms-reminders"

    const val EXTRA_KIND = "kind"
    const val EXTRA_MINUTES = "minutes"

    const val KIND_MORNING = "morning"
    const val KIND_DEADLINE = "deadline"

    /*
     * Notification ids. The deadline reminders share one so that sixty, thirty and ten minutes
     * replace each other rather than stacking into three identical-looking warnings; the morning
     * reminder has its own; server rows are offset by their own id so several can coexist.
     */
    const val NOTE_DEADLINE = 2001
    const val NOTE_MORNING = 2002
    private const val NOTE_SERVER_BASE = 3000

    /* Alarm request codes, stable so re-arming replaces rather than duplicates. */
    private const val REQUEST_MORNING = 4100
    private const val REQUEST_DEADLINE_BASE = 4200

    /** Highest number of deadline thresholds armed, so stale ones can be cancelled. */
    private const val MAX_DEADLINE_SLOTS = 8

    /* ------------------------------------------------------------------ */
    /* Channel                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Create the channel. Safe to call repeatedly; Android treats it as an update.
     *
     * Called from LrmsApp.onCreate, after the language has been applied — the channel's name is
     * shown in the phone's own settings, and it is read once at creation, so applying the locale
     * afterwards would leave it in English for good.
     */
    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        val channel = android.app.NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.reminders_channel_name),
            android.app.NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = context.getString(R.string.reminders_channel_description)
            enableVibration(true)
        }

        context.getSystemService(android.app.NotificationManager::class.java)
            ?.createNotificationChannel(channel)
    }

    /* ------------------------------------------------------------------ */
    /* Arming                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Arm the next occurrence of every reminder.
     *
     * One alarm per reminder rather than a week of them: the receiver calls this again after it
     * fires, so the schedule walks itself forward and repairs itself after a reboot, a timezone
     * change, or a sync that moved the deadline.
     */
    fun arm(context: Context) {
        val session = ServiceLocator.session(context)

        if (!session.isSignedIn() || !session.remindersEnabled()) {
            cancel(context)

            return
        }

        ensureChannel(context)

        val manager = context.getSystemService(AlarmManager::class.java) ?: return
        val zone = session.serverTimezone()?.let { TimeZone.getTimeZone(it) } ?: TimeZone.getDefault()
        val workingDays = session.deadlineWorkingDays()
        val now = System.currentTimeMillis()

        // The morning nudge, at the hour the person set.
        ReminderClock.nextOccurrence(now, session.morningReminderTime(), 0, workingDays, zone)?.let {
            set(context, manager, it, REQUEST_MORNING, KIND_MORNING, 0)
        }

        // The deadline, at the panel's own thresholds.
        val minutes = session.deadlineReminderMinutes()
        val deadlineTime = session.deadlineTime()

        minutes.take(MAX_DEADLINE_SLOTS).forEachIndexed { index, threshold ->
            ReminderClock.nextOccurrence(now, deadlineTime, threshold, workingDays, zone)?.let {
                set(context, manager, it, REQUEST_DEADLINE_BASE + index, KIND_DEADLINE, threshold)
            }
        }

        // Thresholds that used to exist and no longer do, or the list got shorter.
        for (index in minutes.size until MAX_DEADLINE_SLOTS) {
            manager.cancel(alarmIntent(context, REQUEST_DEADLINE_BASE + index, KIND_DEADLINE, 0))
        }
    }

    /** Drop every alarm. Called on sign-out and when the switch is turned off. */
    fun cancel(context: Context) {
        val manager = context.getSystemService(AlarmManager::class.java) ?: return

        manager.cancel(alarmIntent(context, REQUEST_MORNING, KIND_MORNING, 0))

        for (index in 0 until MAX_DEADLINE_SLOTS) {
            manager.cancel(alarmIntent(context, REQUEST_DEADLINE_BASE + index, KIND_DEADLINE, 0))
        }

        NotificationManagerCompat.from(context).apply {
            cancel(NOTE_DEADLINE)
            cancel(NOTE_MORNING)
        }
    }

    private fun set(
        context: Context,
        manager: AlarmManager,
        atMillis: Long,
        requestCode: Int,
        kind: String,
        minutes: Int,
    ) {
        val intent = alarmIntent(context, requestCode, kind, minutes)

        /*
         * Exact where it is allowed, inexact where it is not. canScheduleExactAlarms() is the only
         * honest way to ask: on Android 12 the permission is granted by default and on 13 and up
         * it is not, and calling setExact without it throws.
         */
        val exact = Build.VERSION.SDK_INT < Build.VERSION_CODES.S || manager.canScheduleExactAlarms()

        if (exact) {
            manager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, atMillis, intent)
        } else {
            manager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, atMillis, intent)
        }
    }

    private fun alarmIntent(context: Context, requestCode: Int, kind: String, minutes: Int): PendingIntent {
        val intent = Intent(context, ReminderReceiver::class.java).apply {
            action = ACTION_FIRE
            putExtra(EXTRA_KIND, kind)
            putExtra(EXTRA_MINUTES, minutes)
        }

        return PendingIntent.getBroadcast(
            context,
            requestCode,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }

    const val ACTION_FIRE = "in.lrms.field.REMINDER"

    /* ------------------------------------------------------------------ */
    /* Showing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Raise a notification, or do nothing if the user never granted permission.
     *
     * areNotificationsEnabled() is checked rather than assumed: POST_NOTIFICATIONS has been in the
     * manifest since the first release and was never requested, so until this change every
     * notification this app might have raised on Android 13 or later would have been dropped in
     * silence.
     */
    fun raise(context: Context, id: Int, title: String, body: String) {
        val notifications = NotificationManagerCompat.from(context)

        if (!notifications.areNotificationsEnabled()) {
            return
        }

        val open = PendingIntent.getActivity(
            context,
            id,
            Intent(context, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val note = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_stat_reminder)
            .setContentTitle(title)
            .setContentText(body)
            // So a two-line message is readable without opening anything.
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(open)
            .build()

        runCatching { notifications.notify(id, note) }
    }

    /** A distinct notification id for a server row, so several can be on screen at once. */
    fun serverNoteId(rowId: Long): Int = NOTE_SERVER_BASE + (rowId % 500).toInt()

    /* ------------------------------------------------------------------ */
    /* What the settings screen needs to say                              */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this phone will let the app set an exact alarm.
     *
     * Worth showing, because the difference is visible: without it a reminder can drift by
     * several minutes, and somebody watching the clock at ten to six would otherwise conclude
     * the app had failed.
     */
    fun canBeExact(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) {
            return true
        }

        return context.getSystemService(AlarmManager::class.java)?.canScheduleExactAlarms() ?: false
    }

    /**
     * The soonest reminder that is armed, as something a person can read, or null if none is.
     *
     * Computed from the same settings and the same clock the alarms are set from, rather than by
     * asking AlarmManager — which cannot be queried. If this says a time and nothing arrives, the
     * fault is in the delivery and not in the arithmetic, which is worth being able to tell apart.
     */
    fun describeNext(context: Context): String? {
        val session = ServiceLocator.session(context)

        if (!session.isSignedIn() || !session.remindersEnabled()) {
            return null
        }

        val zone = session.serverTimezone()?.let { TimeZone.getTimeZone(it) } ?: TimeZone.getDefault()
        val workingDays = session.deadlineWorkingDays()
        val now = System.currentTimeMillis()

        val candidates = mutableListOf<Long>()

        ReminderClock.nextOccurrence(now, session.morningReminderTime(), 0, workingDays, zone)
            ?.let { candidates += it }

        session.deadlineReminderMinutes().forEach { threshold ->
            ReminderClock.nextOccurrence(now, session.deadlineTime(), threshold, workingDays, zone)
                ?.let { candidates += it }
        }

        val soonest = candidates.minOrNull() ?: return null

        val format = java.text.SimpleDateFormat("EEE d MMM, HH:mm", java.util.Locale.getDefault())
        format.timeZone = zone

        return format.format(java.util.Date(soonest))
    }
}
