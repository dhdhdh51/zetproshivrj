package `in`.lrms.field.location

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.Looper
import androidx.core.content.ContextCompat
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume

/**
 * A single GPS fix, with everything the server needs to validate it.
 */
data class FieldLocation(
    val latitude: Double,
    val longitude: Double,
    val accuracy: Double?,
    val provider: String?,
    val isMock: Boolean,
    val capturedAtMillis: Long,
)

/**
 * GPS capture using the platform LocationManager.
 *
 * Play Services is deliberately not used: the app must work on the cheap handsets
 * BC Supervisors actually carry, including devices with no Google services, and
 * the extra dependency buys nothing for a one-shot fix.
 */
class LocationCapture(private val context: Context) {

    fun hasPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    fun isGpsEnabled(): Boolean {
        val manager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return false

        return manager.isProviderEnabled(LocationManager.GPS_PROVIDER) ||
            manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
    }

    /**
     * Waits for a fix, preferring GPS but accepting the network provider.
     *
     * @param timeoutMillis how long to wait before giving up
     * @param desiredAccuracy stop early once a fix at least this good arrives
     */
    suspend fun awaitFix(
        timeoutMillis: Long = 25_000,
        desiredAccuracy: Float = 25f,
    ): FieldLocation? {
        if (!hasPermission()) {
            return null
        }

        val manager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null

        // A very recent cached fix of good quality is worth using immediately.
        lastKnown(manager)?.let { cached ->
            if (cached.accuracy != null && cached.accuracy <= desiredAccuracy &&
                System.currentTimeMillis() - cached.capturedAtMillis < 30_000
            ) {
                return cached
            }
        }

        return withTimeoutOrNull(timeoutMillis) {
            suspendCancellableCoroutine { continuation ->
                var best: Location? = null

                val listener = object : LocationListener {
                    override fun onLocationChanged(location: Location) {
                        if (best == null || location.accuracy < (best?.accuracy ?: Float.MAX_VALUE)) {
                            best = location
                        }

                        val candidate = best ?: return

                        if (candidate.accuracy <= desiredAccuracy && continuation.isActive) {
                            runCatching { manager.removeUpdates(this) }
                            continuation.resume(candidate.toFieldLocation())
                        }
                    }

                    @Deprecated("Required by the interface on older API levels")
                    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) = Unit

                    override fun onProviderEnabled(provider: String) = Unit

                    override fun onProviderDisabled(provider: String) = Unit
                }

                try {
                    for (provider in listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)) {
                        if (manager.isProviderEnabled(provider)) {
                            manager.requestLocationUpdates(provider, 1000L, 0f, listener, Looper.getMainLooper())
                        }
                    }
                } catch (e: SecurityException) {
                    continuation.resume(null)

                    return@suspendCancellableCoroutine
                }

                continuation.invokeOnCancellation {
                    runCatching { manager.removeUpdates(listener) }

                    // Whatever we managed to collect is better than nothing.
                    best?.let { }
                }
            }
        } ?: lastKnown(context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager)
    }

    private fun lastKnown(manager: LocationManager?): FieldLocation? {
        if (manager == null || !hasPermission()) {
            return null
        }

        return try {
            listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
                .mapNotNull { provider ->
                    if (manager.isProviderEnabled(provider)) manager.getLastKnownLocation(provider) else null
                }
                .minByOrNull { it.accuracy }
                ?.toFieldLocation()
        } catch (e: SecurityException) {
            null
        }
    }
}

private fun Location.toFieldLocation(): FieldLocation = FieldLocation(
    latitude = latitude,
    longitude = longitude,
    accuracy = accuracy.toDouble(),
    provider = provider,
    isMock = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) isMock else @Suppress("DEPRECATION") isFromMockProvider,
    capturedAtMillis = if (time > 0) time else System.currentTimeMillis(),
)
