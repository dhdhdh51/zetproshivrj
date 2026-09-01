package `in`.lrms.field.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.R
import `in`.lrms.field.data.local.SyncState
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.DetailRow
import `in`.lrms.field.ui.components.InlineNotice
import `in`.lrms.field.ui.components.StatTile
import `in`.lrms.field.ui.components.Tone
import `in`.lrms.field.ui.theme.StatusSuccess
import `in`.lrms.field.util.Times

/** Highest a single day's count may be, per scheme. Matches the server. */
private const val MAX_PER_SCHEME = 999

/**
 * Social Security Scheme enrolments for today: how many people the supervisor signed up
 * for APY, PMJJBY, PMSBY and PMJDY.
 *
 * The figures are typed once per day and can be corrected. Nothing here is mandatory — a
 * scheme with no enrolments is left blank, and a blank box is read as none. Making somebody
 * type four zeros to say "nothing today" only teaches them to skip the screen.
 *
 * Works offline like every other write in this app: the entry is stored on the handset and
 * queued, and correcting it before a signal returns replaces the queued entry rather than
 * adding a second one.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SssScreen(viewModel: AppViewModel, onBack: () -> Unit) {
    val today by viewModel.sssToday.collectAsStateWithLifecycle()
    val monthTotal by viewModel.sssMonthTotal.collectAsStateWithLifecycle()

    var apy by remember { mutableStateOf("") }
    var pmjjby by remember { mutableStateOf("") }
    var pmsby by remember { mutableStateOf("") }
    var pmjdy by remember { mutableStateOf("") }
    var remarks by remember { mutableStateOf("") }
    var message by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }

    // Reopen on what was last recorded, so a correction starts from the real figures
    // instead of an empty form. A zero shows as blank: that is how it was entered, and
    // showing "0" in every box makes a day nobody has touched look like a day of nothing.
    LaunchedEffect(today?.date, today?.syncState) {
        if (!loaded && today != null) {
            apy = today!!.apyCount.blankIfZero()
            pmjjby = today!!.pmjjbyCount.blankIfZero()
            pmsby = today!!.pmsbyCount.blankIfZero()
            pmjdy = today!!.pmjdyCount.blankIfZero()
            remarks = today!!.remarks.orEmpty()
            loaded = true
        }
    }

    // Re-read when the day's row changes, which is when a sync has just been through and
    // the cached target may be newer. Not a flow: the target is a small cached scalar, like
    // the report deadline, and the screen has to open with no signal.
    val target = remember(today?.syncState, today?.status, today?.date) { viewModel.sssTargetState() }

    val locked = today?.locked == true
    val rejected = today?.syncState == SyncState.REJECTED || today?.syncState == SyncState.FAILED

    val typedTotal = listOf(apy, pmjjby, pmsby, pmjdy).sumOf { it.trim().toIntOrNull() ?: 0 }

    // The comparison updates as the figures are typed, so the supervisor sees where the day
    // stands before committing to it. Integer percentages on purpose: this is a progress
    // figure on a cheap handset, not an accounting one, and a decimal point buys nothing.
    val achievedToday = if (locked) (today?.total ?: 0) else typedTotal
    val dayGap = (target.dayTarget - achievedToday).coerceAtLeast(0)
    val dayPercent = if (target.dayTarget > 0) achievedToday * 100 / target.dayTarget else null
    val monthGap = (target.monthTarget - monthTotal).coerceAtLeast(0)
    val monthPercent = if (target.monthTarget > 0) monthTotal * 100 / target.monthTarget else null
    val overLimit = listOf(apy, pmjjby, pmsby, pmjdy).any { (it.trim().toIntOrNull() ?: 0) > MAX_PER_SCHEME }
    val anythingTyped = listOf(apy, pmjjby, pmsby, pmjdy).any { it.isNotBlank() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.sss_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = stringResource(R.string.action_back),
                        )
                    }
                },
            )
        },
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            message?.let { InlineNotice(it, Tone.SUCCESS) }

            if (overLimit) {
                InlineNotice(stringResource(R.string.sss_over_limit, MAX_PER_SCHEME), Tone.DANGER)
            }

            // Why the server would not take the last attempt. Without this the day sits
            // reading "waiting to be sent" for ever and the reason is buried in the outbox.
            if (rejected && !today?.syncMessage.isNullOrBlank()) {
                InlineNotice(today!!.syncMessage!!, Tone.DANGER)
            }

            if (locked) {
                InlineNotice(stringResource(R.string.sss_locked), Tone.WARNING)
            } else if (today?.reopened == true) {
                InlineNotice(stringResource(R.string.sss_reopened), Tone.INFO)
            }

            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatTile(
                    label = stringResource(R.string.sss_today_total),
                    value = typedTotal.toString(),
                    meta = Times.humanDate(Times.today()),
                    accent = StatusSuccess,
                    modifier = Modifier.weight(1f),
                )
                StatTile(
                    label = stringResource(R.string.sss_month_total),
                    value = monthTotal.toString(),
                    meta = stringResource(R.string.sss_month_meta),
                    modifier = Modifier.weight(1f),
                )
            }

            // The Admin's target and how the day stands against it. Read only in the
            // strongest sense: there is no request in this app that can carry a target, a
            // percentage or a gap, so none of these figures can be argued with from here.
            if (target.targetSet) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(
                            stringResource(R.string.sss_target_heading),
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                        )
                        Spacer(Modifier.height(6.dp))
                        DetailRow(stringResource(R.string.sss_target_today), target.dayTarget.toString())
                        DetailRow(stringResource(R.string.sss_achievement), achievedToday.toString())
                        DetailRow(
                            stringResource(R.string.sss_percent),
                            dayPercent?.let { "$it%" } ?: "—",
                        )
                        DetailRow(stringResource(R.string.sss_gap), dayGap.toString())

                        Spacer(Modifier.height(10.dp))
                        Text(
                            stringResource(R.string.sss_target_month_heading),
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                        )
                        Spacer(Modifier.height(6.dp))
                        DetailRow(stringResource(R.string.sss_target_month), target.monthTarget.toString())
                        DetailRow(stringResource(R.string.sss_achievement), monthTotal.toString())
                        DetailRow(
                            stringResource(R.string.sss_percent),
                            monthPercent?.let { "$it%" } ?: "—",
                        )
                        DetailRow(stringResource(R.string.sss_gap), monthGap.toString())

                        if (target.monthWorkingDays > 0) {
                            Spacer(Modifier.height(6.dp))
                            Text(
                                stringResource(R.string.sss_target_working_days, target.monthWorkingDays),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }

                        if (target.stale) {
                            Spacer(Modifier.height(4.dp))
                            Text(
                                stringResource(R.string.sss_target_stale),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            } else {
                InlineNotice(stringResource(R.string.sss_no_target), Tone.INFO)
            }

            if (today != null) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(
                            stringResource(R.string.sss_already_recorded),
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                        )
                        Spacer(Modifier.height(6.dp))
                        DetailRow(stringResource(R.string.sss_apy), today!!.apyCount.toString())
                        DetailRow(stringResource(R.string.sss_pmjjby), today!!.pmjjbyCount.toString())
                        DetailRow(stringResource(R.string.sss_pmsby), today!!.pmsbyCount.toString())
                        DetailRow(stringResource(R.string.sss_pmjdy), today!!.pmjdyCount.toString())
                        DetailRow(stringResource(R.string.sss_total), today!!.total.toString())
                        DetailRow(
                            stringResource(R.string.sss_status),
                            stringResource(
                                // Every state named. This used to be a two-way choice, which
                                // reported a refused day as "Sent" — the one state where the
                                // supervisor most needs to be told otherwise.
                                when (today!!.syncState) {
                                    SyncState.PENDING -> R.string.sss_state_queued
                                    SyncState.SYNCING -> R.string.sss_state_sending
                                    SyncState.FAILED -> R.string.sss_state_retrying
                                    SyncState.REJECTED -> R.string.sss_state_rejected
                                    else -> R.string.sss_state_sent
                                },
                            ),
                        )
                    }
                }
            }

            // A closed day composes no inputs at all, rather than showing disabled boxes.
            // The same choice the attendance screen makes once the day is finished: a field
            // you cannot use is a question about why you cannot use it.
            if (!locked) {
                Text(
                    stringResource(R.string.sss_intro),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                SchemeField(
                    value = apy,
                    onValueChange = { apy = it },
                    label = stringResource(R.string.sss_apy),
                    help = stringResource(R.string.sss_apy_full),
                )
                SchemeField(
                    value = pmjjby,
                    onValueChange = { pmjjby = it },
                    label = stringResource(R.string.sss_pmjjby),
                    help = stringResource(R.string.sss_pmjjby_full),
                )
                SchemeField(
                    value = pmsby,
                    onValueChange = { pmsby = it },
                    label = stringResource(R.string.sss_pmsby),
                    help = stringResource(R.string.sss_pmsby_full),
                )
                SchemeField(
                    value = pmjdy,
                    onValueChange = { pmjdy = it },
                    label = stringResource(R.string.sss_pmjdy),
                    help = stringResource(R.string.sss_pmjdy_full),
                )

                OutlinedTextField(
                    value = remarks,
                    onValueChange = { remarks = it },
                    label = { Text(stringResource(R.string.sss_remarks)) },
                    minLines = 2,
                    modifier = Modifier.fillMaxWidth(),
                )

                Button(
                    onClick = {
                        viewModel.submitSss(apy, pmjjby, pmsby, pmjdy, remarks) { note ->
                            message = note
                        }
                    },
                    // Nothing typed at all is not a submission, and a figure over the cap is
                    // a typing slip the server would reject outright — better to stop it
                    // here than to leave a rejected row sitting in the outbox.
                    enabled = anythingTyped && !overLimit,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(
                        stringResource(
                            if (today == null) {
                                R.string.sss_submit
                            } else {
                                R.string.sss_submit_correction
                            },
                        ),
                    )
                }

                Text(
                    stringResource(R.string.sss_queue_note),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/**
 * One scheme's count.
 *
 * Digits only, and no length beyond the cap: filtering as it is typed means a supervisor
 * cannot produce a figure the server will refuse.
 */
@Composable
private fun SchemeField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    help: String,
) {
    OutlinedTextField(
        value = value,
        onValueChange = { text -> onValueChange(text.filter { it.isDigit() }.take(3)) },
        label = { Text(label) },
        supportingText = { Text(help) },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** A zero reads as "nothing recorded", which is what an empty box already says. */
private fun Int.blankIfZero(): String = if (this == 0) "" else toString()
