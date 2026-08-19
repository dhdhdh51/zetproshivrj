package `in`.lrms.field.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.R
import `in`.lrms.field.BuildConfig
import `in`.lrms.field.data.remote.ApiClient
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.InlineNotice
import `in`.lrms.field.ui.components.LanguageSwitch
import `in`.lrms.field.ui.components.Tone

@Composable
fun LoginScreen(viewModel: AppViewModel) {
    val auth by viewModel.auth.collectAsStateWithLifecycle()
    var username by rememberSaveable { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    Box(
        Modifier
            .fillMaxSize()
            .padding(24.dp),
        contentAlignment = Alignment.Center,
    ) {
        Card(Modifier.fillMaxWidth()) {
            Column(
                Modifier
                    .padding(24.dp)
                    .verticalScroll(rememberScrollState()),
            ) {
                Text(stringResource(R.string.login_title), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Text(
                    stringResource(R.string.login_subtitle),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(20.dp))

                // Installing the debug APK by mistake used to look like a bad
                // signal: every call to 10.0.2.2 just timed out. Say so up front.
                if (ApiClient.isDeveloperEndpoint) {
                    InlineNotice(
                        stringResource(R.string.login_developer_build, ApiClient.host),
                        Tone.WARNING,
                    )
                    Spacer(Modifier.height(12.dp))
                }

                if (auth.error != null) {
                    InlineNotice(auth.error!!, Tone.DANGER)
                    Spacer(Modifier.height(12.dp))
                }

                if (auth.diagnostic != null) {
                    InlineNotice(auth.diagnostic!!, Tone.INFO)
                    Spacer(Modifier.height(12.dp))
                }

                OutlinedTextField(
                    value = username,
                    onValueChange = { username = it },
                    // The BCBF code is what a supervisor has on their paperwork.
                    label = { Text(stringResource(R.string.login_identifier)) },
                    singleLine = true,
                    enabled = !auth.busy,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text(stringResource(R.string.login_password)) },
                    singleLine = true,
                    enabled = !auth.busy,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(20.dp))

                Button(
                    onClick = { viewModel.signIn(username, password) },
                    enabled = !auth.busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    if (auth.busy) {
                        CircularProgressIndicator(
                            strokeWidth = 2.dp,
                            modifier = Modifier.height(18.dp),
                            color = MaterialTheme.colorScheme.onPrimary,
                        )
                    } else {
                        Text(stringResource(R.string.login_button))
                    }
                }

                Spacer(Modifier.height(16.dp))

                Text(
                    stringResource(R.string.login_device_note),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(4.dp))

                // Nobody could previously tell which server an APK was built
                // against, which made a wrong URL indistinguishable from a dead
                // network. Now it is on the screen, and testable before sign-in.
                // Before the login form, not after: someone who reads Hindi has to
                // be able to switch first.
                LanguageSwitch()

                TextButton(
                    onClick = { viewModel.testConnection() },
                    enabled = !auth.testing && !auth.busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(if (auth.testing) stringResource(R.string.login_testing) else stringResource(R.string.login_test_connection))
                }

                Text(
                    ApiClient.baseUrl,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.outline,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(4.dp))

                Text(
                    "${BuildConfig.ENVIRONMENT} · v${BuildConfig.VERSION_NAME} · Android ${android.os.Build.VERSION.RELEASE}",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.outline,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}

@Composable
fun OtpScreen(viewModel: AppViewModel) {
    val auth by viewModel.auth.collectAsStateWithLifecycle()
    var code by remember { mutableStateOf("") }

    Box(
        Modifier
            .fillMaxSize()
            .padding(24.dp),
        contentAlignment = Alignment.Center,
    ) {
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(24.dp)) {
                Text(stringResource(R.string.otp_title), style = MaterialTheme.typography.titleLarge)

                Spacer(Modifier.height(8.dp))

                Text(
                    auth.otpMessage ?: stringResource(R.string.otp_default_message),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(16.dp))

                if (auth.error != null) {
                    InlineNotice(auth.error!!, Tone.DANGER)
                    Spacer(Modifier.height(12.dp))
                }

                OutlinedTextField(
                    value = code,
                    onValueChange = { code = it.filter { char -> char.isDigit() }.take(8) },
                    label = { Text(stringResource(R.string.otp_code)) },
                    singleLine = true,
                    enabled = !auth.busy,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(18.dp))

                Button(
                    onClick = { viewModel.verifyOtp(code) },
                    enabled = !auth.busy && code.length >= 4,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.otp_verify))
                }

                TextButton(
                    onClick = { viewModel.cancelOtp() },
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.otp_start_again))
                }
            }
        }
    }
}

@Composable
fun ChangePasswordScreen(viewModel: AppViewModel, forced: Boolean, onDone: () -> Unit) {
    var current by remember { mutableStateOf("") }
    var next by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(
            if (forced) stringResource(R.string.password_title) else stringResource(R.string.password_change),
            style = MaterialTheme.typography.titleLarge,
        )

        if (forced) {
            InlineNotice(
                stringResource(R.string.password_temporary_note),
                Tone.WARNING,
            )
        }

        error?.let { InlineNotice(it, Tone.DANGER) }

        OutlinedTextField(
            value = current,
            onValueChange = { current = it },
            label = { Text(stringResource(R.string.password_current)) },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth(),
        )

        OutlinedTextField(
            value = next,
            onValueChange = { next = it },
            label = { Text(stringResource(R.string.password_new)) },
            supportingText = { Text(stringResource(R.string.password_rule)) },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth(),
        )

        OutlinedTextField(
            value = confirm,
            onValueChange = { confirm = it },
            label = { Text(stringResource(R.string.password_confirm)) },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth(),
        )

        Button(
            onClick = {
                busy = true
                error = null

                viewModel.changePassword(current, next, confirm) { message ->
                    busy = false
                    error = message

                    if (message == null) {
                        onDone()
                    }
                }
            },
            enabled = !busy && current.isNotBlank() && next.isNotBlank(),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(stringResource(R.string.password_update))
        }

        if (!forced) {
            TextButton(onClick = onDone, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.action_cancel))
            }
        }
    }
}
