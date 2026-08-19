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
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import java.io.File
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
    "customer_met" to R.string.status_customer_met,
    "family_met" to R.string.status_family_met,
    "phone_contact" to R.string.status_phone_contact,
    "house_locked" to R.string.status_house_locked,
    "not_available" to R.string.status_not_available,
    "address_not_found" to R.string.status_address_not_found,
    "shifted" to R.string.status_shifted,
    "deceased" to R.string.status_deceased,
    "refused" to R.string.status_refused,
    "other" to R.string.status_other,
)

/**
 * The evidence slots, in the reference app's order: the borrower, the house, the
 * Aadhaar copy, then the agent's own photograph. Shop, land and document follow
 * because a CKCC crop case needs them and visits already filed use them.
 *
 * "selfie" is the agent's own photograph. Every photograph in this app comes from
 * the camera — there is no gallery import anywhere — so the reference's rule that
 * the agent's photograph cannot be picked from storage holds here by construction.
 */
private val photoTypes = listOf(
    "customer" to R.string.photo_type_customer,
    "house" to R.string.photo_type_house,
    "aadhaar" to R.string.photo_type_aadhaar,
    "selfie" to R.string.photo_type_selfie,
    "shop" to R.string.photo_type_shop,
    "land" to R.string.photo_type_land,
    "document" to R.string.photo_type_document,
    "other" to R.string.photo_type_other,
)

/**
 * How the borrower paid the bank. The key is stored and reported; the label is
 * translated.
 *
 * There is no cash entry. This company does no cash collection — the borrower pays
 * the bank and the agent records the bank's receipt — so offering a mode that means
 * "handed to me" would invite something nobody here is authorised to do.
 */
internal val paymentModes = listOf(
    "UPI" to R.string.payment_upi,
    "Bank Transfer" to R.string.payment_bank,
    "Cheque" to R.string.payment_cheque,
)

