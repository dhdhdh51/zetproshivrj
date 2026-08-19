package `in`.lrms.field.data.remote

import com.squareup.moshi.Json

/**
 * Wire models for the LRMS API.
 *
 * Every response uses the same envelope, so failures can be handled in one place
 * (see [ApiClient.call]).
 */
data class Envelope<T>(
    val success: Boolean,
    val data: T? = null,
    val message: String? = null,
    val code: String? = null,
    val errors: Map<String, String>? = null,
    @Json(name = "server_time") val serverTime: String? = null,
)

data class PingData(
    val app: String?,
    @Json(name = "api_version") val apiVersion: String?,
    @Json(name = "server_time") val serverTime: String?,
    val maintenance: Boolean = false,
    @Json(name = "otp_required_for_login") val otpRequiredForLogin: Boolean = false,
    @Json(name = "device_binding") val deviceBinding: Boolean = true,
)

data class DeviceInfo(
    val uuid: String,
    val model: String?,
    val manufacturer: String?,
    @Json(name = "os_version") val osVersion: String?,
    @Json(name = "app_version") val appVersion: String?,
)

data class LoginRequest(
    val username: String,
    val password: String,
    val device: DeviceInfo,
)

data class OtpRequest(
    @Json(name = "user_id") val userId: Long,
    val code: String,
    val device: DeviceInfo? = null,
)

data class LoginData(
    @Json(name = "otp_required") val otpRequired: Boolean = false,
    @Json(name = "user_id") val userId: Long? = null,
    val message: String? = null,
    val destination: String? = null,
    val token: String? = null,
    @Json(name = "expires_at") val expiresAt: String? = null,
    val user: UserDto? = null,
    val supervisor: SupervisorDto? = null,
)

data class UserDto(
    val id: Long,
    val name: String,
    val username: String?,
    val mobile: String?,
    val role: String?,
    @Json(name = "must_change_password") val mustChangePassword: Boolean = false,
)

data class SupervisorDto(
    val id: Long,
    @Json(name = "bc_code") val bcCode: String,
    @Json(name = "branch_id") val branchId: Long,
    @Json(name = "branch_name") val branchName: String?,
    @Json(name = "branch_code") val branchCode: String?,
    val village: String? = null,
)

data class MeData(
    val user: UserDto?,
    val supervisor: SupervisorDto?,
    val today: TodayDto?,
    val attendance: AttendanceDto?,
    val targets: List<TargetDto>?,
    val deadline: DeadlineDto?,
    @Json(name = "unread_notifications") val unreadNotifications: Int = 0,
)

data class TodayDto(
    val date: String?,
    val visits: Int = 0,
    val recovery: Double = 0.0,
    val promises: Int = 0,
    @Json(name = "allocated_accounts") val allocatedAccounts: Int = 0,
    @Json(name = "pending_accounts") val pendingAccounts: Int = 0,
)

data class TargetDto(
    val period: String,
    @Json(name = "visit_target") val visitTarget: Int = 0,
    @Json(name = "recovery_target") val recoveryTarget: Double = 0.0,
    @Json(name = "period_start") val periodStart: String?,
    @Json(name = "period_end") val periodEnd: String?,
)

data class DeadlineDto(
    @Json(name = "report_date") val reportDate: String?,
    @Json(name = "is_working_day") val isWorkingDay: Boolean = true,
    @Json(name = "deadline_time") val deadlineTime: String?,
    @Json(name = "deadline_at") val deadlineAt: String?,
    @Json(name = "server_time") val serverTime: String?,
    @Json(name = "seconds_remaining") val secondsRemaining: Long = 0,
    @Json(name = "has_passed") val hasPassed: Boolean = false,
    val locked: Boolean = false,
    @Json(name = "late_requests_allowed") val lateRequestsAllowed: Boolean = true,
)

data class AttendanceDto(
    val date: String? = null,
    @Json(name = "attendance_date") val attendanceDate: String? = null,
    @Json(name = "check_in_at") val checkInAt: String? = null,
    @Json(name = "check_out_at") val checkOutAt: String? = null,
    @Json(name = "working_minutes") val workingMinutes: Int = 0,
    @Json(name = "visits_count") val visitsCount: Int = 0,
    val status: String? = null,
)

