package `in`.lrms.field

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * The wiring around the reminders.
 *
 * String matching again, and for the same reason as SssTest: none of this fails to build. A
 * receiver missing from the manifest, a permission not declared, or a check for the signed-in
 * session left out all compile perfectly and then misbehave on somebody's handset — the alarms
 * simply stop after the first reboot, or a shared phone starts naming the last holder's work.
 */
class RemindersTest {

    private fun source(path: String): String {
        val candidates = listOf(File(path), File("app/$path"), File("../app/$path"))

        val file = candidates.firstOrNull { it.isFile }
            ?: throw AssertionError(
                "Cannot find $path from ${File(".").absolutePath}. " +
                    "This test must never pass without reading the file it is about."
            )

        return file.readText()
    }

    private val manifest by lazy { source("src/main/AndroidManifest.xml") }

    /* ------------------------------------------------------------------ */
    /* The manifest                                                       */
    /* ------------------------------------------------------------------ */

    @Test
    fun `both receivers are declared and neither is exported`() {
        assertTrue(
            "the alarm receiver must be declared or no reminder is ever delivered",
            manifest.contains(".reminders.ReminderReceiver"),
        )
        assertTrue(
            "the boot receiver must be declared or the alarms die at the first restart",
            manifest.contains(".reminders.BootReceiver"),
        )

        // An exported receiver would let any app on the phone fake a deadline warning.
        val receivers = Regex("<receiver[\\s\\S]*?</receiver>").findAll(manifest).map { it.value }.toList()

        assertTrue("expected two receivers, found ${receivers.size}", receivers.size >= 2)

        for (receiver in receivers) {
            assertTrue(
                "every receiver must set exported=false:\n$receiver",
                receiver.contains("android:exported=\"false\""),
            )
        }
    }

    @Test
    fun `the alarms are put back after the events that clear them`() {
        for (action in listOf(
            "android.intent.action.BOOT_COMPLETED",
            "android.intent.action.TIME_SET",
            "android.intent.action.TIMEZONE_CHANGED",
            "android.intent.action.MY_PACKAGE_REPLACED",
        )) {
            assertTrue("$action must re-arm the reminders", manifest.contains(action))
        }

        assertTrue(
            "listening for boot needs the permission",
            manifest.contains("android.permission.RECEIVE_BOOT_COMPLETED"),
        )
    }

    @Test
    fun `the app asks to schedule exact alarms and does not claim the entitlement`() {
        assertTrue(
            manifest.contains("android.permission.SCHEDULE_EXACT_ALARM"),
        )

        /*
         * USE_EXACT_ALARM is granted without asking, which makes it tempting. Google restricts it
         * to alarm clocks and calendars, and a deadline nudge is neither — claiming it puts the
         * listing at risk for a few minutes of accuracy.
         */
        assertFalse(
            "USE_EXACT_ALARM is for clock and calendar apps, which this is not",
            manifest.contains("android.permission.USE_EXACT_ALARM"),
        )
    }

    @Test
    fun `the notification permission is requested and not merely declared`() {
        assertTrue(manifest.contains("android.permission.POST_NOTIFICATIONS"))

        // Declaring it does nothing on Android 13 and later: unrequested, every notification is
        // dropped in silence.
        val activity = source("src/main/java/in/lrms/field/ui/MainActivity.kt")

        assertTrue(
            "POST_NOTIFICATIONS must actually be requested at runtime",
            activity.contains("Manifest.permission.POST_NOTIFICATIONS"),
        )
        assertTrue(
            "and only on the versions that have it",
            activity.contains("Build.VERSION_CODES.TIRAMISU"),
        )
    }

    /* ------------------------------------------------------------------ */
    /* The scheduler                                                      */
    /* ------------------------------------------------------------------ */

    private val reminders by lazy { source("src/main/java/in/lrms/field/reminders/Reminders.kt") }

    @Test
    fun `an exact alarm is only set when the phone allows one`() {
        assertTrue(
            "must ask before setting an exact alarm — setExact throws without permission",
            reminders.contains("canScheduleExactAlarms()"),
        )
        assertTrue(reminders.contains("setExactAndAllowWhileIdle"))
        assertTrue(
            "and must fall back rather than crash or give up",
            reminders.contains("setAndAllowWhileIdle"),
        )
    }

    @Test
    fun `nothing is armed for a handset nobody is signed in on`() {
        assertTrue(
            "arming must check the session, as SyncWorker does",
            reminders.contains("isSignedIn()"),
        )

        val receiver = source("src/main/java/in/lrms/field/reminders/ReminderReceiver.kt")

        assertTrue(
            "and so must firing: a shared handset must not name the last holder's work",
            receiver.contains("isSignedIn()"),
        )
        assertTrue(
            "a receiver that finds nobody signed in should drop the alarms too",
            receiver.contains("Reminders.cancel(context)"),
        )
    }

    @Test
    fun `the schedule walks itself forward`() {
        val receiver = source("src/main/java/in/lrms/field/reminders/ReminderReceiver.kt")

        // One alarm is armed at a time, so if firing did not re-arm there would be exactly one
        // reminder ever.
        assertTrue(
            "the receiver must arm the next occurrence after firing",
            receiver.contains("Reminders.arm(context)"),
        )
    }

