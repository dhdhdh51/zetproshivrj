package `in`.lrms.field.data.local

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * The on-device store.
 *
 * The app is offline-first: everything a supervisor needs for the day is cached
 * here, and everything they record is written here first and only then pushed to
 * the server. Each outgoing record carries the client-generated uuid the server
 * uses for idempotency, so a retry can never create a duplicate.
 */

@Entity(
    tableName = "accounts",
    indices = [Index("borrowerName"), Index("village"), Index("overdue")],
)
data class AccountEntity(
    @PrimaryKey val id: Long,
    val accountNumber: String,
    val cif: String?,
    val borrowerName: String,
    val fatherName: String?,
    val mobile: String?,
    val village: String?,
    val address: String?,
    val loanType: String?,
    val sanctionDate: String?,
    val npaDate: String?,
    val limitAmount: Double,
    val outstanding: Double,
    val overdue: Double,
    val totalRecovered: Double,
    val loanCategory: String,
    val recoveryStatus: String,
    val visitCount: Int,
    val lastVisitAt: String?,
    val branchName: String?,
    val branchCode: String?,
    val updatedAt: String?,
)

/**
 * A visit being worked on or waiting to sync.
 *
 * `syncState` drives the outbox: pending → syncing → synced, or failed with a
 * message the supervisor can act on.
 */
@Entity(tableName = "visits", indices = [Index(value = ["uuid"], unique = true), Index("syncState")])
data class VisitEntity(
    @PrimaryKey val uuid: String,
    val accountId: Long,
    val accountNumber: String,
    val borrowerName: String,
    val visitDate: String,
    val startedAt: String,
    /**
     * The case type on the printed form: krm_ots, ckcc_od2, recovery_followup,
     * pre_npa, post_npa, other, or customer.
     *
     * Chosen on the visit screen rather than inferred, because only the person
     * standing there knows whether this doorstep is a renewal call or a pre-NPA
     * check. It decides which form is asked and which Case Type box is ticked on
     * the report.
     */
    val visitType: String = "customer",
    val visitStatus: String?,
    val recoveryPossibility: String?,
    val remarks: String?,
    val recommendation: String?,
    /** JSON object of the dynamic form answers, keyed by field key. */
    val formJson: String,
    val latitude: Double?,
    val longitude: Double?,
    val accuracy: Double?,
    val locationAddress: String?,
    val isMock: Boolean,
    /**
     * Where the phone was when the report was filed, which is not always where it
     * was when the visit was started. A form left open on the doorstep, or finished
     * at the next stop, used to be filed with the opening coordinates and nothing
     * recorded the difference. Null when no fix was available at that moment.
     */
    val submitLatitude: Double? = null,
    val submitLongitude: Double? = null,
    val submitAccuracy: Double? = null,
    val submitAddress: String? = null,
    val submitIsMock: Boolean = false,
    val submitCapturedAt: String? = null,
    val borrowerSignature: String?,
    val supervisorSignature: String?,
    /** JSON array of recovery / promise / followup payloads captured with the visit. */
    val extrasJson: String?,
    val syncState: String,
    val syncMessage: String?,
    val attempts: Int,
    val serverVisitId: Long?,
    val submittedLocally: Boolean,
    val createdAt: Long,
    val updatedAt: Long,
)

@Entity(tableName = "visit_photos", indices = [Index("visitUuid"), Index("syncState")])
data class PhotoEntity(
    @PrimaryKey(autoGenerate = true) val localId: Long = 0,
    val visitUuid: String,
    val photoType: String,
    /** Absolute path in the app's private storage. */
    val filePath: String,
    val caption: String?,
    val latitude: Double?,
    val longitude: Double?,
    val accuracy: Double?,
    val address: String?,
    val capturedAt: String,
    val syncState: String,
    val serverPhotoId: Long?,
)

/**
 * Anything else queued for the server: recoveries, promises, follow-ups,
 * attendance and the day-end report. Keeping them in one outbox means the sync
 * worker has a single ordered queue to drain.
 */
@Entity(tableName = "outbox", indices = [Index(value = ["uuid"], unique = true), Index("syncState")])
data class OutboxEntity(
    @PrimaryKey val uuid: String,
    /** recovery | promise | followup | attendance_in | attendance_out | daily_report */
    val type: String,
    val accountId: Long?,
    val label: String,
    val payloadJson: String,
    val syncState: String,
    val syncMessage: String?,
    val attempts: Int,
    val createdAt: Long,
    val updatedAt: Long,
)

