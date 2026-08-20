package `in`.lrms.field.data.prefs

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import android.os.SystemClock
import java.util.UUID

/**
 * Credentials and session state.
 *
 * The token, the bound device id and the supervisor's identity are kept in
 * EncryptedSharedPreferences so a lost or rooted handset does not hand over a
 * working session. If the keystore is unavailable (some heavily modified ROMs),
 * the store falls back to plain preferences rather than making the app unusable —
 * the token is short lived and server-revocable either way.
 */
class SessionStore(context: Context) {

    private val prefs: SharedPreferences = try {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        EncryptedSharedPreferences.create(
            context,
            "lrms-session",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (e: Exception) {
        context.getSharedPreferences("lrms-session-fallback", Context.MODE_PRIVATE)
    }

    fun token(): String? = prefs.getString(KEY_TOKEN, null)

    fun isSignedIn(): Boolean = !token().isNullOrBlank()

    /**
     * A stable identifier for this installation. It is created once and reused, so
     * the server can bind the account to this device and detect a different one.
     */
    fun deviceUuid(): String {
        prefs.getString(KEY_DEVICE, null)?.let { return it }

        val generated = UUID.randomUUID().toString()
        prefs.edit().putString(KEY_DEVICE, generated).apply()

        return generated
    }

    fun saveSession(
        token: String,
        expiresAt: String?,
        userId: Long,
        userName: String,
        username: String?,
        supervisorId: Long,
        bcCode: String,
        branchName: String?,
        mustChangePassword: Boolean,
    ) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putString(KEY_EXPIRES, expiresAt)
            .putLong(KEY_USER_ID, userId)
            .putString(KEY_USER_NAME, userName)
            .putString(KEY_USERNAME, username)
            .putLong(KEY_SUPERVISOR_ID, supervisorId)
            .putString(KEY_BC_CODE, bcCode)
            .putString(KEY_BRANCH, branchName)
            .putBoolean(KEY_MUST_CHANGE, mustChangePassword)
            .apply()
    }

    fun userName(): String = prefs.getString(KEY_USER_NAME, "") ?: ""

    fun username(): String = prefs.getString(KEY_USERNAME, "") ?: ""

    fun bcCode(): String = prefs.getString(KEY_BC_CODE, "") ?: ""

    fun branchName(): String = prefs.getString(KEY_BRANCH, "") ?: ""

    fun supervisorId(): Long = prefs.getLong(KEY_SUPERVISOR_ID, 0L)

    fun mustChangePassword(): Boolean = prefs.getBoolean(KEY_MUST_CHANGE, false)

    fun setMustChangePassword(value: Boolean) {
        prefs.edit().putBoolean(KEY_MUST_CHANGE, value).apply()
    }

    fun lastSyncAt(): String? = prefs.getString(KEY_LAST_SYNC, null)

    fun setLastSyncAt(value: String?) {
        prefs.edit().putString(KEY_LAST_SYNC, value).apply()
    }

    fun lastSyncMessage(): String? = prefs.getString(KEY_LAST_SYNC_MESSAGE, null)

    fun setLastSyncMessage(value: String?) {
        prefs.edit().putString(KEY_LAST_SYNC_MESSAGE, value).apply()
    }

    /** Cached deadline so the countdown survives going offline. */
    fun saveDeadline(deadlineAt: String?, serverTime: String?, secondsRemaining: Long, locked: Boolean) {
        prefs.edit()
            .putString(KEY_DEADLINE_AT, deadlineAt)
            .putLong(KEY_DEADLINE_SECONDS, secondsRemaining)
            .putLong(KEY_DEADLINE_SYNCED_ELAPSED, monotonicMillis())
            .putBoolean(KEY_DEADLINE_LOCKED, locked)
            .putString(KEY_SERVER_TIME, serverTime)
            .apply()
    }

    fun deadlineAt(): String? = prefs.getString(KEY_DEADLINE_AT, null)

    fun deadlineLocked(): Boolean = prefs.getBoolean(KEY_DEADLINE_LOCKED, false)

    /**
     * Seconds left, counted down using the monotonic clock since the last sync.
     *
     * The device wall clock is deliberately not used: a supervisor could change it
     * to appear inside the deadline. The server has the final say in any case.
     */
    fun deadlineSecondsRemaining(): Long {
        val storedSeconds = prefs.getLong(KEY_DEADLINE_SECONDS, -1L)

        if (storedSeconds < 0) {
            return -1L
        }

        val syncedAtElapsed = prefs.getLong(KEY_DEADLINE_SYNCED_ELAPSED, 0L)
        val elapsedSinceSync = (monotonicMillis() - syncedAtElapsed) / 1000

        return (storedSeconds - elapsedSinceSync).coerceAtLeast(0L)
    }

    /**
     * The Admin's SSS target, cached so the screen still shows what is expected with no
     * signal.
     *
     * Kept here rather than beside the day's figures because a day nobody has enrolled
     * anybody on has no row to hang it from, and that is exactly the moment a supervisor
     * wants to know the number. Stamped with the date it was fetched for so a stale target
     * from last week is recognisable as stale rather than shown as today's.
     */
    fun saveSssTarget(
        date: String,
        targetSet: Boolean,
        dayTarget: Int,
        monthTarget: Int,
        monthWorkingDays: Int,
    ) {
        prefs.edit()
            .putString(KEY_SSS_TARGET_DATE, date)
            .putBoolean(KEY_SSS_TARGET_SET, targetSet)
            .putInt(KEY_SSS_TARGET_DAY, dayTarget)
            .putInt(KEY_SSS_TARGET_MONTH, monthTarget)
            .putInt(KEY_SSS_TARGET_WORKING_DAYS, monthWorkingDays)
            .apply()
    }

    fun sssTargetDate(): String = prefs.getString(KEY_SSS_TARGET_DATE, "") ?: ""

    fun sssTargetSet(): Boolean = prefs.getBoolean(KEY_SSS_TARGET_SET, false)

    fun sssDayTarget(): Int = prefs.getInt(KEY_SSS_TARGET_DAY, 0)

    fun sssMonthTarget(): Int = prefs.getInt(KEY_SSS_TARGET_MONTH, 0)

    fun sssMonthWorkingDays(): Int = prefs.getInt(KEY_SSS_TARGET_WORKING_DAYS, 0)

    /** Keeps the device id so a re-install is recognised, clears everything else. */
    /**
     * The chosen app language as a BCP-47 tag, or "" to follow the phone.
     *
     * Survives sign-out: the language someone reads in is a property of the person
     * holding the handset, not of the session, and making them pick it again after
     * every sign-out would be a small daily insult.
     */
    fun languageTag(): String = prefs.getString(KEY_LANGUAGE, "") ?: ""

    fun setLanguageTag(tag: String) {
        prefs.edit().putString(KEY_LANGUAGE, tag).apply()
    }

    fun clear() {
        val device = deviceUuid()
        val language = languageTag()

        prefs.edit()
            .clear()
            .putString(KEY_DEVICE, device)
            .putString(KEY_LANGUAGE, language)
            .apply()
    }

    private companion object {
        const val KEY_TOKEN = "token"
        const val KEY_EXPIRES = "expires_at"
        const val KEY_DEVICE = "device_uuid"
        const val KEY_USER_ID = "user_id"
        const val KEY_USER_NAME = "user_name"
        const val KEY_USERNAME = "username"
        const val KEY_SUPERVISOR_ID = "supervisor_id"
        const val KEY_BC_CODE = "bc_code"
        const val KEY_BRANCH = "branch_name"
        const val KEY_MUST_CHANGE = "must_change_password"
        const val KEY_LAST_SYNC = "last_sync_at"
        const val KEY_LAST_SYNC_MESSAGE = "last_sync_message"
        const val KEY_DEADLINE_AT = "deadline_at"
        const val KEY_DEADLINE_SECONDS = "deadline_seconds"
        const val KEY_DEADLINE_SYNCED_ELAPSED = "deadline_synced_elapsed"
        const val KEY_DEADLINE_LOCKED = "deadline_locked"
        const val KEY_SERVER_TIME = "server_time"
        const val KEY_LANGUAGE = "language_tag"
        const val KEY_SSS_TARGET_DATE = "sss_target_date"
        const val KEY_SSS_TARGET_SET = "sss_target_set"
        const val KEY_SSS_TARGET_DAY = "sss_target_day"
        const val KEY_SSS_TARGET_MONTH = "sss_target_month"
        const val KEY_SSS_TARGET_WORKING_DAYS = "sss_target_working_days"
    }
}

/** Monotonic clock, isolated here so it can be replaced in unit tests. */
internal fun monotonicMillis(): Long = android.os.SystemClock.elapsedRealtime()
