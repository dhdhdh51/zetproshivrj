package `in`.lrms.field.ui.screens

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Map
import androidx.compose.material.icons.filled.Search
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
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.R
import `in`.lrms.field.data.local.AccountEntity
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.DetailRow
import `in`.lrms.field.ui.components.EmptyState
import `in`.lrms.field.ui.components.InlineNotice
import `in`.lrms.field.ui.components.LoadingBlock
import `in`.lrms.field.ui.components.StatusChip
import `in`.lrms.field.ui.components.Tone
import `in`.lrms.field.util.Times

private val filters = listOf(
    "all" to "All",
    "pending" to stringResource(R.string.account_not_visited),
    "visited" to stringResource(R.string.account_visited),
    "ptp" to "PTP",
    "krm_ots" to "KRM OTS",
    "ckcc_od2" to "CKCC OD-2",
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AccountsScreen(viewModel: AppViewModel, onOpenAccount: (Long) -> Unit) {
    val accounts by viewModel.accounts.collectAsStateWithLifecycle()
    val query by viewModel.query.collectAsStateWithLifecycle()
    val filter by viewModel.filter.collectAsStateWithLifecycle()
    val sync by viewModel.sync.collectAsStateWithLifecycle()

    Scaffold(
        topBar = { TopAppBar(title = { Text(stringResource(R.string.accounts_title)) }) },
    ) { padding ->
        Column(
            Modifier
                .fillMaxWidth()
                .padding(padding)
                .padding(horizontal = 14.dp),
        ) {
            OutlinedTextField(
                value = query,
                onValueChange = { viewModel.search(it) },
                label = { Text(stringResource(R.string.accounts_search_hint)) },
                leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
                singleLine = true,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(top = 8.dp),
            )

            Row(
                Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                filters.forEach { (key, label) ->
                    FilterChip(
                        selected = filter == key,
                        onClick = { viewModel.setFilter(key) },
                        label = { Text(label) },
                    )
                }
            }

            if (accounts.isEmpty()) {
                if (sync.busy) {
                    LoadingBlock(stringResource(R.string.accounts_loading))
                } else {
                    EmptyState(
                        icon = Icons.Filled.Search,
                        title = stringResource(R.string.accounts_empty),
                        message = stringResource(R.string.accounts_empty_hint),
                    )
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(accounts, key = { it.id }) { account ->
                        AccountCard(account) { onOpenAccount(account.id) }
                    }

                    item { Spacer(Modifier.height(16.dp)) }
                }
            }
        }
    }
}

@Composable
private fun AccountCard(account: AccountEntity, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(
            Modifier
                .fillMaxWidth()
                .padding(14.dp),
        ) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text(account.borrowerName, fontWeight = FontWeight.SemiBold)
                    Text(
                        buildString {
                            append(account.accountNumber)
                            account.village?.let { append(" · $it") }
                        },
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                Column(horizontalAlignment = Alignment.End) {
                    Text(Times.money(account.overdue), fontWeight = FontWeight.Bold)
                    Text(
                        "overdue",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            Spacer(Modifier.height(8.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                StatusChip(
                    text = if (account.visitCount == 0) "not visited" else "${account.visitCount} visit(s)",
                    tone = if (account.visitCount == 0) Tone.WARNING else Tone.SUCCESS,
                )

                if (account.loanCategory != "general") {
                    StatusChip(
                        text = account.loanCategory.replace('_', ' ').uppercase(),
                        tone = Tone.INFO,
                    )
                }

                if (account.recoveryStatus == "ptp") {
                    StatusChip(text = "PTP", tone = Tone.INFO)
                }
            }

            Spacer(Modifier.height(10.dp))

            Button(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
                Text("Open")
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AccountDetailScreen(
    viewModel: AppViewModel,
    accountId: Long,
    onBack: () -> Unit,
    onVisitStarted: (String) -> Unit,
) {
    val context = LocalContext.current
    var account by remember { mutableStateOf<AccountEntity?>(null) }
    val locationState by viewModel.location.collectAsStateWithLifecycle()
    var starting by remember { mutableStateOf(false) }
    var sheet by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(accountId) {
        account = viewModel.account(accountId)
        viewModel.clearLocation()
    }

    val current = account

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(current?.borrowerName ?: stringResource(R.string.label_account)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    current?.mobile?.let { mobile ->
                        IconButton(onClick = {
                            context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$mobile")))
                        }) {
                            Icon(Icons.Filled.Call, contentDescription = stringResource(R.string.account_call))
                        }
                    }

                    current?.village?.let { village ->
                        IconButton(onClick = {
                            val query = Uri.encode(listOfNotNull(village, current.address).joinToString(", "))
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:0,0?q=$query")))
                        }) {
                            Icon(Icons.Filled.Map, contentDescription = stringResource(R.string.account_navigate))
                        }
                    }
                },
            )
        },
    ) { padding ->
        if (current == null) {
            LoadingBlock(stringResource(R.string.account_loading), Modifier.padding(padding))

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
            message?.let { InlineNotice(it, Tone.SUCCESS) }

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.label_borrower), style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(6.dp))
                    DetailRow(stringResource(R.string.label_account), current.accountNumber)
                    DetailRow("CIF", current.cif)
                    DetailRow(stringResource(R.string.label_father), current.fatherName)
                    DetailRow(stringResource(R.string.label_mobile), current.mobile)
                    DetailRow(stringResource(R.string.label_village), current.village)
                    DetailRow(stringResource(R.string.label_address), current.address)
                    DetailRow(stringResource(R.string.label_branch), current.branchName)
                }
            }

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text("Loan", style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(6.dp))
                    DetailRow(stringResource(R.string.label_loan_type), current.loanType)
                    DetailRow(stringResource(R.string.label_outstanding), Times.money(current.outstanding))
                    DetailRow(stringResource(R.string.label_overdue), Times.money(current.overdue))
                    DetailRow(stringResource(R.string.label_limit), Times.money(current.limitAmount))
                    DetailRow(stringResource(R.string.label_recovered), Times.money(current.totalRecovered))
                    DetailRow(stringResource(R.string.label_npa_date), Times.humanDate(current.npaDate))
                    DetailRow("Visits", current.visitCount.toString())
                    DetailRow(stringResource(R.string.label_last_visit), Times.humanDateTime(current.lastVisitAt))
                    DetailRow(stringResource(R.string.label_recovery_status), current.recoveryStatus.replace('_', ' '))
                }
            }

            if (locationState.error != null) {
                InlineNotice(locationState.error!!, Tone.DANGER)
            }

            if (locationState.busy || starting) {
                LoadingBlock(stringResource(R.string.account_gps_waiting))
            }

            Button(
                onClick = {
                    starting = true

                    viewModel.captureLocation { fix ->
                        if (fix == null) {
                            starting = false
                        } else {
                            viewModel.startVisit(current, fix) { uuid ->
                                starting = false
                                onVisitStarted(uuid)
                            }
                        }
                    }
                },
                enabled = !starting && !locationState.busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.account_start_visit))
            }

            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { sheet = "recovery" }, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.recovery_add))
                }
                OutlinedButton(onClick = { sheet = "promise" }, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.ptp_add))
                }
            }

            OutlinedButton(onClick = { sheet = "followup" }, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.followup_schedule))
            }

            Text(
                stringResource(R.string.offline_entries_note),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(20.dp))
        }
    }

    when (sheet) {
        "recovery" -> RecoveryDialog(
            account = current,
            onDismiss = { sheet = null },
            onSave = { amount, mode, receipt, remarks ->
                current?.let {
                    viewModel.recordRecovery(it, amount, mode, receipt, remarks) { note ->
                        message = note
                        sheet = null
                    }
                }
            },
        )

        "promise" -> PromiseDialog(
            onDismiss = { sheet = null },
            onSave = { amount, date, remarks ->
                current?.let {
                    viewModel.recordPromise(it, amount, date, remarks) { note ->
                        message = note
                        sheet = null
                    }
                }
            },
        )

        "followup" -> FollowupDialog(
            onDismiss = { sheet = null },
            onSave = { date, action, notes ->
                current?.let {
                    viewModel.recordFollowup(it, date, action, notes) { note ->
                        message = note
                        sheet = null
                    }
                }
            },
        )
    }
}

