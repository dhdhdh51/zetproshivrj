package `in`.lrms.field.ui

import `in`.lrms.field.R
import `in`.lrms.field.util.Localised
import android.app.Application
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import `in`.lrms.field.ServiceLocator
import `in`.lrms.field.data.local.AccountEntity
import `in`.lrms.field.data.local.AttendanceEntity
import `in`.lrms.field.data.local.NotificationEntity
import `in`.lrms.field.data.local.OutboxEntity
import `in`.lrms.field.data.local.SssEntity
import `in`.lrms.field.data.local.VisitEntity
import `in`.lrms.field.data.repo.FieldRepository
import `in`.lrms.field.location.FieldLocation
import `in`.lrms.field.location.LocationCapture
import `in`.lrms.field.sync.SyncWorker
import `in`.lrms.field.util.Times
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.io.File

/**
 * One view model for the whole app.
 *
 * The app is small and every screen shares the same session, sync state and
 * account cache, so a single model keeps the wiring obvious and avoids passing
 * repositories through the navigation graph.
 */
class AppViewModel(application: Application) : AndroidViewModel(application) {

    private val repository: FieldRepository = ServiceLocator.repository(application)
    private val locationCapture = LocationCapture(application)

    val session get() = repository.store

    /**
     * A message for the supervisor, in the app's language.
     *
     * Resolved through [Localised] rather than getApplication().getString, because the
     * application context can still be serving the locale it was created with — see
     * the note on that object. These strings end up on screen next to ones a
     * composable resolved, and the two must not disagree about the language.
     */
    private fun message(@StringRes id: Int, vararg args: Any): String =
        Localised.string(getApplication<Application>(), id, *args)

    /* ------------------------------------------------------------------ */
    /* Session                                                             */
    /* ------------------------------------------------------------------ */

    data class AuthState(
        val signedIn: Boolean = false,
        val busy: Boolean = false,
        val error: String? = null,
        val otpUserId: Long? = null,
        val otpMessage: String? = null,
        val mustChangePassword: Boolean = false,
        /** Result of the sign-in screen's connection test, when one has been run. */
        val diagnostic: String? = null,
        val testing: Boolean = false,
    )

    private val _auth = MutableStateFlow(
        AuthState(
            signedIn = session.isSignedIn(),
            mustChangePassword = session.mustChangePassword(),
        ),
    )
    val auth: StateFlow<AuthState> = _auth.asStateFlow()

    /**
     * Runs the server check from the sign-in screen, so a supervisor who cannot
     * sign in can read out what actually failed instead of "no connection".
     */
    fun testConnection() {
        if (_auth.value.testing) {
            return
        }

        _auth.value = _auth.value.copy(testing = true, diagnostic = null)

        viewModelScope.launch {
            val result = repository.testConnection()

            _auth.value = _auth.value.copy(testing = false, diagnostic = result)
        }
    }

    fun signIn(username: String, password: String) {
        if (username.isBlank() || password.isBlank()) {
            _auth.value = _auth.value.copy(error = message(R.string.msg_enter_credentials))

            return
        }

        _auth.value = _auth.value.copy(busy = true, error = null, diagnostic = null)

        viewModelScope.launch {
            when (val outcome = repository.login(username, password)) {
                FieldRepository.LoginOutcome.Success -> {
                    _auth.value = AuthState(signedIn = true, mustChangePassword = session.mustChangePassword())
                    SyncWorker.schedule(getApplication())
                    syncNow()
                }

                is FieldRepository.LoginOutcome.OtpRequired -> {
                    _auth.value = AuthState(
                        signedIn = false,
                        otpUserId = outcome.userId,
                        otpMessage = outcome.message,
                    )
                }

                is FieldRepository.LoginOutcome.Error -> {
                    _auth.value = _auth.value.copy(busy = false, error = outcome.message)
                }
            }
        }
    }

    fun verifyOtp(code: String) {
        val userId = _auth.value.otpUserId ?: return

        _auth.value = _auth.value.copy(busy = true, error = null)

        viewModelScope.launch {
            when (val outcome = repository.verifyOtp(userId, code)) {
                FieldRepository.LoginOutcome.Success -> {
                    _auth.value = AuthState(signedIn = true, mustChangePassword = session.mustChangePassword())
                    SyncWorker.schedule(getApplication())
                    syncNow()
                }

                is FieldRepository.LoginOutcome.OtpRequired ->
                    _auth.value = _auth.value.copy(busy = false, otpMessage = outcome.message)

                is FieldRepository.LoginOutcome.Error ->
                    _auth.value = _auth.value.copy(busy = false, error = outcome.message)
            }
        }
    }

