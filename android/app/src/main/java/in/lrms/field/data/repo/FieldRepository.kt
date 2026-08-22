package `in`.lrms.field.data.repo

import `in`.lrms.field.R
import `in`.lrms.field.util.Localised
import android.content.Context
import `in`.lrms.field.BuildConfig
import `in`.lrms.field.data.local.AccountEntity
import `in`.lrms.field.data.local.AppDatabase
import `in`.lrms.field.data.local.AttendanceEntity
import `in`.lrms.field.data.local.FormFieldEntity
import `in`.lrms.field.data.local.NotificationEntity
import `in`.lrms.field.data.local.OutboxEntity
import `in`.lrms.field.data.local.OutboxType
import `in`.lrms.field.data.local.PhotoEntity
import `in`.lrms.field.data.local.SssEntity
import `in`.lrms.field.data.local.SssStatus
import `in`.lrms.field.data.local.SyncState
import `in`.lrms.field.data.local.VisitEntity
import `in`.lrms.field.data.prefs.SessionStore
import `in`.lrms.field.data.remote.ApiClient
import `in`.lrms.field.data.remote.ApiResult
import `in`.lrms.field.data.remote.ApiService
import `in`.lrms.field.data.remote.DeviceInfo
import `in`.lrms.field.data.remote.GpsPayload
import `in`.lrms.field.data.remote.LoginRequest
import `in`.lrms.field.data.remote.OtpRequest
import `in`.lrms.field.data.remote.SyncItem
import `in`.lrms.field.data.remote.SyncPushRequest
import `in`.lrms.field.location.FieldLocation
import `in`.lrms.field.util.Json
import `in`.lrms.field.util.Network
import `in`.lrms.field.util.Times
import `in`.lrms.field.util.newUuid
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.withContext
import java.io.File

/**
 * The single place the UI talks to.
 *
 * Reads come from Room so screens work with no connection; writes go to Room
 * first and are drained by the sync worker. That ordering is what makes the app
 * usable in villages with no signal — nothing is ever lost because a request
 * failed.
 */
