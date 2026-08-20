package `in`.lrms.field.util

import android.content.Context
import android.content.res.Configuration
import androidx.annotation.StringRes
import androidx.appcompat.app.AppCompatDelegate
import java.util.Locale

/**
 * Strings in the language the supervisor picked, for code with no composable around it.
 *
 * A composable calls stringResource and gets it right for free, because it resolves
 * against the activity's context. A view model or a repository has neither, and the
 * messages they produce — a sign-in error, a GPS failure, "saved and queued" — are
 * read as often as anything on a screen.
 *
 * AppCompatDelegate.setApplicationLocales is what switches the app's language (see
 * [AppLanguage] for why that mechanism and not a configuration override). On API 33+
 * the platform applies it everywhere, including here. Below that it is a backport, and
 * the application context can keep serving the configuration it was built with at
 * startup — which would leave these messages in English on exactly the cheap handsets
 * this app is for.
 *
 * Rather than depend on which of those is happening, the configuration is built from
 * the selected locale explicitly. createConfigurationContext has existed since API 17,
 * so this behaves the same on every device the app supports.
 */
object Localised {

    /** A context whose resources are in the app's chosen language. */
    fun wrap(context: Context): Context {
        val locales = AppCompatDelegate.getApplicationLocales()

        // Empty means "follow the phone", and the context already does.
        if (locales.isEmpty) {
            return context
        }

        val tag = locales.toLanguageTags().substringBefore(',')

        if (tag.isBlank()) {
            return context
        }

        val configuration = Configuration(context.resources.configuration)
        configuration.setLocale(Locale.forLanguageTag(tag))

        return context.createConfigurationContext(configuration)
    }

    fun string(context: Context, @StringRes id: Int, vararg args: Any): String =
        wrap(context).getString(id, *args)
}
