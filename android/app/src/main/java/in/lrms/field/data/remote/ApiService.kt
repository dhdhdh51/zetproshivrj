package `in`.lrms.field.data.remote

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

interface ApiService {

    @GET("ping")
    suspend fun ping(): Response<Envelope<PingData>>

    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): Response<Envelope<LoginData>>

    @POST("auth/verify-otp")
    suspend fun verifyOtp(@Body body: OtpRequest): Response<Envelope<LoginData>>

    @POST("auth/logout")
    suspend fun logout(): Response<Envelope<SimpleMessageData>>

    @POST("auth/change-password")
    suspend fun changePassword(@Body body: ChangePasswordRequest): Response<Envelope<SimpleMessageData>>

    @GET("me")
    suspend fun me(): Response<Envelope<MeData>>

    @GET("sync/pull")
    suspend fun pull(@Query("since") since: String?): Response<Envelope<SyncPullData>>

    @POST("sync/push")
    suspend fun push(@Body body: SyncPushRequest): Response<Envelope<SyncPushData>>

    @POST("sync/location")
    suspend fun location(@Body body: Map<String, GpsPayload>): Response<Envelope<SimpleMessageData>>

    @GET("accounts")
    suspend fun accounts(
        @Query("search") search: String?,
        @Query("filter") filter: String?,
        @Query("page") page: Int,
    ): Response<Envelope<AccountsData>>

    @GET("visit-form")
    suspend fun visitForm(@Query("visit_type") visitType: String = "customer"): Response<Envelope<Map<String, VisitFormDto>>>

    @POST("visits")
    suspend fun startVisit(@Body body: StartVisitRequest): Response<Envelope<StartVisitData>>

    @POST("visits/{uuid}/photos")
    suspend fun uploadPhoto(
        @Path("uuid") uuid: String,
        @Body body: PhotoUploadRequest,
    ): Response<Envelope<PhotoUploadData>>

    @POST("visits/{uuid}/submit")
    suspend fun submitVisit(
        @Path("uuid") uuid: String,
        @Body body: Map<String, Any?>,
    ): Response<Envelope<SubmitVisitData>>

    @POST("recoveries")
    suspend fun recovery(@Body body: Map<String, Any?>): Response<Envelope<Map<String, Long?>>>

    @POST("promises")
    suspend fun promise(@Body body: Map<String, Any?>): Response<Envelope<Map<String, Long?>>>

    @POST("followups")
    suspend fun followup(@Body body: Map<String, Any?>): Response<Envelope<Map<String, Long?>>>

    @GET("attendance")
    suspend fun attendance(): Response<Envelope<Map<String, AttendanceDto?>>>

    /**
     * A day's SSS figures. Read only: the figures themselves go out through the outbox
     * like every other offline-capable write, so there is no POST here.
     */
    @GET("sss")
    suspend fun sss(@Query("date") date: String? = null): Response<Envelope<SssData>>

    @POST("attendance/check-in")
    suspend fun checkIn(@Body body: Map<String, Any?>): Response<Envelope<AttendanceEnvelopeData>>

    @POST("attendance/check-out")
    suspend fun checkOut(@Body body: Map<String, Any?>): Response<Envelope<AttendanceEnvelopeData>>

    @GET("deadline")
    suspend fun deadline(): Response<Envelope<DeadlineData>>

    @POST("reports/daily")
    suspend fun submitDailyReport(@Body body: Map<String, Any?>): Response<Envelope<DailyReportData>>

    @GET("notifications")
    suspend fun notifications(): Response<Envelope<NotificationsData>>

    @POST("notifications/{id}/read")
    suspend fun readNotification(@Path("id") id: Long): Response<Envelope<SimpleMessageData>>

    @POST("notifications/read-all")
    suspend fun readAllNotifications(): Response<Envelope<Map<String, Int>>>
}