@Entity(tableName = "notifications")
data class NotificationEntity(
    @PrimaryKey val id: Long,
    val title: String,
    val body: String?,
    val type: String,
    val isRead: Boolean,
    val createdAt: String,
)

/** The visit form definitions, cached so the forms work with no connection. */
@Entity(
    tableName = "form_fields",
    primaryKeys = ["visitType", "fieldKey"],
    indices = [Index("visitType", "sortOrder")],
)
data class FormFieldEntity(
    /**
     * Which form this field belongs to: customer, krm_ots or ckcc_od2.
     *
     * Part of the key because the three forms share field keys — a KRM OTS visit
     * and a CKCC renewal both ask whether the borrower was met — and with the key
     * on fieldKey alone one form silently overwrote the other's question.
     */
    val visitType: String,
    val fieldKey: String,
    val label: String,
    val type: String,
    val required: Boolean,
    /** Newline separated choices. */
    val options: String?,
    val placeholder: String?,
    val help: String?,
    val sortOrder: Int,
    val conditionField: String?,
    val conditionOperator: String?,
    val conditionValue: String?,
)

/** Today's attendance, mirrored locally so the UI works offline. */
@Entity(tableName = "attendance")
data class AttendanceEntity(
    @PrimaryKey val date: String,
    val checkInAt: String?,
    val checkOutAt: String?,
    val workingMinutes: Int,
    val visitsCount: Int,
    val status: String,
    val syncState: String,
)

/**
 * A day's Social Security Scheme enrolments, mirrored locally so the screen works
 * offline and reopens on whatever was last typed.
 *
 * Keyed by date, and the uuid is kept with it. A supervisor who types four and then
 * corrects it to five is describing one day, not two: reusing the uuid means the
 * correction overwrites the still-pending outbox row instead of queueing a second one,
 * and the server treats the day as the natural key for the same reason.
 */
@Entity(tableName = "sss_enrolments")
data class SssEntity(
    @PrimaryKey val date: String,
    val uuid: String,
    val apyCount: Int,
    val pmjjbyCount: Int,
    val pmsbyCount: Int,
    val pmjdyCount: Int,
    val remarks: String?,
    val syncState: String,
    /**
     * What the server says about the day: submitted, or handed back by a BC Supervisor.
     *
     * Defaults to submitted because that is what a day this device has just typed will
     * become the moment it arrives — and because a row written by an older build was
     * submitted too.
     */
    val status: String = SssStatus.SUBMITTED,
    /** Why the server refused the last attempt, so the screen can say so. */
    val syncMessage: String? = null,
) {
    val total: Int get() = apyCount + pmjjbyCount + pmsbyCount + pmjdyCount

    /**
     * Can the supervisor still change this day?
     *
     * Not while the server holds it as submitted. A day still waiting in the outbox is
     * fair game — nobody has accepted it yet, so correcting it before it goes is the same
     * as typing it slower.
     */
    val locked: Boolean
        get() = status == SssStatus.SUBMITTED &&
            syncState != SyncState.PENDING &&
            syncState != SyncState.DRAFT

    /** The Admin handed this day back; one more submission is allowed. */
    val reopened: Boolean get() = status == SssStatus.REOPENED
}

/** Mirrors the server's `sss_enrolments.status` enum. */
object SssStatus {
    const val SUBMITTED = "submitted"
    const val REOPENED = "reopened"
}

object SyncState {
    const val PENDING = "pending"
    const val SYNCING = "syncing"
    const val SYNCED = "synced"
    const val FAILED = "failed"
    /** Rejected by the server for a reason retrying cannot fix. */
    const val REJECTED = "rejected"
    /** Being filled in; not yet queued. */
    const val DRAFT = "draft"
}

object OutboxType {
    /**
     * Legacy. Nothing in this app queues a payment any more — the work is the visit,
     * and money never passes through a field agent.
     *
     * The type string stays because an outbox is not a cache: a phone updating from an
     * earlier build can be holding an unsynced payment entry, and outbox rows are
     * pushed by the type they were written with. Removing the constant would not
     * remove those rows, it would only remove the name of what they are.
     */
    const val RECOVERY = "recovery"
    const val PROMISE = "promise"
    const val FOLLOWUP = "followup"
    const val ATTENDANCE_IN = "attendance_in"
    const val ATTENDANCE_OUT = "attendance_out"
    const val DAILY_REPORT = "daily_report"
    const val SSS = "sss"
}