@Composable
private fun RecoveryDialog(
    account: AccountEntity?,
    onDismiss: () -> Unit,
    onSave: (Double, String, String?, String?) -> Unit,
) {
    var amount by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("Cash") }
    var receipt by remember { mutableStateOf("") }
    var remarks by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.recovery_record)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                account?.let {
                    Text(
                        "${it.borrowerName} · overdue ${Times.money(it.overdue)}",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                OutlinedTextField(
                    value = amount,
                    onValueChange = { amount = it.filter { char -> char.isDigit() || char == '.' } },
                    label = { Text(stringResource(R.string.recovery_amount)) },
                    singleLine = true,
                )

                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("Cash", "UPI", "Bank Transfer").forEach { option ->
                        FilterChip(
                            selected = mode == option,
                            onClick = { mode = option },
                            label = { Text(option) },
                        )
                    }
                }

                OutlinedTextField(
                    value = receipt,
                    onValueChange = { receipt = it },
                    label = { Text(stringResource(R.string.recovery_receipt)) },
                    singleLine = true,
                )

                OutlinedTextField(
                    value = remarks,
                    onValueChange = { remarks = it },
                    label = { Text(stringResource(R.string.label_remarks)) },
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    val value = amount.toDoubleOrNull() ?: 0.0

                    if (value > 0) {
                        onSave(value, mode, receipt.ifBlank { null }, remarks.ifBlank { null })
                    }
                },
                enabled = (amount.toDoubleOrNull() ?: 0.0) > 0,
            ) {
                Text("Save")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) } },
    )
}

