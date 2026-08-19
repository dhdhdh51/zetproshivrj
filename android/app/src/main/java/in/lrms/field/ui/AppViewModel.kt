package `in`.lrms.field.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import `in`.lrms.field.ServiceLocator
import `in`.lrms.field.data.local.AccountEntity
import `in`.lrms.field.data.local.AttendanceEntity
import `in`.lrms.field.data.local.NotificationEntity
import `in`.lrms.field.data.local.OutboxEntity
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
            _auth.value = _auth.value.copy(error = "Enter your BCBF code and password.")

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
            onDone("Use at least 8 characters.")

            return
        }

        if (next != confirm) {
            onDone("The two passwords do not match.")

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
                _auth.value = AuthState(signedIn = false, error = "Your session expired. Please sign in again.")
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

    /* ------------------------------------------------------------------ */
    /* Accounts                                                            */
    /* ------------------------------------------------------------------ */

    private val _query = MutableStateFlow("")
    val query: StateFlow<String> = _query.asStateFlow()

    private val _filter = MutableStateFlow("all")
    val filter: StateFlow<String> = _filter.asStateFlow()

    private val _accounts = MutableStateFlow<List<AccountEntity>>(emptyList())
    val accounts: StateFlow<List<AccountEntity>> = _accounts.asStateFlow()

    init {
        // Re-run the query whenever the search text or filter changes.
        viewModelScope.launch {
            kotlinx.coroutines.flow.combine(_query, _filter) { query, filter -> query to filter }
                .collect { (query, filter) ->
                    repository.observeAccounts(query.trim(), filter).collect { list ->
                        _accounts.value = list
                    }
                }
        }
    }

    fun search(text: String) {
        _query.value = text
    }

    fun setFilter(value: String) {
        _filter.value = value
    }

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
            _location.value = LocationState(error = "Location permission is needed to record field work.")
            onResult(null)

            return
        }

        if (!locationCapture.isGpsEnabled()) {
            _location.value = LocationState(error = "Turn on location (GPS) to start a visit.")
            onResult(null)

            return
        }

        _location.value = LocationState(busy = true)

        viewModelScope.launch {
            val fix = locationCapture.awaitFix()

            _location.value = if (fix == null) {
                LocationState(error = "Could not get a GPS fix. Move to an open area and try again.")
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
    @OptIn(ExperimentalCoroutinesApi::class)
    fun formFieldsFor(visitUuid: String) =
        flow { emit(repository.formTypeForVisit(visitUuid)) }
            .flatMapLatest { repository.observeFormFieldsFor(it) }

    fun startVisit(account: AccountEntity, fix: FieldLocation, onStarted: (String) -> Unit) {
        viewModelScope.launch {
            val uuid = repository.startVisit(
                account = account,
                latitude = fix.latitude,
                longitude = fix.longitude,
                accuracy = fix.accuracy,
                address = null,
                isMock = fix.isMock,
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
        recovery: Map<String, Any?>?,
        promise: Map<String, Any?>?,
        followup: Map<String, Any?>?,
        onDone: () -> Unit,
    ) {
        viewModelScope.launch {
            val extras = buildMap<String, Map<String, Any?>> {
                recovery?.let { put("recovery", it) }
                promise?.let { put("promise", it) }
                followup?.let { put("followup", it) }
            }

            repository.queueVisit(
                uuid = uuid,
                visitStatus = visitStatus,
                recoveryPossibility = recoveryPossibility,
                remarks = remarks,
                recommendation = recommendation,
                answers = answers,
                extras = extras,
                borrowerSignature = null,
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

    fun recordRecovery(
        account: AccountEntity,
        amount: Double,
        mode: String,
        receipt: String?,
        remarks: String?,
        onDone: (String) -> Unit,
    ) {
        viewModelScope.launch {
            repository.queueRecovery(account, amount, Times.today(), mode, receipt, remarks)
            requestBackgroundSync()
            onDone("Recovery of ${Times.money(amount)} saved and queued.")
        }
    }

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
            onDone("Promise to pay saved and queued.")
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
            onDone("Follow-up saved and queued.")
        }
    }

    fun checkIn(fix: FieldLocation, selfieBase64: String?, onDone: (String) -> Unit) {
        viewModelScope.launch {
            repository.queueCheckIn(fix.latitude, fix.longitude, fix.accuracy, null, selfieBase64)
            requestBackgroundSync()
            onDone("Checked in at ${Times.timeOnly(Times.nowServerFormat())}.")
        }
    }

    fun checkOut(fix: FieldLocation, onDone: (String) -> Unit) {
        viewModelScope.launch {
            repository.queueCheckOut(fix.latitude, fix.longitude, fix.accuracy, null)
            requestBackgroundSync()
            onDone("Checked out at ${Times.timeOnly(Times.nowServerFormat())}.")
        }
    }

    fun submitDailyReport(summary: String, lateReason: String?, onDone: (String) -> Unit) {
        viewModelScope.launch {
            repository.queueDailyReport(summary, lateReason)
            requestBackgroundSync()
            onDone("Daily report queued for submission.")
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
