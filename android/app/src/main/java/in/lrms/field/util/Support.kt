package `in`.lrms.field.util

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import com.squareup.moshi.Moshi
import com.squareup.moshi.Types
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

fun newUuid(): String = UUID.randomUUID().toString()

/**
 * JSON helpers for the small amount of dynamic data the app stores as text: the
 * visit form answers and the queued payloads.
 */
object Json {
    private val moshi: Moshi = Moshi.Builder().build()

    private val mapType = Types.newParameterizedType(Map::class.java, String::class.java, Any::class.java)
    private val stringMapType = Types.newParameterizedType(Map::class.java, String::class.java, String::class.java)

    fun encodeAny(value: Map<String, Any?>): String =
        moshi.adapter<Map<String, Any?>>(mapType).toJson(value)

    fun encodeStringMap(value: Map<String, String>): String =
        moshi.adapter<Map<String, String>>(stringMapType).toJson(value)

    fun decodeMap(json: String?): Map<String, Any?> {
        if (json.isNullOrBlank()) {
            return emptyMap()
        }

        return runCatching { moshi.adapter<Map<String, Any?>>(mapType).fromJson(json) }
            .getOrNull()
            ?: emptyMap()
    }

    fun decodeStringMap(json: String?): Map<String, String> {
        if (json.isNullOrBlank()) {
            return emptyMap()
        }

        return runCatching { moshi.adapter<Map<String, String>>(stringMapType).fromJson(json) }
            .getOrNull()
            ?: emptyMap()
    }
}

object Network {
    fun isOnline(context: Context): Boolean {
        val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return false
        val network = manager.activeNetwork ?: return false
        val capabilities = manager.getNetworkCapabilities(network) ?: return false

        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    fun type(context: Context): String {
        val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return "unknown"
        val network = manager.activeNetwork ?: return "offline"
        val capabilities = manager.getNetworkCapabilities(network) ?: return "offline"

        return when {
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ethernet"
            else -> "other"
        }
    }
}

/**
 * Date, time and money formatting.
 *
 * The server expects `Y-m-d H:i:s` in the organisation's timezone, and the UI
 * shows Indian-grouped rupee amounts, which is what branch staff read.
 */
object Times {
    private const val SERVER_DATE_TIME = "yyyy-MM-dd HH:mm:ss"
    private const val SERVER_DATE = "yyyy-MM-dd"

    fun today(): String = SimpleDateFormat(SERVER_DATE, Locale.US).format(Date())

    fun nowServerFormat(): String = SimpleDateFormat(SERVER_DATE_TIME, Locale.US).format(Date())

    fun date(offsetDays: Int): String {
        val calendar = java.util.Calendar.getInstance()
        calendar.add(java.util.Calendar.DAY_OF_YEAR, offsetDays)

        return SimpleDateFormat(SERVER_DATE, Locale.US).format(calendar.time)
    }

    fun humanDate(value: String?): String {
        if (value.isNullOrBlank()) {
            return "—"
        }

        val parsed = parse(value) ?: return value

        return SimpleDateFormat("dd MMM yyyy", Locale.US).format(parsed)
    }

    fun humanDateTime(value: String?): String {
        if (value.isNullOrBlank()) {
            return "—"
        }

        val parsed = parse(value) ?: return value

        return SimpleDateFormat("dd MMM yyyy, hh:mm a", Locale.US).format(parsed)
    }

    fun timeOnly(value: String?): String {
        if (value.isNullOrBlank()) {
            return "—"
        }

        val parsed = parse(value) ?: return value

        return SimpleDateFormat("hh:mm a", Locale.US).format(parsed)
    }

    private fun parse(value: String): Date? {
        for (pattern in listOf(SERVER_DATE_TIME, SERVER_DATE)) {
            runCatching { return SimpleDateFormat(pattern, Locale.US).parse(value) }
        }

        return null
    }

    /** Indian digit grouping: 1,23,456.78 */
    fun money(amount: Double): String {
        val negative = amount < 0

        // Work in paise and round, rather than truncating the fraction: binary
        // doubles make 123456.78 slightly less than it looks, which would print
        // ".77" and understate every amount by a paisa.
        val totalPaise = Math.round(kotlin.math.abs(amount) * 100)
        val whole = totalPaise / 100
        val paise = totalPaise % 100

        val digits = whole.toString()
        val grouped = if (digits.length <= 3) {
            digits
        } else {
            val last3 = digits.substring(digits.length - 3)
            var rest = digits.substring(0, digits.length - 3)
            val chunks = mutableListOf<String>()

            while (rest.length > 2) {
                chunks.add(0, rest.substring(rest.length - 2))
                rest = rest.substring(0, rest.length - 2)
            }

            if (rest.isNotEmpty()) {
                chunks.add(0, rest)
            }

            chunks.joinToString(",") + "," + last3
        }

        return buildString {
            if (negative) append("-")
            append("₹")
            append(grouped)
            append(".")
            append(paise.toString().padStart(2, '0'))
        }
    }

    fun compactMoney(amount: Double): String = when {
        kotlin.math.abs(amount) >= 10_000_000 -> "₹%.2f Cr".format(amount / 10_000_000)
        kotlin.math.abs(amount) >= 100_000 -> "₹%.2f L".format(amount / 100_000)
        else -> money(amount)
    }

    /** "2h 05m left" for the deadline countdown. */
    fun countdown(seconds: Long): String {
        if (seconds <= 0) {
            return "Deadline passed"
        }

        val hours = seconds / 3600
        val minutes = (seconds % 3600) / 60
        val remainder = seconds % 60

        return when {
            hours > 0 -> "%dh %02dm left".format(hours, minutes)
            minutes > 0 -> "%dm %02ds left".format(minutes, remainder)
            else -> "%ds left".format(remainder)
        }
    }
}