@Composable
private fun PromiseDialog(onDismiss: () -> Unit, onSave: (Double, String, String?) -> Unit) {
    var amount by remember { mutableStateOf("") }
    var date by remember { mutableStateOf(Times.date(7)) }
    var remarks by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.ptp_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = amount,
                    onValueChange = { amount = it.filter { char -> char.isDigit() || char == '.' } },
                    label = { Text(stringResource(R.string.ptp_amount)) },
                    singleLine = true,
                )
                OutlinedTextField(
                    value = date,
                    onValueChange = { date = it },
                    label = { Text(stringResource(R.string.ptp_date)) },
                    singleLine = true,
                )
                OutlinedTextField(
                    value = remarks,
                    onValueChange = { remarks = it },
                    label = { Text(stringResource(R.string.followup_reason)) },
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    val value = amount.toDoubleOrNull() ?: 0.0

                    if (value > 0) {
                        onSave(value, date, remarks.ifBlank { null })
                    }
                },
                enabled = (amount.toDoubleOrNull() ?: 0.0) > 0 && date.length == 10,
            ) {
                Text("Save")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) } },
    )
}

@Composable
private fun FollowupDialog(onDismiss: () -> Unit, onSave: (String, String, String?) -> Unit) {
    var date by remember { mutableStateOf(Times.date(3)) }
    var action by remember { mutableStateOf("visit") }
    var notes by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.followup_schedule)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = date,
                    onValueChange = { date = it },
                    label = { Text(stringResource(R.string.followup_date)) },
                    singleLine = true,
                )

                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("visit", "call", "notice").forEach { option ->
                        FilterChip(
                            selected = action == option,
                            onClick = { action = option },
                            label = { Text(option.replaceFirstChar { it.uppercase() }) },
                        )
                    }
                }

                OutlinedTextField(
                    value = notes,
                    onValueChange = { notes = it },
                    label = { Text(stringResource(R.string.label_notes)) },
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onSave(date, action, notes.ifBlank { null }) },
                enabled = date.length == 10,
            ) {
                Text("Save")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) } },
    )
}