data class AttendanceEnvelopeData(val attendance: AttendanceDto?)

data class SyncPullData(
    @Json(name = "synced_at") val syncedAt: String?,
    val accounts: List<AccountDto> = emptyList(),
    @Json(name = "removed_account_ids") val removedAccountIds: List<Long> = emptyList(),
    /** Every visit form: customer, KRM OTS and CKCC OD-2. */
    @Json(name = "visit_forms") val visitForms: List<VisitFormDto> = emptyList(),
    /** The customer form alone, from a server older than visit_forms. */
    @Json(name = "visit_form") val visitForm: VisitFormDto?,
    val rules: RulesDto?,
    val deadline: DeadlineDto?,
    val notifications: List<NotificationDto> = emptyList(),
)

data class AccountDto(
    val id: Long,
    @Json(name = "account_number") val accountNumber: String,
    val cif: String?,
    @Json(name = "borrower_name") val borrowerName: String,
    @Json(name = "father_name") val fatherName: String?,
    val mobile: String?,
    val village: String?,
    val address: String?,
    @Json(name = "loan_type") val loanType: String?,
    @Json(name = "sanction_date") val sanctionDate: String?,
    @Json(name = "npa_date") val npaDate: String?,
    @Json(name = "limit_amount") val limitAmount: Double = 0.0,
    val outstanding: Double = 0.0,
    val overdue: Double = 0.0,
    @Json(name = "total_recovered") val totalRecovered: Double = 0.0,
    @Json(name = "loan_category") val loanCategory: String = "general",
    @Json(name = "recovery_status") val recoveryStatus: String = "pending",
    @Json(name = "visit_count") val visitCount: Int = 0,
    @Json(name = "last_visit_at") val lastVisitAt: String?,
    @Json(name = "branch_name") val branchName: String?,
    @Json(name = "branch_code") val branchCode: String?,
    @Json(name = "updated_at") val updatedAt: String?,
)

data class VisitFormDto(
    val id: Long,
    val name: String,
    val version: Int = 1,
    @Json(name = "visit_type") val visitType: String = "customer",
    val fields: List<FormFieldDto> = emptyList(),
)

data class FormFieldDto(
    val key: String,
    val label: String,
    val type: String,
    val required: Boolean = false,
    val options: List<String> = emptyList(),
    val placeholder: String? = null,
    val help: String? = null,
    val order: Int = 0,
    val condition: ConditionDto? = null,
)

data class ConditionDto(
    val field: String?,
    val operator: String?,
    val value: String?,
)

data class RulesDto(
    @Json(name = "min_visit_photos") val minVisitPhotos: Int = 1,
    @Json(name = "require_borrower_signature") val requireBorrowerSignature: Boolean = false,
    @Json(name = "gps_max_accuracy_metres") val gpsMaxAccuracyMetres: Double = 200.0,
    @Json(name = "gps_mock_location_allowed") val gpsMockLocationAllowed: Boolean = false,
    @Json(name = "visit_statuses") val visitStatuses: Map<String, String> = emptyMap(),
    @Json(name = "photo_types") val photoTypes: Map<String, String> = emptyMap(),
    @Json(name = "recovery_possibility") val recoveryPossibility: Map<String, String> = emptyMap(),
    @Json(name = "max_backdate_days") val maxBackdateDays: Int = 7,
)

data class NotificationDto(
    val id: Long,
    val title: String,
    val body: String?,
    val type: String = "info",
    val link: String? = null,
    @Json(name = "is_read") val isRead: Boolean = false,
    @Json(name = "created_at") val createdAt: String?,
)

data class NotificationsData(
    val unread: Int = 0,
    val notifications: List<NotificationDto> = emptyList(),
)

data class GpsPayload(
    val latitude: Double,
    val longitude: Double,
    val accuracy: Double? = null,
    val provider: String? = null,
    val address: String? = null,
    @Json(name = "is_mock") val isMock: Boolean = false,
    @Json(name = "captured_at") val capturedAt: String? = null,
)

