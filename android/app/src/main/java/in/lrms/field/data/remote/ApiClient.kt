package `in`.lrms.field.data.remote

import `in`.lrms.field.BuildConfig
import `in`.lrms.field.data.prefs.SessionStore
import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.util.concurrent.TimeUnit

/**
 * The outcome of an API call, so callers never have to think about HTTP codes.
 *
 * [Offline] is distinguished from [Failure] because it is the normal state in the
 * field: the app queues the work and carries on rather than showing an error.
 */
sealed class ApiResult<out T> {
    data class Success<T>(val data: T) : ApiResult<T>()

    data class Failure(
        val message: String,
        val code: String? = null,
        val status: Int = 0,
        val errors: Map<String, String> = emptyMap(),
    ) : ApiResult<Nothing>() {
        /** Retrying will not help: the server rejected the content itself. */
        val permanent: Boolean get() = status in 400..499 && status != 401 && status != 408 && status != 429
    }

    data class Offline(val message: String = "No connection. Your work is saved on this device.") : ApiResult<Nothing>()

    /** The session is gone; the UI must return to the sign-in screen. */
    data object Unauthenticated : ApiResult<Nothing>()
}

/**
 * Builds the Retrofit service.
 *
 * The base URL comes from BuildConfig (set per build type in Gradle, overridable
 * with -PlrmsApiUrl in CI), so no environment URL is ever compiled into the wrong
 * variant. Every authenticated request carries the bearer token and the bound
 * device id.
 */
object ApiClient {

    val moshi: Moshi = Moshi.Builder()
        .add(KotlinJsonAdapterFactory())
        .build()

    fun create(session: SessionStore): ApiService {
        val authInterceptor = Interceptor { chain ->
            val builder = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("X-App-Version", BuildConfig.VERSION_NAME)

            session.token()?.let { builder.header("Authorization", "Bearer $it") }
            builder.header("X-Device-Id", session.deviceUuid())

            chain.proceed(builder.build())
        }

        val logging = HttpLoggingInterceptor().apply {
            // Bodies contain customer data and photographs; only log them in debug.
            level = if (BuildConfig.DEBUG) {
                HttpLoggingInterceptor.Level.HEADERS
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
            redactHeader("Authorization")
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .addInterceptor(logging)
            // Field networks are slow; photo uploads need room to finish.
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .writeTimeout(120, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()

        return Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(client)
            .addConverterFactory(MoshiConverterFactory.create(moshi))
            .build()
            .create(ApiService::class.java)
    }

    /**
     * Unwraps the standard envelope and turns transport problems into
     * [ApiResult.Offline] rather than exceptions.
     */
    suspend fun <T : Any> call(block: suspend () -> Response<Envelope<T>>): ApiResult<T> {
        return try {
            val response = block()
            val body = response.body()

            if (response.isSuccessful && body != null && body.success && body.data != null) {
                ApiResult.Success(body.data)
            } else if (response.code() == 401) {
                ApiResult.Unauthenticated
            } else {
                val errorBody = body ?: parseError(response)

                ApiResult.Failure(
                    message = errorBody?.message ?: "The server rejected the request (HTTP ${response.code()}).",
                    code = errorBody?.code,
                    status = response.code(),
                    errors = errorBody?.errors ?: emptyMap(),
                )
            }
        } catch (e: UnknownHostException) {
            ApiResult.Offline()
        } catch (e: SocketTimeoutException) {
            ApiResult.Offline("The server did not respond. Your work is saved on this device.")
        } catch (e: IOException) {
            ApiResult.Offline()
        } catch (e: Exception) {
            ApiResult.Failure(e.message ?: "Something went wrong.", status = 0)
        }
    }

    private fun <T> parseError(response: Response<Envelope<T>>): Envelope<*>? {
        val raw = response.errorBody()?.string() ?: return null

        return try {
            val adapter = moshi.adapter(Envelope::class.java)
            adapter.fromJson(raw)
        } catch (e: Exception) {
            null
        }
    }
}
