package `in`.lrms.field.reminders

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import `in`.lrms.field.R
import `in`.lrms.field.ServiceLocator
import `in`.lrms.field.util.Times
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * An alarm has gone off: say something, then arm the next one.
 *
 * Re-arming here rather than scheduling a week in advance is what makes the schedule survive
 * everything that can move it — the panel changing the deadline, the working days changing, a
 * threshold being added. Each firing sets up the next from whatever the settings now say.
 */
class ReminderReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Reminders.ACTION_FIRE) {
            return
        }

        val session = ServiceLocator.session(context)

        /*
         * Nothing is said on a handset nobody is signed in on. The database is deleted at
         * sign-out precisely so a shared phone leaks nothing, and a reminder naming somebody
         * else's work would undo that. Alarms are dropped too, so this does not fire again.
         */
        if (!session.isSignedIn() || !session.remindersEnabled()) {
            Reminders.cancel(context)

            return
        }

        val kind = intent.getStringExtra(Reminders.EXTRA_KIND) ?: Reminders.KIND_DEADLINE
        val minutes = intent.getIntExtra(Reminders.EXTRA_MINUTES, 0)

        // The pending count needs the database, which must not be touched on this thread.
        val pending = goAsync()

        CoroutineScope(Dispatchers.IO).launch {
            try {
                when (kind) {
                    Reminders.KIND_MORNING -> morning(context)
                    else -> deadline(context, minutes)
                }

                Reminders.arm(context)
            } finally {
                pending.finish()
            }
        }
    }

    /**
     * The deadline warning, unless the day's report is already in.
     *
     * Somebody who has filed gets nothing: a reminder to do what you have done reads as the app
     * not having registered it, and the next one is ignored. This is the same suppression the
     * server's own cron applies, which skips anyone with a submitted report for the day.
     */
    private fun deadline(context: Context, minutes: Int) {
        val session = ServiceLocator.session(context)

        if (session.dailyReportFiledOn() == Times.today()) {
            return
        }

        val body = if (minutes >= 60 && minutes % 60 == 0) {
            val hours = minutes / 60
            context.resources.getQuantityString(R.plurals.reminder_deadline_hours, hours, hours)
        } else {
            context.resources.getQuantityString(R.plurals.reminder_deadline_minutes, minutes, minutes)
        }

        Reminders.raise(
            context,
            Reminders.NOTE_DEADLINE,
            context.getString(R.string.reminder_deadline_title),
            body,
        )
    }

    /** The morning nudge, naming what is still unvisited so it says something worth reading. */
    private suspend fun morning(context: Context) {
        val repository = ServiceLocator.repository(context)
        val pending = runCatching { repository.pendingAccountCount() }.getOrDefault(0)

        val body = if (pending > 0) {
            context.resources.getQuantityString(R.plurals.reminder_morning_pending, pending, pending)
        } else {
            context.getString(R.string.reminder_morning_clear)
        }

        Reminders.raise(
            context,
            Reminders.NOTE_MORNING,
            context.getString(R.string.reminder_morning_title),
            body,
        )
    }
}
