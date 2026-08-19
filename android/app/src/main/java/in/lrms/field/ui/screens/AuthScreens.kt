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
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.BuildConfig
import `in`.lrms.field.data.remote.ApiClient
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.InlineNotice
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
                Text("LRMS Field", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Text(
                    "BC Supervisor sign-in",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(20.dp))

                // Installing the debug APK by mistake used to look like a bad
                // signal: every call to 10.0.2.2 just timed out. Say so up front.
                if (ApiClient.isDeveloperEndpoint) {
                    InlineNotice(
                        "This is a developer build. It is set to ${ApiClient.host}, which only exists on " +
                            "an emulator, so sign-in cannot work on a phone. Install the release APK instead.",
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
                    label = { Text("BCBF code or username") },
                    singleLine = true,
                    enabled = !auth.busy,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("Password") },
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
                        Text("Sign in")
                    }
                }

                Spacer(Modifier.height(16.dp))

                Text(
                    "Your account is bound to this device. Ask your Admin/Supervisor to reset the " +
                        "binding if you change handset.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(4.dp))

                // Nobody could previously tell which server an APK was built
                // against, which made a wrong URL indistinguishable from a dead
                // network. Now it is on the screen, and testable before sign-in.
                TextButton(
                    onClick = { viewModel.testConnection() },
                    enabled = !auth.testing && !auth.busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(if (auth.testing) "Checking the server…" else "Test connection")
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
                Text("Verify sign-in", style = MaterialTheme.typography.titleLarge)

                Spacer(Modifier.height(8.dp))

                Text(
                    auth.otpMessage ?: "Enter the verification code sent to you.",
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
                    label = { Text("Verification code") },
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
                    Text("Verify and continue")
                }

                TextButton(
                    onClick = { viewModel.cancelOtp() },
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text("Start again")
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
            if (forced) "Set a new password" else "Change password",
            style = MaterialTheme.typography.titleLarge,
        )

        if (forced) {
            InlineNotice(
                "Your account uses a temporary password. Choose a new one to continue.",
                Tone.WARNING,
            )
        }

        error?.let { InlineNotice(it, Tone.DANGER) }

        OutlinedTextField(
            value = current,
            onValueChange = { current = it },
            label = { Text("Current password") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth(),
        )

        OutlinedTextField(
            value = next,
            onValueChange = { next = it },
            label = { Text("New password") },
            supportingText = { Text("At least 8 characters, with a letter and a number.") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth(),
        )

        OutlinedTextField(
            value = confirm,
            onValueChange = { confirm = it },
            label = { Text("Confirm new password") },
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
            Text("Update password")
        }

        if (!forced) {
            TextButton(onClick = onDone, modifier = Modifier.fillMaxWidth()) {
                Text("Cancel")
            }
        }
    }
}
