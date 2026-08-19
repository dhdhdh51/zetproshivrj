package `in`.lrms.field.ui.screens

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.R
import `in`.lrms.field.camera.PhotoFiles
import `in`.lrms.field.data.local.FormFieldEntity
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.DetailRow
import `in`.lrms.field.ui.components.InlineNotice
import `in`.lrms.field.ui.components.LoadingBlock
import `in`.lrms.field.ui.components.StatusChip
import `in`.lrms.field.ui.components.Tone
import `in`.lrms.field.util.FormLogic
import `in`.lrms.field.util.Times
import java.io.File

/**
 * The case types the printed form offers, in the server's order (Visits::CASE_TYPES).
 *
 * Resource ids rather than strings: this list is built once at class load, before
 * the chosen language is known.
 */
private val caseTypes = listOf(
    "krm_ots" to R.string.case_type_krm_ots,
    "ckcc_od2" to R.string.case_type_ckcc_od2,
    "recovery_followup" to R.string.case_type_recovery_followup,
    "pre_npa" to R.string.case_type_pre_npa,
    "post_npa" to R.string.case_type_post_npa,
    "customer" to R.string.case_type_customer,
    "other" to R.string.case_type_other,
)

private val visitStatuses = listOf(
    "customer_met" to "Customer met",
    "family_met" to "Family met",
    "phone_contact" to "Phone only",
    "house_locked" to "House locked",
    "not_available" to "Not available",
    "address_not_found" to "Address not found",
    "shifted" to "Shifted",
    "deceased" to "Deceased",
    "refused" to "Refused",
    "other" to "Other",
)

private val photoTypes = listOf(
    "customer" to "Customer",
    "house" to "House",
    "shop" to "Shop",
    "land" to "Land",
    "document" to "Document",
    "other" to "Other",
)