data class StartVisitRequest(
    val uuid: String,
    @Json(name = "loan_account_id") val loanAccountId: Long,
    @Json(name = "visit_date") val visitDate: String,
    val gps: GpsPayload,
    @Json(name = "started_at") val startedAt: String? = null,
)

data class StartVisitData(
    val visit: VisitSummaryDto?,
    val created: Boolean = false,
    @Json(name = "min_photos") val minPhotos: Int = 1,
)

data class VisitSummaryDto(
    val id: Long,
    val uuid: String,
    val status: String?,
    @Json(name = "visit_date") val visitDate: String? = null,
    @Json(name = "form_id") val formId: Long? = null,
    @Json(name = "is_late") val isLate: Boolean = false,
    @Json(name = "submitted_at") val submittedAt: String? = null,
)

data class PhotoUploadRequest(
    val data: String,
    @Json(name = "photo_type") val photoType: String,
    val caption: String? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    val accuracy: Double? = null,
    val address: String? = null,
    @Json(name = "captured_at") val capturedAt: String? = null,
)

data class PhotoUploadData(
    @Json(name = "photo_id") val photoId: Long?,
    val duplicate: Boolean = false,
    @Json(name = "photo_count") val photoCount: Int = 0,
)

data class SubmitVisitData(
    val visit: VisitSummaryDto?,
    @Json(name = "already_submitted") val alreadySubmitted: Boolean = false,
    @Json(name = "recovery_id") val recoveryId: Long? = null,
    @Json(name = "promise_id") val promiseId: Long? = null,
    @Json(name = "followup_id") val followupId: Long? = null,
    val deadline: DeadlineDto? = null,
)

data class SyncPushRequest(
    @Json(name = "batch_uuid") val batchUuid: String,
    @Json(name = "app_version") val appVersion: String?,
    @Json(name = "network_type") val networkType: String?,
    val items: List<SyncItem>,
)

data class SyncItem(
    val type: String,
    val uuid: String,
    val payload: Map<String, Any?>,
)

data class SyncPushData(
    @Json(name = "batch_uuid") val batchUuid: String?,
    val received: Int = 0,
    val accepted: Int = 0,
    val duplicates: Int = 0,
    val failed: Int = 0,
    val results: List<SyncResultDto> = emptyList(),
    val deadline: DeadlineDto? = null,
)

data class SyncResultDto(
    val index: Int = 0,
    val type: String?,
    val uuid: String?,
    val status: String?,
    val id: Long? = null,
    val message: String? = null,
    val retryable: Boolean = true,
    @Json(name = "is_late") val isLate: Boolean = false,
)

data class DeadlineData(
    val deadline: DeadlineDto?,
    val counts: CountsDto?,
    val submission: SubmissionDto?,
)

data class CountsDto(
    val visits: Int = 0,
    val recovery: Double = 0.0,
    val promises: Int = 0,
)

data class SubmissionDto(
    val id: Long? = null,
    val status: String? = null,
    @Json(name = "submitted_at") val submittedAt: String? = null,
    @Json(name = "is_late") val isLate: Boolean = false,
    @Json(name = "deadline_at") val deadlineAt: String? = null,
    @Json(name = "late_reason") val lateReason: String? = null,
    @Json(name = "approval_remarks") val approvalRemarks: String? = null,
)

data class DailyReportData(
    @Json(name = "submission_id") val submissionId: Long?,
    val status: String?,
    @Json(name = "is_late") val isLate: Boolean = false,
    val message: String?,
    val deadline: DeadlineDto? = null,
)

data class SimpleMessageData(val message: String? = null)

data class AccountsData(
    val accounts: List<AccountDto> = emptyList(),
    val pagination: PaginationDto? = null,
)

data class PaginationDto(
    val page: Int = 1,
    @Json(name = "per_page") val perPage: Int = 50,
    val total: Int = 0,
    @Json(name = "last_page") val lastPage: Int = 1,
)

data class ChangePasswordRequest(
    @Json(name = "current_password") val currentPassword: String,
    val password: String,
    @Json(name = "password_confirmation") val passwordConfirmation: String,
)
