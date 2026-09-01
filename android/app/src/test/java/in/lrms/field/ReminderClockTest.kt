package `in`.lrms.field

import `in`.lrms.field.reminders.ReminderClock
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * When a reminder falls due.
 *
 * This is the part of the reminder system worth testing properly rather than by string match:
 * it is arithmetic over a calendar, a timezone and a list of weekdays the Admin can change, and
 * every way of getting it wrong produces a reminder that arrives on the wrong day or not at all
 * — which is indistinguishable, from the field, from the feature not working.
 */
class ReminderClockTest {

    private val kolkata: TimeZone = TimeZone.getTimeZone("Asia/Kolkata")

    /** Monday to Saturday, as the server defaults to. */
    private val monToSat = listOf(1, 2, 3, 4, 5, 6)

    private fun at(date: String, zone: TimeZone = kolkata): Long {
        val format = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.US)
        format.timeZone = zone

        return format.parse(date)!!.time
    }

    private fun show(millis: Long, zone: TimeZone = kolkata): String {
        val format = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.US)
        format.timeZone = zone

        return format.format(millis)
    }

    /* ------------------------------------------------------------------ */
    /* The ordinary case                                                  */
    /* ------------------------------------------------------------------ */

    @Test
    fun `an hour before a six o'clock deadline is five o'clock the same day`() {
        // Monday 24 August 2026, mid-morning.
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-24 09:30"),
            timeOfDay = "18:00",
            minutesBefore = 60,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-24 17:00", show(next!!))
    }

    @Test
    fun `the ten minute warning is ten minutes before`() {
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-24 09:30"),
            timeOfDay = "18:00",
            minutesBefore = 10,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-24 17:50", show(next!!))
    }

    @Test
    fun `a morning reminder fires at the hour itself`() {
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-24 06:00"),
            timeOfDay = "09:00",
            minutesBefore = 0,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-24 09:00", show(next!!))
    }

    /* ------------------------------------------------------------------ */
    /* Moving on when today is spent                                      */
    /* ------------------------------------------------------------------ */

    @Test
    fun `once the moment has passed it moves to the next working day`() {
        // Monday evening, after five o'clock.
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-24 17:30"),
            timeOfDay = "18:00",
            minutesBefore = 60,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-25 17:00", show(next!!))
    }

    @Test
    fun `a reminder exactly now is treated as passed rather than fired twice`() {
        // Arming happens after a reminder fires, so "now" must move forward or the alarm would
        // be set for the instant that has just gone off and the receiver would loop.
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-24 17:00"),
            timeOfDay = "18:00",
            minutesBefore = 60,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-25 17:00", show(next!!))
    }

    /* ------------------------------------------------------------------ */
    /* Working days                                                       */
    /* ------------------------------------------------------------------ */

    @Test
    fun `sunday is skipped when the week runs monday to saturday`() {
        // Saturday 29 August 2026, after the deadline. The next is Monday, not Sunday.
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-29 19:00"),
            timeOfDay = "18:00",
            minutesBefore = 60,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-31 17:00", show(next!!))
    }

    @Test
    fun `a five day week skips both saturday and sunday`() {
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-28 19:00"),
            timeOfDay = "18:00",
            minutesBefore = 60,
            workingDays = listOf(1, 2, 3, 4, 5),
            zone = kolkata,
        )

        assertEquals("2026-08-31 17:00", show(next!!))
    }

    @Test
    fun `a single working day is honoured`() {
        // Only Wednesday. From Thursday that is six days away.
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-27 09:00"),
            timeOfDay = "18:00",
            minutesBefore = 0,
            workingDays = listOf(3),
            zone = kolkata,
        )

        assertEquals("2026-09-02 18:00", show(next!!))
    }

    @Test
    fun `no working days means no reminder rather than one every day`() {
        assertNull(
            ReminderClock.nextOccurrence(
                nowMillis = at("2026-08-24 09:00"),
                timeOfDay = "18:00",
                minutesBefore = 60,
                workingDays = emptyList(),
                zone = kolkata,
            )
        )
    }

    /* ------------------------------------------------------------------ */
    /* The awkward ones                                                   */
    /* ------------------------------------------------------------------ */

    @Test
    fun `a threshold reaching back over midnight is judged against the deadline's own day`() {
        /*
         * report_reminder_minutes is free text in the panel, so 1200 minutes is somebody's to
         * type. Twenty hours before Monday's six o'clock deadline is ten in the evening on
         * Sunday — and Sunday not being a working day must not disqualify it, because the
         * deadline being reminded about is Monday's.
         */
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-23 08:00"),
            timeOfDay = "18:00",
            minutesBefore = 1200,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-23 22:00", show(next!!))
    }

    @Test
    fun `the server's timezone decides, not the phone's`() {
        /*
         * The deadline belongs to the server. A handset set to London must still remind its
         * holder at six in the evening in Kolkata, which is half past one there.
         */
        val next = ReminderClock.nextOccurrence(
            nowMillis = at("2026-08-24 09:00"),
            timeOfDay = "18:00",
            minutesBefore = 0,
            workingDays = monToSat,
            zone = kolkata,
        )

        assertEquals("2026-08-24 18:00", show(next!!, kolkata))
        assertEquals("2026-08-24 13:30", show(next, TimeZone.getTimeZone("Europe/London")))
    }

    @Test
    fun `a malformed time of day gives nothing rather than midnight`() {
        for (bad in listOf("", "18", "18:60", "24:00", "half six", "6 pm", "18:00:00")) {
            assertNull("\"$bad\" should not parse", ReminderClock.parseTimeOfDay(bad))
            assertNull(
                "\"$bad\" should arm nothing",
                ReminderClock.nextOccurrence(
                    nowMillis = at("2026-08-24 09:00"),
                    timeOfDay = bad,
                    minutesBefore = 0,
                    workingDays = monToSat,
                    zone = kolkata,
                )
            )
        }
    }

    @Test
    fun `midnight and one minute to midnight both parse`() {
        assertEquals(0 to 0, ReminderClock.parseTimeOfDay("00:00"))
        assertEquals(23 to 59, ReminderClock.parseTimeOfDay("23:59"))
    }

    /* ------------------------------------------------------------------ */
    /* The day numbering                                                  */
    /* ------------------------------------------------------------------ */

    @Test
    fun `the week is numbered the way the server numbers it`() {
        /*
         * Calendar counts from Sunday = 1; the server's report_working_days is ISO, Monday = 1.
         * Comparing the two directly would remind people on the wrong days and read as the
         * setting being ignored, so the mapping gets its own check.
         */
        val expected = mapOf(
            "2026-08-24" to 1, // Monday
            "2026-08-25" to 2,
            "2026-08-26" to 3,
            "2026-08-27" to 4,
            "2026-08-28" to 5,
            "2026-08-29" to 6, // Saturday
            "2026-08-30" to 7, // Sunday
        )

        for ((date, iso) in expected) {
            val calendar = Calendar.getInstance(kolkata).apply { timeInMillis = at("$date 12:00") }

            assertEquals("ISO day for $date", iso, ReminderClock.isoDayOfWeek(calendar))
        }
    }

    @Test
    fun `every reminder armed is in the future`() {
        // Whatever the settings, an alarm in the past would fire immediately and then re-arm,
        // which is the shape of an endless loop.
        val now = at("2026-08-26 17:55")

        for (minutes in listOf(0, 1, 10, 30, 60, 120, 1200)) {
            for (time in listOf("00:00", "09:00", "18:00", "23:59")) {
                val next = ReminderClock.nextOccurrence(now, time, minutes, monToSat, kolkata)

                if (next != null) {
                    assertTrue("$time minus $minutes should be ahead of now", next > now)
                }
            }
        }
    }
}
