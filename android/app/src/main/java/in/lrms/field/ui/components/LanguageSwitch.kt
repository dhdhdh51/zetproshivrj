package `in`.lrms.field.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import `in`.lrms.field.R
import `in`.lrms.field.ServiceLocator
import `in`.lrms.field.util.AppLanguage

/**
 * English / हिन्दी switch.
 *
 * Shown on the sign-in screen as well as inside the app: a supervisor who reads
 * Hindi needs it before the login form, not after. Each language is labelled in
 * its own script, so someone who has landed in a language they cannot read can
 * still find the way back.
 *
 * The choice is stored and then handed to AppCompatDelegate, which recreates the
 * activity itself — nothing here has to restart anything.
 */
@Composable
fun LanguageSwitch(modifier: Modifier = Modifier, showSystemOption: Boolean = false) {
    val context = LocalContext.current
    val session = remember { ServiceLocator.session(context) }
    var current by remember { mutableStateOf(AppLanguage.current(session.languageTag())) }

    val choices = if (showSystemOption) {
        AppLanguage.CHOICES
    } else {
        AppLanguage.CHOICES.filter { it != AppLanguage.SYSTEM }
    }

    Row(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.Center,
    ) {
        choices.forEachIndexed { index, language ->
            if (index > 0) {
                Text("  ")
            }

            FilterChip(
                selected = language == current,
                onClick = {
                    session.setLanguageTag(language.tag)
                    current = language
                    AppLanguage.apply(language)
                },
                label = {
                    Text(
                        when (language) {
                            AppLanguage.SYSTEM -> stringResource(R.string.language_system)
                            AppLanguage.ENGLISH -> stringResource(R.string.language_english)
                            AppLanguage.HINDI -> stringResource(R.string.language_hindi)
                        },
                    )
                },
            )
        }
    }
}
