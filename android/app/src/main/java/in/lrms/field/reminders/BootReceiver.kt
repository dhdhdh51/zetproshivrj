package `in`.lrms.field.reminders

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Put the alarms back after the things that silently remove them.
 *
 * Android drops every alarm an app has set when the device restarts, and says nothing about it.
 * Without this the reminders would work perfectly until the first reboot and then never again,
 * which is the kind of fault that gets reported as "it worked for a week".
 *
 * A time or timezone change matters for the same reason in the other direction: the alarms were
 * set against absolute instants worked out in the server's zone, so moving the clock leaves them
 * pointing at the wrong moment. Re-arming recomputes them from the settings.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_TIME_CHANGED,
            Intent.ACTION_TIMEZONE_CHANGED,
            // Sent after the app is updated, when alarms are also cleared.
            Intent.ACTION_MY_PACKAGE_REPLACED,
            -> Reminders.arm(context)
        }
    }
}