/**
 * The customer visit: evidence first, then the configurable form, then submit.
 *
 * The visit is already stored locally with its GPS fix by the time this screen
 * opens, so closing the app mid-visit loses nothing.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun VisitScreen(
    viewModel: AppViewModel,
    visitUuid: String,
    onDone: () -> Unit,
    onBack: () -> Unit,
) {
    val context = LocalContext.current
    val visit by viewModel.observeVisit(visitUuid).collectAsState(initial = null)
    val photos by viewModel.observePhotos(visitUuid).collectAsState(initial = emptyList())
    val fields by viewModel.formFieldsFor(visitUuid).collectAsState(initial = emptyList())
    val caseType by viewModel.observeVisitType(visitUuid).collectAsState(initial = null)
    val locationState by viewModel.location.collectAsStateWithLifecycle()

    val answers = remember { mutableStateMapOf<String, String>() }
    var visitStatus by remember { mutableStateOf("customer_met") }
    var possibility by remember { mutableStateOf("medium") }
    var remarks by remember { mutableStateOf("") }
    var recommendation by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDiscard by remember { mutableStateOf(false) }
    var pendingPhoto by remember { mutableStateOf<File?>(null) }
    var photoType by remember { mutableStateOf("customer") }

    // Money captured with the visit.
    var recoveryAmount by remember { mutableStateOf("") }
    var recoveryMode by remember { mutableStateOf("Cash") }
    var recoveryReceipt by remember { mutableStateOf("") }
    var promiseAmount by remember { mutableStateOf("") }
    var promiseDate by remember { mutableStateOf(Times.date(7)) }

    val cameraLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        val file = pendingPhoto

        if (success && file != null && PhotoFiles.compress(file)) {
            viewModel.addPhoto(
                visitUuid = visitUuid,
                file = file,
                photoType = photoType,
                caption = null,
                fix = locationState.fix,
            )
        } else if (file != null) {
            file.delete()
        }

        pendingPhoto = null
    }

    val current = visit

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Customer visit") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        if (current == null) {
            LoadingBlock("Loading visit…", Modifier.padding(padding))

            return@Scaffold
        }

        Column(
            Modifier
                .fillMaxWidth()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(current.borrowerName, fontWeight = FontWeight.SemiBold)
                    Text(
                        current.accountNumber,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(8.dp))

                    DetailRow("Started", Times.humanDateTime(current.startedAt))
                    DetailRow(
                        "GPS",
                        current.latitude?.let { latitude ->
                            "%.6f, %.6f (±%.0f m)".format(
                                latitude,
                                current.longitude ?: 0.0,
                                current.accuracy ?: 0.0,
                            )
                        },
                    )

                    if (current.isMock) {
                        Spacer(Modifier.height(6.dp))
                        InlineNotice(
                            "This fix reports itself as a mock location and the server will reject it.",
                            Tone.DANGER,
                        )
                    }
                }
            }

            error?.let { InlineNotice(it, Tone.DANGER) }

            // Photographs
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Row {
                        Text("Photographs", style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
                        StatusChip(
                            text = "${photos.size} taken",
                            tone = if (photos.isEmpty()) Tone.WARNING else Tone.SUCCESS,
                        )
                    }

                    Text(
                        "Photographs are watermarked with your name, " +
                            "the time and the coordinates.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(8.dp))

                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        photoTypes.take(3).forEach { (key, label) ->
                            FilterChip(
                                selected = photoType == key,
                                onClick = { photoType = key },
                                label = { Text(label) },
                            )
                        }
                    }

                    Row(
                        Modifier.fillMaxWidth().padding(top = 6.dp),
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        photoTypes.drop(3).forEach { (key, label) ->
                            FilterChip(
                                selected = photoType == key,
                                onClick = { photoType = key },
                                label = { Text(label) },
                            )
                        }
                    }

                    Spacer(Modifier.height(8.dp))

                    OutlinedButton(
                        onClick = {
                            val file = PhotoFiles.newFile(context, "visit")
                            pendingPhoto = file
                            cameraLauncher.launch(PhotoFiles.uriFor(context, file))
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Icon(Icons.Filled.CameraAlt, contentDescription = null)
                        Spacer(Modifier.height(4.dp))
                        Text("  Take photograph")
                    }

                    photos.forEach { photo ->
                        Row(Modifier.fillMaxWidth().padding(top = 8.dp)) {
                            Column(Modifier.weight(1f)) {
                                Text(
                                    photoTypes.firstOrNull { it.first == photo.photoType }?.second ?: photo.photoType,
                                    style = MaterialTheme.typography.bodyMedium,
                                )
                                Text(
                                    Times.humanDateTime(photo.capturedAt),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }

                            StatusChip("queued", Tone.NEUTRAL)
                        }
                    }
                }
            }

            // Case type first: it decides which questions follow and which box is
            // ticked on the printed report. Only the person at the door knows
            // whether this call is a renewal visit or a pre-NPA check.
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.visit_case_type), style = MaterialTheme.typography.titleSmall)
                    Text(
                        stringResource(R.string.visit_case_type_helper),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(8.dp))

                    caseTypes.chunked(2).forEach { row ->
                        Row(
                            Modifier.fillMaxWidth().padding(bottom = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            row.forEach { (key, labelRes) ->
                                FilterChip(
                                    selected = caseType == key,
                                    onClick = { viewModel.setVisitType(visitUuid, key) },
                                    label = { Text(stringResource(labelRes)) },
                                    modifier = Modifier.weight(1f),
                                )
                            }

                            if (row.size == 1) {
                                Spacer(Modifier.weight(1f))
                            }
                        }
                    }
                }
            }

            // Outcome
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text("Visit outcome", style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(8.dp))

                    visitStatuses.chunked(2).forEach { row ->
                        Row(
                            Modifier.fillMaxWidth().padding(bottom = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            row.forEach { (key, label) ->
                                FilterChip(
                                    selected = visitStatus == key,
                                    onClick = { visitStatus = key },
                                    label = { Text(label) },
                                    modifier = Modifier.weight(1f),
                                )
                            }

                            if (row.size == 1) {
                                Spacer(Modifier.weight(1f))
                            }
                        }
                    }

                    Spacer(Modifier.height(6.dp))
                    Text("Recovery possibility", style = MaterialTheme.typography.bodySmall)

                    Row(
                        Modifier.fillMaxWidth().padding(top = 4.dp),
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        listOf("high", "medium", "low", "nil").forEach { option ->
                            FilterChip(
                                selected = possibility == option,
                                onClick = { possibility = option },
                                label = { Text(option.replaceFirstChar { it.uppercase() }) },
                            )
                        }
                    }

                    Spacer(Modifier.height(10.dp))

                    OutlinedTextField(
                        value = remarks,
                        onValueChange = { remarks = it },
                        label = { Text("Remarks") },
                        minLines = 2,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(Modifier.height(8.dp))

                    OutlinedTextField(
                        value = recommendation,
                        onValueChange = { recommendation = it },
                        label = { Text("Your recommendation") },
                        minLines = 2,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }

            // Money
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text("Recovery and promise", style = MaterialTheme.typography.titleSmall)
                    Text(
                        "Leave blank when nothing was collected or promised.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(8.dp))

                    OutlinedTextField(
                        value = recoveryAmount,
                        onValueChange = { recoveryAmount = it.filter { char -> char.isDigit() || char == '.' } },
                        label = { Text("Amount collected") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    if (recoveryAmount.isNotBlank()) {
                        Row(
                            Modifier.fillMaxWidth().padding(top = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            listOf("Cash", "UPI", "Bank Transfer").forEach { option ->
                                FilterChip(
                                    selected = recoveryMode == option,
                                    onClick = { recoveryMode = option },
                                    label = { Text(option) },
                                )
                            }
                        }

                        OutlinedTextField(
                            value = recoveryReceipt,
                            onValueChange = { recoveryReceipt = it },
                            label = { Text("Receipt number") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
                        )
                    }

                    Spacer(Modifier.height(10.dp))

                    OutlinedTextField(
                        value = promiseAmount,
                        onValueChange = { promiseAmount = it.filter { char -> char.isDigit() || char == '.' } },
                        label = { Text("Promised amount") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    if (promiseAmount.isNotBlank()) {
                        OutlinedTextField(
                            value = promiseDate,
                            onValueChange = { promiseDate = it },
                            label = { Text("Promise date (YYYY-MM-DD)") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
                        )
                    }
                }
            }

            // The configurable form from the server.
            if (fields.isNotEmpty()) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text("Visit form", style = MaterialTheme.typography.titleSmall)
                        Text(
                            "Configured by your Admin/Supervisor.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )

                        Spacer(Modifier.height(6.dp))

                        fields.forEach { field ->
                            if (FormLogic.isVisible(field, answers)) {
                                DynamicField(
                                    field = field,
                                    value = answers[field.fieldKey] ?: "",
                                    onChange = { answers[field.fieldKey] = it },
                                )
                            }
                        }
                    }
                }
            }

            Button(
                onClick = {
                    val validation = FormLogic.validateVisit(
                        photoCount = photos.size,
                        minPhotos = 1,
                        remarks = remarks,
                        fields = fields,
                        answers = answers,
                    )

                    if (validation != null) {
                        error = validation

                        return@Button
                    }

                    error = null

                    val recovery = recoveryAmount.toDoubleOrNull()?.takeIf { it > 0 }?.let { amount ->
                        mapOf(
                            "amount" to amount,
                            "recovery_date" to Times.today(),
                            "payment_mode" to recoveryMode,
                            "receipt_number" to recoveryReceipt.ifBlank { null },
                        )
                    }

                    val promise = promiseAmount.toDoubleOrNull()?.takeIf { it > 0 }?.let { amount ->
                        mapOf(
                            "promise_amount" to amount,
                            "promise_date" to promiseDate,
                            "remarks" to remarks.take(400),
                        )
                    }

                    viewModel.submitVisit(
                        uuid = visitUuid,
                        visitStatus = visitStatus,
                        recoveryPossibility = possibility,
                        remarks = remarks,
                        recommendation = recommendation.ifBlank { null },
                        answers = answers.toMap(),
                        recovery = recovery,
                        promise = promise,
                        followup = null,
                        onDone = onDone,
                    )
                },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Submit visit")
            }

            OutlinedButton(onClick = { confirmDiscard = true }, modifier = Modifier.fillMaxWidth()) {
                Text("Discard this visit")
            }

            Text(
                "Submitting saves the visit on this device and queues it. It reaches LRMS at the " +
                    "next sync, even if you are offline now.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(24.dp))
        }
    }

    if (confirmDiscard) {
        AlertDialog(
            onDismissRequest = { confirmDiscard = false },
            title = { Text("Discard visit?") },
            text = { Text("The photographs and answers captured for this visit will be deleted.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDiscard = false
                    viewModel.discardVisit(visitUuid, onDone)
                }) {
                    Text("Discard")
                }
            },
            dismissButton = { TextButton(onClick = { confirmDiscard = false }) { Text("Keep") } },
        )
    }
}

@Composable
private fun DynamicField(field: FormFieldEntity, value: String, onChange: (String) -> Unit) {
    val options = field.options?.split('\n')?.filter { it.isNotBlank() } ?: emptyList()

    when (field.type) {
        "section" -> {
            Text(
                field.label,
                style = MaterialTheme.typography.titleSmall,
                modifier = Modifier.padding(top = 12.dp, bottom = 2.dp),
            )
            field.help?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }

        "photo", "gps", "signature" -> Unit // Captured elsewhere on this screen.

        "yes_no" -> {
            Column(Modifier.padding(top = 10.dp)) {
                Text(label(field), style = MaterialTheme.typography.bodyMedium)

                Row(
                    Modifier.padding(top = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    listOf("Yes", "No").forEach { option ->
                        FilterChip(
                            selected = value == option,
                            onClick = { onChange(option) },
                            label = { Text(option) },
                        )
                    }
                }
            }
        }

        "dropdown", "radio" -> {
            Column(Modifier.padding(top = 10.dp)) {
                Text(label(field), style = MaterialTheme.typography.bodyMedium)

                options.chunked(2).forEach { row ->
                    Row(
                        Modifier.fillMaxWidth().padding(top = 4.dp),
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        row.forEach { option ->
                            FilterChip(
                                selected = value == option,
                                onClick = { onChange(option) },
                                label = { Text(option) },
                                modifier = Modifier.weight(1f),
                            )
                        }

                        if (row.size == 1) {
                            Spacer(Modifier.weight(1f))
                        }
                    }
                }
            }
        }

        "textarea", "remarks" -> OutlinedTextField(
            value = value,
            onValueChange = onChange,
            label = { Text(label(field)) },
            minLines = 2,
            modifier = Modifier.fillMaxWidth().padding(top = 10.dp),
        )

        "number", "decimal" -> OutlinedTextField(
            value = value,
            onValueChange = { text -> onChange(text.filter { it.isDigit() || it == '.' || it == '-' }) },
            label = { Text(label(field)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth().padding(top = 10.dp),
        )

        else -> OutlinedTextField(
            value = value,
            onValueChange = onChange,
            label = { Text(label(field)) },
            singleLine = true,
            supportingText = field.help?.let { { Text(it) } },
            modifier = Modifier.fillMaxWidth().padding(top = 10.dp),
        )
    }
}

private fun label(field: FormFieldEntity): String =
    if (field.required) "${field.label} *" else field.label
