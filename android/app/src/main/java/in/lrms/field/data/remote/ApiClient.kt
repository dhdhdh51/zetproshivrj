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
import java.net.ConnectException
import java.net.NoRouteToHostException
import java.net.SocketTimeoutException
import java.net.URI
import java.net.UnknownHostException
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException
import javax.net.ssl.SSLHandshakeException

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

    /**
     * The message states only what failed. The sync screens compose their own
     * wording about queued work, so promising "your work is saved" here ends up
     * on the sign-in screen, where there is no work to save and it reads as
     * nonsense.
     */
    data class Offline(
        val message: String = "No connection to the server.",
        val reason: Reason = Reason.NO_NETWORK,
    ) : ApiResult<Nothing>()

    /**
     * Why a call could not reach the server.
     *
     * Every one of these arrives as an IOException, so without this the app can
     * only say "no connection" — which sends someone hunting for a signal when
     * the real problem is a certificate the phone will not trust, or a server
     * name that does not resolve. Support cannot act on "no connection".
     */
    enum class Reason { NO_NETWORK, DNS, TLS, TIMEOUT, REFUSED, OTHER }

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

    /** The full URL this build talks to, shown on the sign-in screen. */
    val baseUrl: String get() = BuildConfig.API_BASE_URL

    /**
     * Just the hostname, for error messages. Naming the host turns "no connection"
     * into something a supervisor can check, and immediately reveals an APK built
     * against the wrong server.
     */
    val host: String = try {
        URI(BuildConfig.API_BASE_URL).host ?: BuildConfig.API_BASE_URL
    } catch (e: Exception) {
        BuildConfig.API_BASE_URL
    }

    /**
     * True when this build points at a developer machine rather than a real server.
     *
     * 10.0.2.2 is how an emulator reaches the host it runs on; on a physical handset
     * nothing is there, so every call simply times out. A debug APK sitting next to
     * the release APK in the same CI run is easy to install by mistake, and without
     * this the only symptom is a timeout that looks like a bad mobile signal.
     */
    val isDeveloperEndpoint: Boolean =
        host == "10.0.2.2" || host == "127.0.0.1" || host == "localhost"

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
            // The name did not resolve: no internet at all, or DNS trouble.
            ApiResult.Offline(
                "Could not find $host. Either this phone has no internet, or it cannot look up that address.",
                ApiResult.Reason.DNS,
            )
        } catch (e: SSLHandshakeException) {
            // Ordered before SSLException and IOException, both of which it extends.
            ApiResult.Offline(
                "This phone would not accept the security certificate of $host. On Android 7 and older " +
                    "the built-in certificate list is too old for many current certificates.",
                ApiResult.Reason.TLS,
            )
        } catch (e: SSLException) {
            ApiResult.Offline(
                "The secure connection to $host failed (${e.javaClass.simpleName}).",
                ApiResult.Reason.TLS,
            )
        } catch (e: SocketTimeoutException) {
            ApiResult.Offline(
                "$host did not answer in time.",
                ApiResult.Reason.TIMEOUT,
            )
        } catch (e: ConnectException) {
            ApiResult.Offline(
                "Could not reach $host — the connection was refused.",
                ApiResult.Reason.REFUSED,
            )
        } catch (e: NoRouteToHostException) {
            ApiResult.Offline(
                "No route to $host from this network.",
                ApiResult.Reason.REFUSED,
            )
        } catch (e: IOException) {
            ApiResult.Offline(
                "Connection to $host failed (${e.javaClass.simpleName}).",
                ApiResult.Reason.OTHER,
            )
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
