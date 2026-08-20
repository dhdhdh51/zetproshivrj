package `in`.lrms.field

import java.io.File
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Two things the language switch depends on that nothing else would catch.
 *
 * The app was shipped fully translated and came out entirely in English. Every string
 * was in place, `values-hi/` was complete, and the switcher wrote the right tag — but
 * AppCompatDelegate.setApplicationLocales only reaches an activity below API 33 by
 * wrapping its base context through AppCompat's delegate, and MainActivity was a plain
 * ComponentActivity. On Android 13 and newer the platform applies the locale itself, so
 * the fault was invisible on a modern test device and total on the cheap handsets this
 * app is actually for.
 *
 * Nothing about that is visible at the call site: the switcher looks correct, the strings
 * look correct, and a compiler has no opinion. Hence these, which read the source rather
 * than run it — an instrumented test would need a device, and the invariant is really
 * about how the app is declared.
 *
 * The second test exists because the first cannot be satisfied alone: an AppCompatActivity
 * throws at startup unless the window theme descends from Theme.AppCompat, so anyone
 * reverting the theme would break the app rather than merely break the translation.
 */
class LocaleSwitchingTest {

    /**
     * Unit tests run with the module directory as the working directory, but that is a
     * convention rather than a guarantee. Missing files fail loudly instead of letting
     * the assertions pass over nothing.
     */
    private fun source(path: String): String {
        val candidates = listOf(File(path), File("app/$path"), File("../app/$path"))

        val file = candidates.firstOrNull { it.isFile }
            ?: throw AssertionError(
                "Cannot find $path from ${File(".").absolutePath}. " +
                    "This test must never pass without reading the file it is about."
            )

        return file.readText()
    }

    @Test
    fun `the activity goes through AppCompat, so the language switch reaches it`() {
        val activity = source("src/main/java/in/lrms/field/ui/MainActivity.kt")

        assertTrue(
            "MainActivity must extend AppCompatActivity. Below API 33 the per-app locale " +
                "is applied through AppCompat's delegate; a plain ComponentActivity never " +
                "goes through it and every screen stays in the phone's language.",
            activity.contains("class MainActivity : AppCompatActivity()"),
        )
    }

    @Test
    fun `the window theme descends from an AppCompat theme`() {
        val themes = source("src/main/res/values/themes.xml")

        assertTrue(
            "Theme.LRMS must have a Theme.AppCompat parent, or the AppCompatActivity the " +
                "language switch depends on throws at startup.",
            themes.contains("parent=\"Theme.AppCompat"),
        )
    }

    @Test
    fun `hindi resources are actually present`() {
        val hindi = source("src/main/res/values-hi/strings.xml")

        assertTrue("values-hi/strings.xml must hold translations", hindi.contains("<string"))
        assertTrue(
            "The Hindi resources must be Devanagari, not English copied across",
            hindi.contains("\u0939\u093f") || hindi.contains("\u0917\u094d\u0930"),
        )
    }

    /**
     * resourceConfigurations once listed only "en", which stripped every Hindi resource
     * out of the built APK while leaving the source files looking complete.
     */
    @Test
    fun `the build keeps both languages in the APK`() {
        val gradle = source("build.gradle.kts")

        assertTrue(
            "resourceConfigurations must keep hi as well as en, or Hindi is stripped from " +
                "the APK at build time",
            gradle.contains("\"en\"") && gradle.contains("\"hi\""),
        )
    }
}