    fun cancelOtp() {
        _auth.value = AuthState(signedIn = false)
    }

    fun signOut() {
        viewModelScope.launch {
            SyncWorker.cancelAll(getApplication())
            repository.logout()
            ServiceLocator.reset()
            _auth.value = AuthState(signedIn = false)
        }
    }

    fun changePassword(current: String, next: String, confirm: String, onDone: (String?) -> Unit) {
        if (next.length < 8) {
            onDone(message(R.string.msg_password_too_short))

            return
        }

        if (next != confirm) {
            onDone(message(R.string.msg_passwords_differ))

            return
        }

        viewModelScope.launch {
            val error = repository.changePassword(current, next)

            if (error == null) {
                _auth.value = _auth.value.copy(mustChangePassword = false)
            }

            onDone(error)
        }
    }

    /* ------------------------------------------------------------------ */
    /* Sync state                                                          */
    /* ------------------------------------------------------------------ */

    data class SyncState(
        val busy: Boolean = false,
        val message: String? = null,
        val offline: Boolean = false,
    )

    private val _sync = MutableStateFlow(SyncState(message = session.lastSyncMessage()))
    val sync: StateFlow<SyncState> = _sync.asStateFlow()

    fun syncNow() {
        if (_sync.value.busy) {
            return
        }

        _sync.value = SyncState(busy = true, message = "Synchronising…")

        viewModelScope.launch {
            val report = repository.sync()

            if (report.unauthenticated) {
                _auth.value = AuthState(signedIn = false, error = message(R.string.msg_session_expired))
                session.clear()
            }

            _sync.value = SyncState(busy = false, message = report.message, offline = report.offline)
        }
    }

    /** Queue-and-forget: WorkManager retries in the background. */
    private fun requestBackgroundSync() {
        SyncWorker.syncNow(getApplication())
    }