    @Test
    fun `a deadline reminder is suppressed once the report is in`() {
        val receiver = source("src/main/java/in/lrms/field/reminders/ReminderReceiver.kt")

        assertTrue(
            "reminding somebody to do what they have done reads as the app losing their work",
            receiver.contains("dailyReportFiledOn()"),
        )

        val repository = source("src/main/java/in/lrms/field/data/repo/FieldRepository.kt")

        assertTrue(
            "and the day has to be recorded when the report is filed",
            repository.contains("setDailyReportFiledOn"),
        )
    }

    @Test
    fun `the panel's own settings drive the alarms`() {
        /*
         * The server has sent working_days and reminder_minutes since the first release and the
         * app threw them away, which is why it could show a countdown but never raise a warning.
         * Hard-coding them here instead would mean changing the deadline in the panel moved the
         * countdown and not the reminders.
         */
        val dtos = source("src/main/java/in/lrms/field/data/remote/Dtos.kt")

        assertTrue(dtos.contains("\"working_days\""))
        assertTrue(dtos.contains("\"reminder_minutes\""))
        assertTrue(dtos.contains("\"server_timezone\""))

        assertTrue(reminders.contains("deadlineReminderMinutes()"))
        assertTrue(reminders.contains("deadlineWorkingDays()"))
        assertTrue(
            "the deadline belongs to the server, so its zone decides",
            reminders.contains("serverTimezone()"),
        )
    }

    /* ------------------------------------------------------------------ */
    /* The server's own reminders                                         */
    /* ------------------------------------------------------------------ */

    @Test
    fun `the server's notification rows are announced once each`() {
        val repository = source("src/main/java/in/lrms/field/data/repo/FieldRepository.kt")

        assertTrue(
            "follow-up and promise reminders arrive as notification rows and must be shown",
            repository.contains("announce("),
        )
        assertTrue(
            "a high-water mark stops forty rows being re-announced every fifteen minutes",
            repository.contains("lastAnnouncedNotificationId()"),
        )
        assertTrue(repository.contains("setLastAnnouncedNotificationId"))
    }

    /* ------------------------------------------------------------------ */
    /* The notification itself                                            */
    /* ------------------------------------------------------------------ */

    @Test
    fun `the status bar icon is a silhouette and not the launcher mark`() {
        /*
         * Android keeps only the alpha of a small icon, so the launcher emblem — gold ring, blue
         * field, a handshake — would come out as one white blob.
         */
        assertTrue(reminders.contains("R.drawable.ic_stat_reminder"))

        val icon = source("src/main/res/drawable/ic_stat_reminder.xml")

        assertTrue("must be a vector, sized 24dp as the platform expects", icon.contains("24dp"))
        assertTrue(icon.contains("#FFFFFF"))
    }

    @Test
    fun `the channel is created before anything tries to notify`() {
        val app = source("src/main/java/in/lrms/field/LrmsApp.kt")

        assertTrue(app.contains("Reminders.ensureChannel(this)"))

        /*
         * After the language is applied. A channel's name is read once, at creation, and shown in
         * the phone's settings for good — created first, it would be stuck in English.
         */
        val languageAt = app.indexOf("AppLanguage.apply")
        val channelAt = app.indexOf("Reminders.ensureChannel")

        assertTrue("the language must be applied first", languageAt in 0 until channelAt)
    }

    @Test
    fun `reminders are dropped on sign-out beside the sync worker`() {
        val viewModel = source("src/main/java/in/lrms/field/ui/AppViewModel.kt")

        assertTrue(viewModel.contains("Reminders.cancel(getApplication())"))
        assertTrue(viewModel.contains("Reminders.arm(getApplication())"))
    }

    /* ------------------------------------------------------------------ */
    /* Wording                                                            */
    /* ------------------------------------------------------------------ */

    @Test
    fun `every reminder string is in both languages`() {
        val english = source("src/main/res/values/strings.xml")
        val hindi = source("src/main/res/values-hi/strings.xml")

        val keys = listOf(
            "reminders_channel_name",
            "reminders_channel_description",
            "reminders_title",
            "reminders_note",
            "reminders_enabled",
            "reminders_morning_time",
            "reminders_next",
            "reminders_next_none",
            "reminders_blocked",
            "reminders_inexact",
            "reminder_deadline_title",
            "reminder_morning_title",
            "reminder_morning_clear",
        )

        for (key in keys) {
            assertTrue("$key missing from English", english.contains("name=\"$key\""))
            // lint's MissingTranslation is switched off in build.gradle.kts, so nothing else
            // would notice a Hindi string that was never written.
            assertTrue("$key missing from Hindi", hindi.contains("name=\"$key\""))
        }

        for (key in listOf(
            "reminder_deadline_minutes",
            "reminder_deadline_hours",
            "reminder_morning_pending",
        )) {
            assertTrue("$key plural missing from English", english.contains("name=\"$key\""))
            assertTrue("$key plural missing from Hindi", hindi.contains("name=\"$key\""))
        }
    }
}