/** Recovery possibility, same split of stored key and shown label. */
private val possibilities = listOf(
    "high" to R.string.possibility_high,
    "medium" to R.string.possibility_medium,
    "low" to R.string.possibility_low,
    "nil" to R.string.possibility_nil,
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
    var recoveryMode by remember { mutableStateOf("UPI") }
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
                title = { Text(stringResource(R.string.visit_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.action_back))
                    }
                },
            )
        },
    ) { padding ->
        if (current == null) {
            LoadingBlock(stringResource(R.string.visit_loading), Modifier.padding(padding))

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

                    DetailRow(stringResource(R.string.visit_started), Times.humanDateTime(current.startedAt))
                    DetailRow(
                        stringResource(R.string.visit_gps),
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
                            stringResource(R.string.visit_mock_location),
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
                        Text(
                            stringResource(R.string.visit_photographs),
                            style = MaterialTheme.typography.titleSmall,
                            modifier = Modifier.weight(1f),
                        )
                        StatusChip(
                            text = stringResource(R.string.visit_photo_count, photos.size),
                            tone = if (photos.isEmpty()) Tone.WARNING else Tone.SUCCESS,
                        )
                    }

                    Text(
                        stringResource(R.string.visit_photo_watermark),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(8.dp))

                    photoTypes.chunked(3).forEach { row ->
                        Row(
                            Modifier.fillMaxWidth().padding(bottom = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            row.forEach { (key, labelRes) ->
                                FilterChip(
                                    selected = photoType == key,
                                    onClick = { photoType = key },
                                    label = { Text(stringResource(labelRes)) },
                                )
                            }
                        }
                    }

                    // Says why this slot is a photograph of the agent, not of the case.
                    if (photoType == "selfie") {
                        Text(
                            stringResource(R.string.photo_selfie_note),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
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
                        Text("  " + stringResource(R.string.photo_take))
                    }

                    photos.forEach { photo ->
                        Row(Modifier.fillMaxWidth().padding(top = 8.dp)) {
                            Column(Modifier.weight(1f)) {
                                Text(
                                    photoTypes.firstOrNull { it.first == photo.photoType }
                                        ?.let { stringResource(it.second) }
                                        ?: photo.photoType,
                                    style = MaterialTheme.typography.bodyMedium,
                                )
                                Text(
                                    Times.humanDateTime(photo.capturedAt),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }

                            StatusChip(stringResource(R.string.status_queued), Tone.NEUTRAL)
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

                    Spacer(Modifier.height(10.dp))

                    // A dropdown, as the reference app does it: seven case types as a
                    // row of chips filled the screen before the first question, and
                    // the list will only grow. The field reads as one answer with one
                    // value, which is what it is.
                    var caseTypeOpen by remember { mutableStateOf(false) }
                    val selectedLabel = caseTypes.firstOrNull { it.first == caseType }
                        ?.let { stringResource(it.second) }
                        ?: ""

                    ExposedDropdownMenuBox(
                        expanded = caseTypeOpen,
                        onExpandedChange = { caseTypeOpen = !caseTypeOpen },
                    ) {
                        OutlinedTextField(
                            value = selectedLabel,
                            onValueChange = {},
                            readOnly = true,
                            label = { Text(stringResource(R.string.visit_case_type)) },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = caseTypeOpen) },
                            modifier = Modifier
                                .fillMaxWidth()
                                .menuAnchor(MenuAnchorType.PrimaryNotEditable),
                        )

                        ExposedDropdownMenu(
                            expanded = caseTypeOpen,
                            onDismissRequest = { caseTypeOpen = false },
                        ) {
                            caseTypes.forEach { (key, labelRes) ->
                                DropdownMenuItem(
                                    text = { Text(stringResource(labelRes)) },
                                    onClick = {
                                        viewModel.setVisitType(visitUuid, key)
                                        caseTypeOpen = false
                                    },
                                )
                            }
                        }
                    }
                }
            }

            // Outcome
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.visit_outcome), style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(8.dp))

                    visitStatuses.chunked(2).forEach { row ->
                        Row(
                            Modifier.fillMaxWidth().padding(bottom = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            row.forEach { (key, labelRes) ->
                                FilterChip(
                                    selected = visitStatus == key,
                                    onClick = { visitStatus = key },
                                    label = { Text(stringResource(labelRes)) },
                                    modifier = Modifier.weight(1f),
                                )
                            }

                            if (row.size == 1) {
                                Spacer(Modifier.weight(1f))
                            }
                        }
                    }

                    Spacer(Modifier.height(6.dp))
                    Text(stringResource(R.string.visit_possibility), style = MaterialTheme.typography.bodySmall)

                    Row(
                        Modifier.fillMaxWidth().padding(top = 4.dp),
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        possibilities.forEach { (key, labelRes) ->
                            FilterChip(
                                selected = possibility == key,
                                onClick = { possibility = key },
                                label = { Text(stringResource(labelRes)) },
                            )
                        }
                    }

                    Spacer(Modifier.height(10.dp))

                    OutlinedTextField(
                        value = remarks,
                        onValueChange = { remarks = it },
                        label = { Text(stringResource(R.string.label_remarks)) },
                        minLines = 2,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(Modifier.height(8.dp))

                    OutlinedTextField(
                        value = recommendation,
                        onValueChange = { recommendation = it },
                        label = { Text(stringResource(R.string.visit_recommendation)) },
                        minLines = 2,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }

            // Money
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.visit_recovery_promise), style = MaterialTheme.typography.titleSmall)
                    Text(
                        stringResource(R.string.visit_recovery_blank),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    // The rule, on the screen rather than only in a policy document.
                    Text(
                        stringResource(R.string.recovery_no_cash),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.error,
                        modifier = Modifier.padding(top = 4.dp),
                    )

                    Spacer(Modifier.height(8.dp))

                    OutlinedTextField(
                        value = recoveryAmount,
                        onValueChange = { recoveryAmount = it.filter { char -> char.isDigit() || char == '.' } },
                        label = { Text(stringResource(R.string.recovery_amount)) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    if (recoveryAmount.isNotBlank()) {
                        Row(
                            Modifier.fillMaxWidth().padding(top = 6.dp),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            paymentModes.forEach { (key, labelRes) ->
                                FilterChip(
                                    selected = recoveryMode == key,
                                    onClick = { recoveryMode = key },
                                    label = { Text(stringResource(labelRes)) },
                                )
                            }
                        }

                        OutlinedTextField(
                            value = recoveryReceipt,
                            onValueChange = { recoveryReceipt = it },
                            label = { Text(stringResource(R.string.recovery_receipt)) },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
                        )
                    }

                    Spacer(Modifier.height(10.dp))

                    OutlinedTextField(
                        value = promiseAmount,
                        onValueChange = { promiseAmount = it.filter { char -> char.isDigit() || char == '.' } },
                        label = { Text(stringResource(R.string.ptp_amount)) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    if (promiseAmount.isNotBlank()) {
                        OutlinedTextField(
                            value = promiseDate,
                            onValueChange = { promiseDate = it },
                            label = { Text(stringResource(R.string.ptp_date)) },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
                        )
                    }

                    // Half a promise is not stored as one: the server opens a promise
                    // case only when an amount and a date arrive together, so an agent
                    // who clears the date would watch the promise disappear without
                    // being told. A warning, not a block — nothing on this form is
                    // mandatory, and the visit is still worth filing without it.
                    val promiseValue = promiseAmount.replace(",", "").trim().toDoubleOrNull()
                    if ((promiseValue != null && promiseValue > 0.0) != promiseDate.isNotBlank()) {
                        Text(
                            stringResource(R.string.visit_promise_incomplete),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.error,
                            modifier = Modifier.padding(top = 6.dp),
                        )
                    }
                }
            }

            // The configurable form from the server.
            if (fields.isNotEmpty()) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(stringResource(R.string.visit_form_title), style = MaterialTheme.typography.titleSmall)
                        Text(
                            stringResource(R.string.visit_form_note),
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
                Text(stringResource(R.string.visit_submit))
            }

            OutlinedButton(onClick = { confirmDiscard = true }, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.visit_discard))
            }

            Text(
                stringResource(R.string.visit_queue_note),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(24.dp))
        }
    }

    if (confirmDiscard) {
        AlertDialog(
            onDismissRequest = { confirmDiscard = false },
            title = { Text(stringResource(R.string.visit_discard_title)) },
            text = { Text(stringResource(R.string.visit_discard_body)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmDiscard = false
                    viewModel.discardVisit(visitUuid, onDone)
                }) {
                    Text(stringResource(R.string.action_discard))
                }
            },
            dismissButton = { TextButton(onClick = { confirmDiscard = false }) { Text(stringResource(R.string.action_keep)) } },
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
                    listOf("Yes" to R.string.common_yes, "No" to R.string.common_no).forEach { (option, labelRes) ->
                        FilterChip(
                            selected = value == option,
                            onClick = { onChange(option) },
                            label = { Text(stringResource(labelRes)) },
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

/**
 * The field's label, with no required marker.
 *
 * Nothing on this form is mandatory, so an asterisk would be a promise the app does
 * not keep — and worse, it would keep appearing on installations whose form fields
 * were created while is_required still meant something: seeding new defaults does
 * not rewrite rows that already exist. Ignoring the flag here means no live database
 * has to be edited for the screen to tell the truth.
 */
private fun label(field: FormFieldEntity): String = field.label