    val pendingVisits: StateFlow<Int> = repository.observePendingVisits()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    val pendingOutbox: StateFlow<Int> = repository.observePendingOutbox()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    val outbox: StateFlow<List<OutboxEntity>> = repository.observeOutbox()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    val pendingAccounts: StateFlow<Int> = repository.observePendingAccounts()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    val todaysVisits: StateFlow<List<VisitEntity>> = repository.observeVisitsForDate(Times.today())
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    val notifications: StateFlow<List<NotificationEntity>> = repository.observeNotifications()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    val unreadNotifications: StateFlow<Int> = repository.observeUnreadNotifications()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    val attendance: StateFlow<AttendanceEntity?> = repository.observeAttendance(Times.today())
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), null)

    /** Today's Social Security Scheme figures, whatever was last recorded on this device. */
    val sssToday: StateFlow<SssEntity?> = repository.observeSss(Times.today())
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), null)

    val sssMonthTotal: StateFlow<Int> = repository.observeSssMonthTotal(Times.today())
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    /* ------------------------------------------------------------------ */
    /* Accounts                                                            */
    /* ------------------------------------------------------------------ */

    private val _query = MutableStateFlow("")
    val query: StateFlow<String> = _query.asStateFlow()

    /** Work stream: all, krm_ots, ckcc_od2, general. Its own row of chips. */
    private val _stream = MutableStateFlow("all")
    val stream: StateFlow<String> = _stream.asStateFlow()

    /** Visit status: all, pending, visited, ptp. A separate question. */
    private val _status = MutableStateFlow("all")
    val status: StateFlow<String> = _status.asStateFlow()

    private val _accounts = MutableStateFlow<List<AccountEntity>>(emptyList())
    val accounts: StateFlow<List<AccountEntity>> = _accounts.asStateFlow()

    init {
        // Re-run the query whenever the search text or either filter changes.
        viewModelScope.launch {
            kotlinx.coroutines.flow.combine(_query, _stream, _status) { query, stream, status ->
                Triple(query, stream, status)
            }
                .collectLatest { (query, stream, status) ->
                    repository.observeAccounts(query.trim(), stream, status).collect { list ->
                        _accounts.value = list
                    }
                }
        }
    }

    fun search(text: String) {
        _query.value = text
    }

    fun setStream(value: String) {
        _stream.value = value
    }

    fun setStatus(value: String) {
        _status.value = value
    }

    /** Live count for a stream chip, so the supervisor sees how much work is in each. */
    fun streamCount(stream: String) = repository.observeStreamCount(stream)

    suspend fun account(id: Long): AccountEntity? = repository.account(id)

    /* ------------------------------------------------------------------ */
    /* Location                                                            */
    /* ------------------------------------------------------------------ */

    data class LocationState(
        val busy: Boolean = false,
        val fix: FieldLocation? = null,
        val error: String? = null,
    )

    private val _location = MutableStateFlow(LocationState())
    val location: StateFlow<LocationState> = _location.asStateFlow()

    fun captureLocation(onResult: (FieldLocation?) -> Unit = {}) {
        if (!locationCapture.hasPermission()) {
            _location.value = LocationState(error = message(R.string.msg_location_permission))
            onResult(null)

            return
        }

        if (!locationCapture.isGpsEnabled()) {
            _location.value = LocationState(error = message(R.string.msg_location_off))
            onResult(null)

            return
        }

        _location.value = LocationState(busy = true)

        viewModelScope.launch {
            val fix = locationCapture.awaitFix()

            _location.value = if (fix == null) {
                LocationState(error = message(R.string.msg_no_gps_fix))
            } else {
                LocationState(fix = fix)
            }

            if (fix != null) {
                // Keeps the monitoring screen's "last known" position fresh.
                repository.reportLocation(fix.latitude, fix.longitude, fix.accuracy, null)
            }

            onResult(fix)
        }
    }

    fun clearLocation() {
        _location.value = LocationState()
    }

    /* ------------------------------------------------------------------ */
    /* Visits                                                              */
    /* ------------------------------------------------------------------ */

    fun observeVisit(uuid: String) = repository.observeVisit(uuid)

    fun observePhotos(uuid: String) = repository.observePhotos(uuid)

    fun observeFormFields() = repository.observeFormFields()

    /**
     * The form for this visit, chosen from the account's work stream so a KRM OTS
     * or CKCC OD-2 case asks the questions its printed report expects.
     */
    /**
     * The form for this visit, following the case type chosen on the visit screen.
     *
     * Keyed off observeVisitType so changing the case type mid-form swaps the
     * questions immediately, rather than after leaving and reopening the screen.
     */
    @OptIn(ExperimentalCoroutinesApi::class)
    fun formFieldsFor(visitUuid: String) =
        repository.observeVisitType(visitUuid)
            .flatMapLatest { caseType ->
                flow { emit(repository.formTypeFor(caseType)) }
                    .flatMapLatest { repository.observeFormFieldsFor(it) }
            }

    fun observeVisitType(visitUuid: String) = repository.observeVisitType(visitUuid)

    fun setVisitType(visitUuid: String, caseType: String) {
        viewModelScope.launch { repository.setVisitType(visitUuid, caseType) }
    }

    fun startVisit(account: AccountEntity, fix: FieldLocation, onStarted: (String) -> Unit) {
        viewModelScope.launch {
            val uuid = repository.startVisit(
                account = account,
                latitude = fix.latitude,
                longitude = fix.longitude,
                accuracy = fix.accuracy,
                address = null,
                isMock = fix.isMock,
                // Starts on the account's own work stream; the supervisor can
                // change it on the visit screen if this doorstep is something
                // else — a pre-NPA check on a KRM OTS account, say.
                visitType = repository.defaultCaseTypeFor(account),
            )

            onStarted(uuid)
        }
    }

    fun addPhoto(
        visitUuid: String,
        file: File,
        photoType: String,
        caption: String?,
        fix: FieldLocation?,
    ) {
        viewModelScope.launch {
            repository.addPhoto(
                visitUuid = visitUuid,
                file = file,
                photoType = photoType,
                caption = caption,
                latitude = fix?.latitude,
                longitude = fix?.longitude,
                accuracy = fix?.accuracy,
                address = null,
            )
        }
    }

    fun submitVisit(
        uuid: String,
        visitStatus: String,
        recoveryPossibility: String?,
        remarks: String,
        recommendation: String?,
        answers: Map<String, String>,
        promise: Map<String, Any?>?,
        followup: Map<String, Any?>?,
        onDone: () -> Unit,
    ) {
        viewModelScope.launch {
            val extras = buildMap<String, Map<String, Any?>> {
                promise?.let { put("promise", it) }
                followup?.let { put("followup", it) }
            }

            // Read the location again, here, at the moment the report is filed. The
            // fix taken when the visit was started can be an hour and a village old
            // by now. A short window because the agent is waiting on this tap, and
            // awaitFix returns a good cached fix immediately when it has one; if
            // nothing comes back the visit is still filed, with the last fix this
            // screen saw, or with none. Location has never been a condition of
            // filing a report and is not becoming one here.
            val submitFix = locationCapture.awaitFix(timeoutMillis = 8_000)
                ?: _location.value.fix

            repository.queueVisit(
                uuid = uuid,
                visitStatus = visitStatus,
                recoveryPossibility = recoveryPossibility,
                remarks = remarks,
                recommendation = recommendation,
                answers = answers,
                extras = extras,
                borrowerSignature = null,
                submitFix = submitFix,
            )

            requestBackgroundSync()
            onDone()
        }
    }

    fun discardVisit(uuid: String, onDone: () -> Unit) {
        viewModelScope.launch {
            repository.discardVisit(uuid)
            onDone()
        }
    }

    /* ------------------------------------------------------------------ */
    /* Standalone entries                                                  */
    /* ------------------------------------------------------------------ */

    fun recordPromise(
        account: AccountEntity,
        amount: Double,
        promiseDate: String,
        remarks: String?,
        onDone: (String) -> Unit,
    ) {
        viewModelScope.launch {
            repository.queuePromise(account, amount, promiseDate, null, remarks)
            requestBackgroundSync()
            onDone(message(R.string.msg_promise_saved))
        }
    }

    fun recordFollowup(
        account: AccountEntity,
        date: String,
        action: String,
        notes: String?,
        onDone: (String) -> Unit,
    ) {
        viewModelScope.launch {
            repository.queueFollowup(account, date, action, notes)
            requestBackgroundSync()
            onDone(message(R.string.msg_followup_saved))
        }
    }

    fun checkIn(fix: FieldLocation, selfieBase64: String?, onDone: (String) -> Unit) {
        viewModelScope.launch {
            repository.queueCheckIn(fix.latitude, fix.longitude, fix.accuracy, null, selfieBase64)
            requestBackgroundSync()
            onDone(message(R.string.msg_checked_in, Times.timeOnly(Times.nowServerFormat())))
        }
    }

    fun checkOut(fix: FieldLocation, onDone: (String) -> Unit) {
        viewModelScope.launch {
            repository.queueCheckOut(fix.latitude, fix.longitude, fix.accuracy, null)
            requestBackgroundSync()
            onDone(message(R.string.msg_checked_out, Times.timeOnly(Times.nowServerFormat())))
        }
    }

    fun submitDailyReport(summary: String, lateReason: String?, onDone: (String) -> Unit) {
        viewModelScope.launch {
            repository.queueDailyReport(summary, lateReason)
            requestBackgroundSync()
            onDone(message(R.string.msg_report_queued))
        }
    }

    /**
     * Record or correct today's SSS enrolments.
     *
     * A blank box is a zero, because a scheme with no enrolments that day is a real answer
     * and making somebody type four zeros to say "nothing" invites them to skip the screen
     * altogether. Anything unreadable is also a zero rather than a refusal — the rest of
     * the day's figures are worth keeping.
     */
    fun submitSss(
        apy: String,
        pmjjby: String,
        pmsby: String,
        pmjdy: String,
        remarks: String,
        onDone: (String) -> Unit,
    ) {
        viewModelScope.launch {
            val date = Times.today()
            val counts = listOf(apy, pmjjby, pmsby, pmjdy).map { it.trim().toIntOrNull() ?: 0 }

            repository.queueSss(
                date = date,
                apy = counts[0],
                pmjjby = counts[1],
                pmsby = counts[2],
                pmjdy = counts[3],
                remarks = remarks.trim().ifBlank { null },
            )
            requestBackgroundSync()
            onDone(message(R.string.msg_sss_queued, counts.sum()))
        }
    }

    fun markNotificationRead(id: Long) {
        viewModelScope.launch { repository.markNotificationRead(id) }
    }

    fun markAllNotificationsRead() {
        viewModelScope.launch { repository.markAllNotificationsRead() }
    }

    /* ------------------------------------------------------------------ */
    /* Deadline                                                            */
    /* ------------------------------------------------------------------ */

    data class DeadlineState(
        val deadlineAt: String?,
        val secondsRemaining: Long,
        val locked: Boolean,
    )

    fun deadlineState(): DeadlineState = DeadlineState(
        deadlineAt = session.deadlineAt(),
        secondsRemaining = session.deadlineSecondsRemaining(),
        locked = session.deadlineLocked() || session.deadlineSecondsRemaining() == 0L,
    )
}
