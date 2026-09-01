package `in`.lrms.field.reminders

import java.util.Calendar
import java.util.TimeZone

/**
 * When a daily reminder next falls due.
 *
 * Separated from the alarm plumbing on purpose: this is the part that is easy to get wrong and
 * the part worth testing, and it touches nothing from the Android framework beyond Calendar, so
 * a plain JVM test can reach it.
 *
 * WHOSE CLOCK
 *
 * Everything is worked out in the server's timezone, not the phone's. The deadline is the
 * server's ("the server clock is the only authority here" — App\Services\Deadline), so a handset
 * that has wandered into another zone, or been set to one by hand, still reminds its holder at
 * the hour the branch actually closes.
 *
 * The alarm itself is then set against the wall clock, which somebody could move. That is
 * accepted: an alarm is a courtesy, and the server still decides whether a report was late. What
 * is not accepted is the reminder quietly never arriving, which is why the receiver re-arms after
 * every firing and why a time or timezone change re-arms too.
 */
object ReminderClock {

    /** How many days ahead to look for a working day before giving up. */
    private const val HORIZON_DAYS = 8

    /**
     * The next moment this reminder should fire, or null if there is no working day within the
     * next week — which would mean the working-day list is empty or nonsense.
     *
     * @param nowMillis     the current instant
     * @param timeOfDay     "HH:mm", the hour the thing being reminded about happens
     * @param minutesBefore how long before that to fire; 0 fires at the hour itself
     * @param workingDays   ISO weekday numbers, 1 = Monday
     * @param zone          the zone timeOfDay is expressed in
     */
    fun nextOccurrence(
        nowMillis: Long,
        timeOfDay: String,
        minutesBefore: Int,
        workingDays: List<Int>,
        zone: TimeZone,
    ): Long? {
        val (hour, minute) = parseTimeOfDay(timeOfDay) ?: return null

        if (workingDays.isEmpty()) {
            return null
        }

        /*
         * The working-day test is applied to the day of the thing being reminded about, not to
         * the day the alarm rings. They are usually the same day, but a threshold long enough to
         * reach back over midnight would otherwise be judged against the wrong date — and
         * report_reminder_minutes is a free-text setting in the panel, so a large value is
         * somebody's to type.
         */
        for (offset in 0 until HORIZON_DAYS) {
            val target = Calendar.getInstance(zone).apply {
                timeInMillis = nowMillis
                add(Calendar.DAY_OF_YEAR, offset)
                set(Calendar.HOUR_OF_DAY, hour)
                set(Calendar.MINUTE, minute)
                set(Calendar.SECOND, 0)
                set(Calendar.MILLISECOND, 0)
            }

            if (isoDayOfWeek(target) !in workingDays) {
                continue
            }

            val trigger = target.timeInMillis - minutesBefore * 60_000L

            if (trigger > nowMillis) {
                return trigger
            }
        }

        return null
    }

    /** "HH:mm" to hour and minute, or null if it is not that. */
    fun parseTimeOfDay(value: String): Pair<Int, Int>? {
        val parts = value.trim().split(':')

        if (parts.size != 2) {
            return null
        }

        val hour = parts[0].toIntOrNull() ?: return null
        val minute = parts[1].toIntOrNull() ?: return null

        if (hour !in 0..23 || minute !in 0..59) {
            return null
        }

        return hour to minute
    }

    /**
     * ISO weekday, 1 = Monday through 7 = Sunday.
     *
     * Calendar numbers its own days from Sunday = 1, which does not match the server's
     * report_working_days at all: a plain comparison would remind people on the wrong days and
     * look like the setting was being ignored.
     */
    fun isoDayOfWeek(calendar: Calendar): Int = when (calendar.get(Calendar.DAY_OF_WEEK)) {
        Calendar.MONDAY -> 1
        Calendar.TUESDAY -> 2
        Calendar.WEDNESDAY -> 3
        Calendar.THURSDAY -> 4
        Calendar.FRIDAY -> 5
        Calendar.SATURDAY -> 6
        else -> 7
    }
}
