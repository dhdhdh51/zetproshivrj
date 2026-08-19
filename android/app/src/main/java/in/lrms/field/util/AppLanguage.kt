package `in`.lrms.field.util

import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat

/**
 * The language the app is displayed in, as the supervisor chooses it.
 *
 * Approach taken from the Bankmitra2 app in this organisation, which already
 * solved this for the same field staff — worth following rather than inventing a
 * second mechanism they would have to learn.
 *
 * WHY A PER-APP LOCALE AND NOT A CONFIGURATION OVERRIDE
 *
 * The obvious implementation wraps every base context, swaps the Locale in a
 * Configuration and recreates the activity. It is also the one that breaks: the
 * wrapped context and the one Android hands a dialog or a notification builder
 * disagree, the resource cache still holds strings from the previous locale, and
 * anything outliving the activity — a foreground service, a WorkManager job, a
 * notification channel name — reads whichever locale it was created under. This
 * app has all three.
 *
 * AppCompatDelegate.setApplicationLocales() is the platform's own answer:
 * framework on API 33+, backported below it. Android performs the switch and
 * everything asking for a string afterwards gets the chosen language, including
 * code with no Activity anywhere near it — which is where our sync messages and
 * notification text come from.
 *
 * WHY THE CHOICE IS STORED HERE TOO
 *
 * appcompat can persist it, but only with autoStoreLocales metadata and a
 * synchronous preferences read on the main thread at startup. There is already a
 * preferences file, so the tag goes there and is reapplied in LrmsApp.onCreate().
 *
 * [fromTag], [tagFor] and [nativeName] are deliberately free of Android context,
 * so the mapping between what is stored, what is applied and what the supervisor
 * sees is covered by ordinary JVM tests instead of being hoped about.
 */
enum class AppLanguage(
    /** What goes into preferences and a BCP-47 locale list. Empty = follow the phone. */
    val tag: String,
    /**
     * The language's own name.
     *
     * Always written in its own script: a supervisor who has switched into a
     * language they cannot read must still be able to find their way back, and
     * "Hindi" spelled in English is no help to someone looking for "हिन्दी".
     */
    val nativeName: String,
) {
    /** Whatever the phone is set to. */
    SYSTEM("", "Phone language"),
    ENGLISH("en", "English"),
    HINDI("hi", "हिन्दी"),
    ;

    companion object {

        /** Offered in this order; English first because the source is written in it. */
        val CHOICES: List<AppLanguage> = listOf(ENGLISH, HINDI, SYSTEM)

        /**
         * A stored tag read back, tolerantly.
         *
         * Anything unrecognised becomes [SYSTEM] rather than throwing. A
         * preferences file can carry a value written by an older or newer build,
         * and a language setting is not worth crashing an app over — falling back
         * to the phone's language is always a defensible answer.
         */
        fun fromTag(tag: String?): AppLanguage {
            if (tag.isNullOrBlank()) {
                return SYSTEM
            }

            // Compare on the language subtag so "hi-IN" resolves to Hindi.
            val language = tag.substringBefore('-').lowercase()

            return entries.firstOrNull { it.tag.isNotEmpty() && it.tag == language } ?: SYSTEM
        }

        fun tagFor(language: AppLanguage): String = language.tag

        /** Applies a language now, and to everything the process does afterwards. */
        fun apply(language: AppLanguage) {
            AppCompatDelegate.setApplicationLocales(
                if (language.tag.isEmpty()) {
                    LocaleListCompat.getEmptyLocaleList()
                } else {
                    LocaleListCompat.forLanguageTags(language.tag)
                },
            )
        }

        /**
         * What is on screen right now.
         *
         * Reads what appcompat has applied first, because on Android 13+ the
         * supervisor can change this from the system settings without going
         * through the app, and the app's own switcher must not contradict it.
         */
        fun current(storedTag: String?): AppLanguage {
            val applied = AppCompatDelegate.getApplicationLocales()

            return if (applied.isEmpty) {
                fromTag(storedTag)
            } else {
                fromTag(applied[0]?.language)
            }
        }
    }
}
