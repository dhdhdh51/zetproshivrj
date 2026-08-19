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
    const val RECOVERY = "recovery"
    const val PROMISE = "promise"
    const val FOLLOWUP = "followup"
    const val ATTENDANCE_IN = "attendance_in"
    const val ATTENDANCE_OUT = "attendance_out"
    const val DAILY_REPORT = "daily_report"
}