class FieldRepository(
    private val context: Context,
    private val db: AppDatabase,
    private val api: ApiService,
    private val session: SessionStore,
) {

    /* ------------------------------------------------------------------ */
    /* Authentication                                                      */
    /* ------------------------------------------------------------------ */

    sealed class LoginOutcome {
        data object Success : LoginOutcome()
        data class OtpRequired(val userId: Long, val message: String, val destination: String?) : LoginOutcome()
        data class Error(val message: String) : LoginOutcome()
    }

    private fun deviceInfo() = DeviceInfo(
        uuid = session.deviceUuid(),
        model = android.os.Build.MODEL,
        manufacturer = android.os.Build.MANUFACTURER,
        osVersion = android.os.Build.VERSION.RELEASE,
        appVersion = BuildConfig.VERSION_NAME,
    )

    suspend fun login(username: String, password: String): LoginOutcome = withContext(Dispatchers.IO) {
        when (val result = ApiClient.call { api.login(LoginRequest(username.trim(), password, deviceInfo())) }) {
            is ApiResult.Success -> {
                val data = result.data

                if (data.otpRequired) {
                    LoginOutcome.OtpRequired(
                        userId = data.userId ?: 0L,
                        message = data.message ?: Localised.string(context, R.string.msg_enter_otp),
                        destination = data.destination,
                    )
                } else {
                    storeSession(result.data)
                    LoginOutcome.Success
                }
            }

            is ApiResult.Failure -> LoginOutcome.Error(result.message)

            is ApiResult.Offline -> LoginOutcome.Error(
                // Pass the real reason through. Replacing it with a generic "no
                // connection" is what sends someone looking for a signal when the
                // actual problem is a certificate or an unresolvable host.
                when (result.reason) {
                    ApiResult.Reason.NO_NETWORK, ApiResult.Reason.DNS ->
                        result.message + " Signing in needs a network the first time."

                    else -> result.message
                },
            )

            ApiResult.Unauthenticated ->
                LoginOutcome.Error(Localised.string(context, R.string.msg_bad_credentials))
        }
    }

    /**
     * Checks the server from the handset that is actually failing.
     *
     * A BCA standing in a village cannot read a log, and nobody can fix
     * "no connection" without knowing which step broke. This calls the unauthenticated
     * ping endpoint and reports plainly whether the phone reached the server, so the
     * answer comes back over the phone in one sentence.
     */
    suspend fun testConnection(): String = withContext(Dispatchers.IO) {
        when (val result = ApiClient.call { api.ping() }) {
            is ApiResult.Success -> "Server reached: ${result.data.app ?: "LRMS"}. Sign-in should work."
            is ApiResult.Offline -> result.message
            is ApiResult.Failure ->
                Localised.string(context, R.string.msg_server_error, ApiClient.host, result.message)
            ApiResult.Unauthenticated ->
                Localised.string(context, R.string.msg_server_refused, ApiClient.host)
        }
    }

    suspend fun verifyOtp(userId: Long, code: String): LoginOutcome = withContext(Dispatchers.IO) {
        when (val result = ApiClient.call { api.verifyOtp(OtpRequest(userId, code.trim(), deviceInfo())) }) {
            is ApiResult.Success -> {
                storeSession(result.data)
                LoginOutcome.Success
            }

            is ApiResult.Failure -> LoginOutcome.Error(result.message)
            is ApiResult.Offline -> LoginOutcome.Error(Localised.string(context, R.string.msg_no_connection))
            ApiResult.Unauthenticated -> LoginOutcome.Error(Localised.string(context, R.string.msg_otp_rejected))
        }
    }

    private fun storeSession(data: `in`.lrms.field.data.remote.LoginData) {
        val token = data.token ?: return

        session.saveSession(
            token = token,
            expiresAt = data.expiresAt,
            userId = data.user?.id ?: 0L,
            userName = data.user?.name ?: "",
            username = data.user?.username,
            supervisorId = data.supervisor?.id ?: 0L,
            bcCode = data.supervisor?.bcCode ?: "",
            branchName = data.supervisor?.branchName,
            mustChangePassword = data.user?.mustChangePassword ?: false,
        )
    }

    suspend fun logout(): Unit = withContext(Dispatchers.IO) {
        // Best effort: revoke server-side, then clear the device either way.
        runCatching { ApiClient.call { api.logout() } }

        session.clear()
        AppDatabase.destroy(context)
    }

    suspend fun changePassword(current: String, next: String): String? = withContext(Dispatchers.IO) {
        val result = ApiClient.call {
            api.changePassword(
                `in`.lrms.field.data.remote.ChangePasswordRequest(current, next, next),
            )
        }

        when (result) {
            is ApiResult.Success -> {
                session.setMustChangePassword(false)
                null
            }

            is ApiResult.Failure -> result.message
            is ApiResult.Offline -> Localised.string(context, R.string.msg_password_needs_connection)
            ApiResult.Unauthenticated -> Localised.string(context, R.string.msg_sign_in_again)
        }
    }

    /* ------------------------------------------------------------------ */
    /* Local reads                                                         */
    /* ------------------------------------------------------------------ */

    fun observeAccounts(query: String, stream: String, status: String): Flow<List<AccountEntity>> =
        db.accounts().observe(query, stream, status)

    fun observeStreamCount(stream: String): Flow<Int> = db.accounts().observeStreamCount(stream)

    suspend fun account(id: Long): AccountEntity? = withContext(Dispatchers.IO) { db.accounts().find(id) }

    fun observePendingAccounts(): Flow<Int> = db.accounts().observePendingCount()

    fun observeVisitsForDate(date: String): Flow<List<VisitEntity>> = db.visits().observeForDate(date)

    fun observeRecentVisits(): Flow<List<VisitEntity>> = db.visits().observeRecent()

    fun observeVisit(uuid: String): Flow<VisitEntity?> = db.visits().observe(uuid)

    fun observePhotos(uuid: String): Flow<List<PhotoEntity>> = db.photos().observeForVisit(uuid)

    fun observePhotoCount(uuid: String): Flow<Int> = db.photos().observeCountForVisit(uuid)

    fun observePendingVisits(): Flow<Int> = db.visits().observePendingCount()

    fun observePendingOutbox(): Flow<Int> = db.outbox().observePendingCount()

    fun observeOutbox(): Flow<List<OutboxEntity>> = db.outbox().observeRecent()

    fun observeVisitCountForDate(date: String): Flow<Int> = db.visits().observeCountForDate(date)

    fun observeNotifications(): Flow<List<NotificationEntity>> = db.notifications().observe()

    fun observeUnreadNotifications(): Flow<Int> = db.notifications().observeUnreadCount()

    fun observeFormFields(): Flow<List<FormFieldEntity>> = db.forms().observe()

    /**
     * Which form a visit must be recorded on.
     *
     * A KRM OTS or CKCC OD-2 account is verified on its own 13-section form; the
     * generic customer form is for everything else. Falls back to the customer
     * form when the stream's form has not reached the handset yet, because an
     * empty form would leave a supervisor standing in front of a borrower with
     * nothing to fill in.
     */
    suspend fun formTypeForVisit(visitUuid: String): String = withContext(Dispatchers.IO) {
        formTypeFor(db.visits().visitTypeOf(visitUuid))
    }

    /**
     * The form a case type is recorded on.
     *
     * Only KRM OTS and CKCC OD-2 have forms of their own. Recovery Follow-up,
     * Pre-NPA and Post-NPA are verification calls that use the customer form —
     * they differ in what the report is *for*, not in what is asked at the door.
     * Falls back to the customer form when a stream's form has not synced yet,
     * because an empty form would leave a supervisor standing in front of a
     * borrower with nothing to fill in.
     */
    suspend fun formTypeFor(caseType: String?): String = withContext(Dispatchers.IO) {
        val type = if (caseType == "krm_ots" || caseType == "ckcc_od2") caseType else "customer"

        if (db.forms().countForType(type) > 0) type else "customer"
    }

    /** The case type default for a new visit, from the account's work stream. */
    fun defaultCaseTypeFor(account: AccountEntity): String = when (account.loanCategory) {
        "krm_ots" -> "krm_ots"
        "ckcc_od2" -> "ckcc_od2"
        else -> "customer"
    }

    fun observeVisitType(visitUuid: String): Flow<String?> = db.visits().observeVisitType(visitUuid)

    suspend fun setVisitType(visitUuid: String, caseType: String) = withContext(Dispatchers.IO) {
        db.visits().setVisitType(visitUuid, caseType, System.currentTimeMillis())
    }

    fun observeFormFieldsFor(visitType: String): Flow<List<FormFieldEntity>> =
        db.forms().observeForType(visitType)

    fun observeAttendance(date: String): Flow<AttendanceEntity?> = db.attendance().observe(date)

    fun observeSss(date: String): Flow<SssEntity?> = db.sss().observe(date)

    /** Month-to-date total for the month the given day falls in. */
    fun observeSssMonthTotal(date: String): Flow<Int> =
        db.sss().observeMonthTotal(date.take(7) + "%")

    suspend fun formFields(): List<FormFieldEntity> = withContext(Dispatchers.IO) { db.forms().all() }

    suspend fun accountCount(): Int = withContext(Dispatchers.IO) { db.accounts().count() }

    /* ------------------------------------------------------------------ */
    /* Visits — written locally, then queued                               */
    /* ------------------------------------------------------------------ */

    /**
     * Starts a visit on the device. GPS is mandatory here too, so a supervisor
     * cannot begin a visit the server would later refuse.
     */
    suspend fun startVisit(
        account: AccountEntity,
        latitude: Double,
        longitude: Double,
        accuracy: Double?,
        address: String?,
        isMock: Boolean,
        visitType: String = "customer",
    ): String = withContext(Dispatchers.IO) {
        val uuid = newUuid()
        val now = System.currentTimeMillis()

        db.visits().upsert(
            VisitEntity(
                uuid = uuid,
                accountId = account.id,
                accountNumber = account.accountNumber,
                borrowerName = account.borrowerName,
                visitDate = Times.today(),
                startedAt = Times.nowServerFormat(),
                visitType = visitType,
                visitStatus = null,
                recoveryPossibility = null,
                remarks = null,
                recommendation = null,
                formJson = "{}",
                latitude = latitude,
                longitude = longitude,
                accuracy = accuracy,
                locationAddress = address,
                isMock = isMock,
                borrowerSignature = null,
                supervisorSignature = null,
                extrasJson = null,
                syncState = SyncState.DRAFT,
                syncMessage = null,
                attempts = 0,
                serverVisitId = null,
                submittedLocally = false,
                createdAt = now,
                updatedAt = now,
            ),
        )

        uuid
    }

    suspend fun addPhoto(
        visitUuid: String,
        file: File,
        photoType: String,
        caption: String?,
        latitude: Double?,
        longitude: Double?,
        accuracy: Double?,
        address: String?,
    ): Unit = withContext(Dispatchers.IO) {
        db.photos().insert(
            PhotoEntity(
                visitUuid = visitUuid,
                photoType = photoType,
                filePath = file.absolutePath,
                caption = caption,
                latitude = latitude,
                longitude = longitude,
                accuracy = accuracy,
                address = address,
                capturedAt = Times.nowServerFormat(),
                syncState = SyncState.PENDING,
                serverPhotoId = null,
            ),
        )
    }

    suspend fun removePhoto(photo: PhotoEntity): Unit = withContext(Dispatchers.IO) {
        runCatching { File(photo.filePath).delete() }
        db.photos().delete(photo.localId)
    }

    /**
     * Marks the visit ready to send. Everything captured is stored, so the queue
     * survives the app being killed.
     *
     * @param extras promise / followup payloads to submit with the visit
     */
    suspend fun queueVisit(
        uuid: String,
        visitStatus: String,
        recoveryPossibility: String?,
        remarks: String?,
        recommendation: String?,
        answers: Map<String, String>,
        extras: Map<String, Map<String, Any?>>,
        borrowerSignature: String?,
        submitFix: FieldLocation?,
    ): Unit = withContext(Dispatchers.IO) {
        val visit = db.visits().find(uuid) ?: return@withContext

        db.visits().upsert(
            visit.copy(
                visitStatus = visitStatus,
                recoveryPossibility = recoveryPossibility,
                remarks = remarks,
                recommendation = recommendation,
                formJson = Json.encodeStringMap(answers),
                extrasJson = if (extras.isEmpty()) null else Json.encodeAny(extras),
                borrowerSignature = borrowerSignature,
                submitLatitude = submitFix?.latitude,
                submitLongitude = submitFix?.longitude,
                submitAccuracy = submitFix?.accuracy,
                submitIsMock = submitFix?.isMock ?: false,
                submitCapturedAt = submitFix?.let { Times.serverFormat(it.capturedAtMillis) },
                syncState = SyncState.PENDING,
                syncMessage = null,
                submittedLocally = true,
                updatedAt = System.currentTimeMillis(),
            ),
        )
    }

    suspend fun discardVisit(uuid: String): Unit = withContext(Dispatchers.IO) {
        db.photos().forVisit(uuid).forEach { runCatching { File(it.filePath).delete() } }
        db.visits().delete(uuid)
    }

    /* ------------------------------------------------------------------ */
    /* Outbox items                                                        */
    /* ------------------------------------------------------------------ */

    private suspend fun queue(type: String, accountId: Long?, label: String, payload: Map<String, Any?>): String {
        val uuid = (payload["uuid"] as? String) ?: newUuid()

        db.outbox().upsert(
            OutboxEntity(
                uuid = uuid,
                type = type,
                accountId = accountId,
                label = label,
                payloadJson = Json.encodeAny(payload + mapOf("uuid" to uuid)),
                syncState = SyncState.PENDING,
                syncMessage = null,
                attempts = 0,
                createdAt = System.currentTimeMillis(),
                updatedAt = System.currentTimeMillis(),
            ),
        )

        return uuid
    }

    suspend fun queuePromise(
        account: AccountEntity,
        amount: Double,
        promiseDate: String,
        followupDate: String?,
        remarks: String?,
        visitUuid: String? = null,
    ): String = withContext(Dispatchers.IO) {
        queue(
            OutboxType.PROMISE,
            account.id,
            "PTP ${Times.money(amount)} by $promiseDate — ${account.borrowerName}",
            mapOf(
                "loan_account_id" to account.id,
                "promise_amount" to amount,
                "promise_date" to promiseDate,
                "followup_date" to followupDate,
                "remarks" to remarks,
                "visit_uuid" to visitUuid,
            ),
        )
    }

    suspend fun queueFollowup(
        account: AccountEntity,
        date: String,
        action: String,
        notes: String?,
        visitUuid: String? = null,
    ): String = withContext(Dispatchers.IO) {
        queue(
            OutboxType.FOLLOWUP,
            account.id,
            "Follow-up ($action) on $date — ${account.borrowerName}",
            mapOf(
                "loan_account_id" to account.id,
                "followup_date" to date,
                "action" to action,
                "notes" to notes,
                "visit_uuid" to visitUuid,
            ),
        )
    }

    suspend fun queueCheckIn(
        latitude: Double,
        longitude: Double,
        accuracy: Double?,
        address: String?,
        selfieBase64: String?,
    ): Unit = withContext(Dispatchers.IO) {
        val date = Times.today()

        db.attendance().upsert(
            AttendanceEntity(
                date = date,
                checkInAt = Times.nowServerFormat(),
                checkOutAt = null,
                workingMinutes = 0,
                visitsCount = 0,
                status = "present",
                syncState = SyncState.PENDING,
            ),
        )

        queue(
            OutboxType.ATTENDANCE_IN,
            null,
            "Check in at ${Times.timeOnly(Times.nowServerFormat())}",
            mapOf(
                "gps" to mapOf(
                    "latitude" to latitude,
                    "longitude" to longitude,
                    "accuracy" to accuracy,
                    "address" to address,
                    "captured_at" to Times.nowServerFormat(),
                ),
                "selfie" to selfieBase64,
                "check_in_at" to Times.nowServerFormat(),
            ),
        )
    }

    suspend fun queueCheckOut(
        latitude: Double,
        longitude: Double,
        accuracy: Double?,
        address: String?,
    ): Unit = withContext(Dispatchers.IO) {
        val date = Times.today()
        val existing = db.attendance().find(date)

        if (existing != null) {
            db.attendance().upsert(
                existing.copy(
                    checkOutAt = Times.nowServerFormat(),
                    syncState = SyncState.PENDING,
                ),
            )
        }

        queue(
            OutboxType.ATTENDANCE_OUT,
            null,
            "Check out at ${Times.timeOnly(Times.nowServerFormat())}",
            mapOf(
                "gps" to mapOf(
                    "latitude" to latitude,
                    "longitude" to longitude,
                    "accuracy" to accuracy,
                    "address" to address,
                    "captured_at" to Times.nowServerFormat(),
                ),
                "check_out_at" to Times.nowServerFormat(),
            ),
        )
    }

    suspend fun queueDailyReport(summary: String, lateReason: String?): Unit = withContext(Dispatchers.IO) {
        queue(
            OutboxType.DAILY_REPORT,
            null,
            "Daily report for ${Times.today()}",
            mapOf(
                "report_date" to Times.today(),
                "summary" to summary,
                "late_reason" to lateReason,
            ),
        )
    }

    /**
     * Record or correct a day's Social Security Scheme enrolments.
     *
     * Written locally first so the screen shows the figures whether or not there is a
     * signal, then queued. The uuid is reused for the day if one already exists, so a
     * supervisor who types four and corrects it to five while still offline replaces the
     * queued entry rather than adding a second one — `queue()` upserts on the uuid, and
     * the server treats (supervisor, day) as the natural key for the same reason.
     */
    suspend fun queueSss(
        date: String,
        apy: Int,
        pmjjby: Int,
        pmsby: Int,
        pmjdy: Int,
        remarks: String?,
    ): Unit = withContext(Dispatchers.IO) {
        val existing = db.sss().find(date)
        val uuid = existing?.uuid ?: newUuid()
        val total = apy + pmjjby + pmsby + pmjdy

        db.sss().upsert(
            SssEntity(
                date = date,
                uuid = uuid,
                apyCount = apy,
                pmjjbyCount = pmjjby,
                pmsbyCount = pmsby,
                pmjdyCount = pmjdy,
                remarks = remarks,
                syncState = SyncState.PENDING,
                // Whatever the server last said about the day is carried across. If an
                // Admin re-opened it, this is the submission that re-opening bought, and
                // losing the state here would lock the fields again while the correction
                // was still sitting in the queue.
                status = existing?.status ?: SssStatus.SUBMITTED,
                // A fresh attempt carries no complaint from the last one.
                syncMessage = null,
            ),
        )

        queue(
            OutboxType.SSS,
            null,
            Localised.string(context, R.string.sss_outbox_label, date, total),
            mapOf(
                "uuid" to uuid,
                "enrolment_date" to date,
                "apy_count" to apy,
                "pmjjby_count" to pmjjby,
                "pmsby_count" to pmsby,
                "pmjdy_count" to pmjdy,
                "remarks" to remarks,
            ),
        )
    }

    /* ------------------------------------------------------------------ */
    /* Notifications                                                       */
    /* ------------------------------------------------------------------ */

    suspend fun markNotificationRead(id: Long): Unit = withContext(Dispatchers.IO) {
        db.notifications().markRead(id)
        runCatching { ApiClient.call { api.readNotification(id) } }
    }

    suspend fun markAllNotificationsRead(): Unit = withContext(Dispatchers.IO) {
        db.notifications().markAllRead()
        runCatching { ApiClient.call { api.readAllNotifications() } }
    }

    /* ------------------------------------------------------------------ */
    /* Synchronisation                                                     */
    /* ------------------------------------------------------------------ */

    data class SyncReport(
        val pulled: Int = 0,
        val pushed: Int = 0,
        val duplicates: Int = 0,
        val failed: Int = 0,
        val offline: Boolean = false,
        val unauthenticated: Boolean = false,
        val message: String = "",
    )

    /**
     * Drains the outbox and refreshes the local cache. Called by the sync worker
     * and by the manual "Sync now" action.
     */
    suspend fun sync(): SyncReport = withContext(Dispatchers.IO) {
        if (!session.isSignedIn()) {
            return@withContext SyncReport(unauthenticated = true, message = Localised.string(context, R.string.msg_not_signed_in))
        }

        var pushed = 0
        var duplicates = 0
        var failed = 0

        // 1. Push queued work first: recording it matters more than refreshing.
        val visits = db.visits().pending()
        val outbox = db.outbox().pending()

        if (visits.isNotEmpty() || outbox.isNotEmpty()) {
            val items = mutableListOf<SyncItem>()

            for (visit in visits) {
                items += SyncItem(type = "visit", uuid = visit.uuid, payload = visitPayload(visit))
                db.visits().markSync(visit.uuid, SyncState.SYNCING, null, visit.serverVisitId, System.currentTimeMillis())
            }

            for (item in outbox) {
                items += SyncItem(
                    type = item.type,
                    uuid = item.uuid,
                    payload = Json.decodeMap(item.payloadJson),
                )
                db.outbox().markSync(item.uuid, SyncState.SYNCING, null, System.currentTimeMillis())
            }

            val request = SyncPushRequest(
                batchUuid = newUuid(),
                appVersion = BuildConfig.VERSION_NAME,
                networkType = Network.type(context),
                items = items,
            )

            when (val result = ApiClient.call { api.push(request) }) {
                is ApiResult.Success -> {
                    val now = System.currentTimeMillis()

                    for (outcome in result.data.results) {
                        val uuid = outcome.uuid ?: continue
                        val isVisit = outcome.type == "visit"

                        val state = when (outcome.status) {
                            "accepted", "duplicate" -> SyncState.SYNCED
                            else -> if (outcome.retryable) SyncState.FAILED else SyncState.REJECTED
                        }

                        if (isVisit) {
                            db.visits().markSync(uuid, state, outcome.message, outcome.id, now)

                            if (state == SyncState.SYNCED) {
                                db.photos().forVisit(uuid).forEach { photo ->
                                    db.photos().markSync(photo.localId, SyncState.SYNCED, null)
                                }
                            }
                        } else {
                            db.outbox().markSync(uuid, state, outcome.message, now)

                            // An SSS day carries its outcome onto the day itself, not only
                            // onto the queue row. Without this the supervisor sees "waiting
                            // to be sent" on the SSS screen for ever after a refusal, and
                            // the only place the reason appears is the outbox list — which
                            // is not where they are looking when they wonder why their
                            // correction did nothing.
                            if (outcome.type == OutboxType.SSS) {
                                db.sss().markSync(uuid, state, outcome.message)
                            }
                        }
                    }

                    pushed = result.data.accepted
                    duplicates = result.data.duplicates
                    failed = result.data.failed

                    result.data.deadline?.let { cacheDeadline(it) }
                }

                is ApiResult.Offline -> {
                    // Put everything back in the queue untouched.
                    resetSyncing(visits.map { it.uuid }, outbox.map { it.uuid })

                    return@withContext SyncReport(offline = true, message = Localised.wrap(context).resources.getQuantityString(
                        R.plurals.msg_offline_waiting, items.size, items.size
                    ))
                }

                ApiResult.Unauthenticated -> {
                    resetSyncing(visits.map { it.uuid }, outbox.map { it.uuid })

                    return@withContext SyncReport(unauthenticated = true, message = Localised.string(context, R.string.msg_session_expired))
                }

                is ApiResult.Failure -> {
                    resetSyncing(visits.map { it.uuid }, outbox.map { it.uuid })

                    return@withContext SyncReport(failed = items.size, message = result.message)
                }
            }
        }

        // 2. Pull the refreshed cache.
        var pulled = 0

        when (val result = ApiClient.call { api.pull(session.lastSyncAt()) }) {
            is ApiResult.Success -> {
                val data = result.data

                if (data.accounts.isNotEmpty()) {
                    db.accounts().upsertAll(
                        data.accounts.map { dto ->
                            AccountEntity(
                                id = dto.id,
                                accountNumber = dto.accountNumber,
                                cif = dto.cif,
                                borrowerName = dto.borrowerName,
                                fatherName = dto.fatherName,
                                mobile = dto.mobile,
                                village = dto.village,
                                address = dto.address,
                                loanType = dto.loanType,
                                sanctionDate = dto.sanctionDate,
                                npaDate = dto.npaDate,
                                limitAmount = dto.limitAmount,
                                outstanding = dto.outstanding,
                                overdue = dto.overdue,
                                totalRecovered = dto.totalRecovered,
                                loanCategory = dto.loanCategory,
                                recoveryStatus = dto.recoveryStatus,
                                visitCount = dto.visitCount,
                                lastVisitAt = dto.lastVisitAt,
                                branchName = dto.branchName,
                                branchCode = dto.branchCode,
                                updatedAt = dto.updatedAt,
                            )
                        },
                    )
                }

                if (data.removedAccountIds.isNotEmpty()) {
                    db.accounts().deleteByIds(data.removedAccountIds)
                }

                // Newer servers send every form; an older one sends only the
                // customer form, and that is still better than none.
                val forms = data.visitForms.ifEmpty { listOfNotNull(data.visitForm) }
                    .filter { it.fields.isNotEmpty() }

                if (forms.isNotEmpty()) {
                    db.forms().clear()

                    forms.forEach { form ->
                        db.forms().upsertAll(
                            form.fields.map { field ->
                                FormFieldEntity(
                                    visitType = form.visitType,
                                    fieldKey = field.key,
                                    label = field.label,
                                    type = field.type,
                                    required = field.required,
                                    options = field.options.joinToString("\n").ifBlank { null },
                                    placeholder = field.placeholder,
                                    help = field.help,
                                    sortOrder = field.order,
                                    conditionField = field.condition?.field,
                                    conditionOperator = field.condition?.operator,
                                    conditionValue = field.condition?.value,
                                )
                            },
                        )
                    }
                }

                if (data.notifications.isNotEmpty()) {
                    db.notifications().upsertAll(
                        data.notifications.map {
                            NotificationEntity(
                                id = it.id,
                                title = it.title,
                                body = it.body,
                                type = it.type,
                                isRead = it.isRead,
                                createdAt = it.createdAt ?: Times.nowServerFormat(),
                            )
                        },
                    )
                }

                data.deadline?.let { cacheDeadline(it) }

                pulled = data.accounts.size
                session.setLastSyncAt(data.syncedAt ?: Times.nowServerFormat())
            }

            is ApiResult.Offline -> return@withContext SyncReport(
                pushed = pushed,
                duplicates = duplicates,
                failed = failed,
                offline = true,
                message = Localised.wrap(context).resources.getQuantityString(
                            R.plurals.msg_offline_pushed, pushed, pushed
                        ),
            )

            ApiResult.Unauthenticated -> return@withContext SyncReport(
                    unauthenticated = true,
                    message = Localised.string(context, R.string.msg_session_expired),
                )

            is ApiResult.Failure -> return@withContext SyncReport(
                pushed = pushed,
                duplicates = duplicates,
                failed = failed,
                message = result.message,
            )
        }

        // 3. Refresh today's attendance from the server's view.
        when (val result = ApiClient.call { api.attendance() }) {
            is ApiResult.Success -> {
                result.data["attendance"]?.let { dto ->
                    db.attendance().upsert(
                        AttendanceEntity(
                            date = dto.attendanceDate ?: dto.date ?: Times.today(),
                            checkInAt = dto.checkInAt,
                            checkOutAt = dto.checkOutAt,
                            workingMinutes = dto.workingMinutes,
                            visitsCount = dto.visitsCount,
                            status = dto.status ?: "present",
                            syncState = SyncState.SYNCED,
                        ),
                    )
                }
            }

            else -> Unit
        }

        // 4. Refresh today's SSS figures from the server's view.
        //
        // Only when nothing is waiting to go out. The server is not authoritative here the
        // way it is for attendance times: the phone can be holding figures a supervisor
        // typed minutes ago that have not been pushed yet, and overwriting those with an
        // older server row would silently undo their work.
        val sssDate = Times.today()
        val localSss = db.sss().find(sssDate)

        if (localSss?.syncState != SyncState.PENDING) {
            when (val result = ApiClient.call { api.sss(sssDate) }) {
                is ApiResult.Success -> {
                    // Keyed on the day that was asked for, not the day the response
                    // reports, so a surprising answer cannot write a row under a date
                    // nothing else on this screen is looking at.
                    result.data.entry?.let { entry ->
                        db.sss().upsert(
                            SssEntity(
                                date = sssDate,
                                uuid = localSss?.uuid ?: newUuid(),
                                apyCount = entry.apyCount,
                                pmjjbyCount = entry.pmjjbyCount,
                                pmsbyCount = entry.pmsbyCount,
                                pmjdyCount = entry.pmjdyCount,
                                remarks = entry.remarks,
                                syncState = SyncState.SYNCED,
                                // The server decides whether the day is closed. An Admin
                                // re-opening it is the only way the fields unlock, so this
                                // is the field the whole lock hangs on.
                                status = entry.status,
                                syncMessage = null,
                            ),
                        )
                    }

                    // The Admin's target, cached against the day it was fetched for. Saved
                    // whether or not the day has figures yet: a supervisor who has enrolled
                    // nobody so far is exactly who needs to see the number.
                    result.data.progress?.let { progress ->
                        session.saveSssTarget(
                            date = sssDate,
                            targetSet = progress.targetSet,
                            dayTarget = progress.day?.target ?: 0,
                            monthTarget = progress.monthToDate?.target ?: 0,
                            monthWorkingDays = progress.monthToDate?.workingDays ?: 0,
                        )
                    }
                }

                else -> Unit
            }
        }

        // Housekeeping: drop synced rows older than a week.
        val cutoff = System.currentTimeMillis() - 7L * 24 * 60 * 60 * 1000
        db.visits().pruneSynced(cutoff)
        db.outbox().pruneSynced(cutoff)

        val message = buildString {
            append("Synced ")
            append(pulled)
            append(" account(s)")

            if (pushed > 0 || duplicates > 0) {
                append(", sent ")
                append(pushed + duplicates)
                append(" record(s)")
            }

            if (failed > 0) {
                append(", ")
                append(failed)
                append(" failed")
            }
        }

        session.setLastSyncMessage(message)

        SyncReport(pulled = pulled, pushed = pushed, duplicates = duplicates, failed = failed, message = message)
    }

    private suspend fun resetSyncing(visitUuids: List<String>, outboxUuids: List<String>) {
        val now = System.currentTimeMillis()

        visitUuids.forEach { db.visits().markSync(it, SyncState.PENDING, null, null, now) }
        outboxUuids.forEach { db.outbox().markSync(it, SyncState.PENDING, null, now) }
    }

    private fun cacheDeadline(deadline: `in`.lrms.field.data.remote.DeadlineDto) {
        session.saveDeadline(
            deadlineAt = deadline.deadlineAt,
            serverTime = deadline.serverTime,
            secondsRemaining = deadline.secondsRemaining,
            locked = deadline.locked,
        )
    }

    /**
     * Builds the queued-visit payload the server expects, including photographs
     * as base64 so a whole visit travels as one atomic item.
     */
    private suspend fun visitPayload(visit: VisitEntity): Map<String, Any?> {
        val photos = db.photos().forVisit(visit.uuid).mapNotNull { photo ->
            val file = File(photo.filePath)

            if (!file.exists()) {
                return@mapNotNull null
            }

            mapOf(
                "data" to android.util.Base64.encodeToString(file.readBytes(), android.util.Base64.NO_WRAP),
                "photo_type" to photo.photoType,
                "caption" to photo.caption,
                "latitude" to photo.latitude,
                "longitude" to photo.longitude,
                "accuracy" to photo.accuracy,
                "address" to photo.address,
                "captured_at" to photo.capturedAt,
            )
        }

        val extras = visit.extrasJson?.let { Json.decodeMap(it) } ?: emptyMap()

        return buildMap {
            put("uuid", visit.uuid)
            put("loan_account_id", visit.accountId)
            put("visit_date", visit.visitDate)
            put("started_at", visit.startedAt)
            // Tells the server which Case Type to tick on the printed report. Was
            // never sent, so Recovery Follow-up, Pre-NPA and Post-NPA could not be
            // filed at all even though the form has boxes for them.
            put("visit_type", visit.visitType)
            put("visit_status", visit.visitStatus)
            put("recovery_possibility", visit.recoveryPossibility)
            put("remarks", visit.remarks)
            put("recommendation", visit.recommendation)
            put("form", Json.decodeMap(visit.formJson))
            put("photos", photos)
            put(
                "gps",
                mapOf(
                    "latitude" to visit.latitude,
                    "longitude" to visit.longitude,
                    "accuracy" to visit.accuracy,
                    "address" to visit.locationAddress,
                    "is_mock" to visit.isMock,
                    "captured_at" to visit.startedAt,
                ),
            )
            if (visit.submitLatitude != null && visit.submitLongitude != null) {
                put(
                    "submit_gps",
                    mapOf(
                        "latitude" to visit.submitLatitude,
                        "longitude" to visit.submitLongitude,
                        "accuracy" to visit.submitAccuracy,
                        "address" to visit.submitAddress,
                        "is_mock" to visit.submitIsMock,
                        "captured_at" to (visit.submitCapturedAt ?: visit.startedAt),
                    ),
                )
            }

            visit.borrowerSignature?.let { put("borrower_signature", it) }
            extras["promise"]?.let { put("promise", it) }
            extras["followup"]?.let { put("followup", it) }
        }
    }

    /** A cheap position ping so the monitoring screen shows a recent point. */
    suspend fun reportLocation(latitude: Double, longitude: Double, accuracy: Double?, address: String?) {
        withContext(Dispatchers.IO) {
            runCatching {
                ApiClient.call {
                    api.location(
                        mapOf(
                            "gps" to GpsPayload(
                                latitude = latitude,
                                longitude = longitude,
                                accuracy = accuracy,
                                address = address,
                                capturedAt = Times.nowServerFormat(),
                            ),
                        ),
                    )
                }
            }
        }
    }

    val store: SessionStore get() = session
}
