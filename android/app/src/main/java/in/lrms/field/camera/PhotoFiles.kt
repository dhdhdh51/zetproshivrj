package `in`.lrms.field.camera

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.util.Base64
import androidx.core.content.FileProvider
import androidx.exifinterface.media.ExifInterface
import java.io.ByteArrayOutputStream
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Photograph handling.
 *
 * Images are captured by the device camera app into the app's private storage,
 * then downscaled and re-encoded before they are queued. That keeps a day's worth
 * of evidence to a few megabytes on a cheap handset with little free space, and
 * makes the upload survivable on a 2G connection. The authoritative watermark is
 * burned in by the server, which also strips EXIF.
 */
object PhotoFiles {

    private const val MAX_EDGE = 1600
    private const val JPEG_QUALITY = 82

    fun directory(context: Context): File {
        val directory = File(context.filesDir, "photos")

        if (!directory.exists()) {
            directory.mkdirs()
        }

        return directory
    }

    fun newFile(context: Context, prefix: String = "visit"): File {
        val stamp = SimpleDateFormat("yyyyMMdd-HHmmss", Locale.US).format(Date())

        return File(directory(context), "$prefix-$stamp-${(1000..9999).random()}.jpg")
    }

    fun uriFor(context: Context, file: File): Uri =
        FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)

    /**
     * Downscales and rotates the captured file in place.
     *
     * @return true when the file now holds a usable image
     */
    fun compress(file: File): Boolean {
        if (!file.exists() || file.length() == 0L) {
            return false
        }

        return try {
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(file.absolutePath, bounds)

            val longestEdge = maxOf(bounds.outWidth, bounds.outHeight)

            if (longestEdge <= 0) {
                return false
            }

            var scale = 1

            while (longestEdge / scale > MAX_EDGE * 2) {
                scale *= 2
            }

            val decodeOptions = BitmapFactory.Options().apply { inSampleSize = scale }
            val decoded = BitmapFactory.decodeFile(file.absolutePath, decodeOptions) ?: return false

            val rotated = applyOrientation(file, decoded)
            val resized = fit(rotated, MAX_EDGE)

            file.outputStream().use { stream ->
                resized.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, stream)
            }

            if (resized != rotated) resized.recycle()
            if (rotated != decoded) rotated.recycle()
            decoded.recycle()

            true
        } catch (e: OutOfMemoryError) {
            // Leave the original file: the server will still accept and process it.
            true
        } catch (e: Exception) {
            file.exists() && file.length() > 0
        }
    }

    private fun applyOrientation(file: File, bitmap: Bitmap): Bitmap {
        val orientation = try {
            ExifInterface(file.absolutePath).getAttributeInt(
                ExifInterface.TAG_ORIENTATION,
                ExifInterface.ORIENTATION_NORMAL,
            )
        } catch (e: Exception) {
            ExifInterface.ORIENTATION_NORMAL
        }

        val degrees = when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }

        if (degrees == 0f) {
            return bitmap
        }

        val matrix = android.graphics.Matrix().apply { postRotate(degrees) }

        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
    }

    private fun fit(bitmap: Bitmap, maxEdge: Int): Bitmap {
        val longest = maxOf(bitmap.width, bitmap.height)

        if (longest <= maxEdge) {
            return bitmap
        }

        val ratio = maxEdge.toFloat() / longest

        return Bitmap.createScaledBitmap(
            bitmap,
            (bitmap.width * ratio).toInt().coerceAtLeast(1),
            (bitmap.height * ratio).toInt().coerceAtLeast(1),
            true,
        )
    }

    /** Used for the attendance selfie, which travels inline rather than as a file. */
    fun toBase64(file: File, maxEdge: Int = 720, quality: Int = 75): String? {
        if (!file.exists()) {
            return null
        }

        return try {
            val decoded = BitmapFactory.decodeFile(file.absolutePath) ?: return null
            val rotated = applyOrientation(file, decoded)
            val resized = fit(rotated, maxEdge)
            val stream = ByteArrayOutputStream()

            resized.compress(Bitmap.CompressFormat.JPEG, quality, stream)

            if (resized != rotated) resized.recycle()
            if (rotated != decoded) rotated.recycle()
            decoded.recycle()

            Base64.encodeToString(stream.toByteArray(), Base64.NO_WRAP)
        } catch (e: Exception) {
            null
        }
    }

    /** Clears photographs that belong to visits which have already synced. */
    fun cleanUp(context: Context, keepPaths: Set<String>) {
        directory(context).listFiles()?.forEach { file ->
            if (file.absolutePath !in keepPaths && System.currentTimeMillis() - file.lastModified() > 7L * 86_400_000) {
                file.delete()
            }
        }
    }
}
