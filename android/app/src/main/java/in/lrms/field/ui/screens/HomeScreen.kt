package `in`.lrms.field.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Assignment
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.R
import `in`.lrms.field.data.local.SyncState
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.EmptyState
import `in`.lrms.field.ui.components.InlineNotice
import `in`.lrms.field.ui.components.StatTile
import `in`.lrms.field.ui.components.StatusChip
import `in`.lrms.field.ui.components.Tone
import `in`.lrms.field.ui.theme.StatusDanger
import `in`.lrms.field.ui.theme.StatusSuccess
import `in`.lrms.field.ui.theme.StatusWarning
import `in`.lrms.field.util.Times
import kotlinx.coroutines.delay

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    viewModel: AppViewModel,
    onOpenAccounts: () -> Unit,
    onOpenAttendance: () -> Unit,
    onOpenReport: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenVisit: (String) -> Unit,
) {
    val sync by viewModel.sync.collectAsStateWithLifecycle()
    val pendingVisits by viewModel.pendingVisits.collectAsStateWithLifecycle()
    val pendingOutbox by viewModel.pendingOutbox.collectAsStateWithLifecycle()
    val pendingAccounts by viewModel.pendingAccounts.collectAsStateWithLifecycle()
    val todaysVisits by viewModel.todaysVisits.collectAsStateWithLifecycle()
    val attendance by viewModel.attendance.collectAsStateWithLifecycle()
    val unread by viewModel.unreadNotifications.collectAsStateWithLifecycle()

    // The countdown is driven by the server deadline captured at the last sync and
    // the monotonic clock, so changing the device time does not buy extra minutes.
    var secondsRemaining by remember { mutableLongStateOf(viewModel.deadlineState().secondsRemaining) }

    LaunchedEffect(Unit) {
        while (true) {
            secondsRemaining = viewModel.deadlineState().secondsRemaining
            delay(1000)
        }
    }

    val deadline = viewModel.deadlineState()

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(viewModel.session.userName().ifBlank { stringResource(R.string.home_role) })
                        Text(
                            listOf(viewModel.session.bcCode(), viewModel.session.branchName())
                                .filter { it.isNotBlank() }
                                .joinToString(" · "),
                            style = MaterialTheme.typography.labelSmall,
                        )
                    }
                },
                actions = {
                    IconButton(onClick = onOpenNotifications) {
                        Icon(Icons.Filled.Notifications, contentDescription = stringResource(R.string.home_notifications))
                    }
                    IconButton(onClick = { viewModel.syncNow() }, enabled = !sync.busy) {
                        Icon(Icons.Filled.Sync, contentDescription = stringResource(R.string.home_sync_now))
                    }
                },
            )
        },
    ) { padding ->
        LazyColumn(
            Modifier
                .fillMaxWidth()
                .padding(padding)
                .padding(horizontal = 14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            item {
                Spacer(Modifier.height(4.dp))

                // Deadline banner: the single most operationally important thing.
                if (deadline.deadlineAt == null) {
                    InlineNotice(stringResource(R.string.home_deadline_unknown), Tone.INFO)
                } else if (deadline.locked || secondsRemaining <= 0L) {
                    InlineNotice(
                        stringResource(R.string.home_deadline_passed, Times.timeOnly(deadline.deadlineAt)) +
                            " " + stringResource(R.string.home_late_needs_approval),
                        Tone.DANGER,
                    )
                } else {
                    InlineNotice(
                        stringResource(
                            R.string.home_deadline_countdown,
                            Times.timeOnly(deadline.deadlineAt),
                            Times.countdown(secondsRemaining),
                        ),
                        if (secondsRemaining < 3600) Tone.WARNING else Tone.INFO,
                    )
                }
            }

            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    StatTile(
                        label = stringResource(R.string.home_visits_today),
                        value = todaysVisits.count { it.syncState != SyncState.DRAFT }.toString(),
                        meta = if (todaysVisits.any { it.syncState == SyncState.DRAFT }) {
                            "${todaysVisits.count { it.syncState == SyncState.DRAFT }} in progress"
                        } else {
                            null
                        },
                        accent = StatusSuccess,
                        modifier = Modifier.weight(1f),
                    )
                    StatTile(
                        label = stringResource(R.string.home_not_visited),
                        value = pendingAccounts.toString(),
                        meta = "allocated accounts",
                        accent = StatusWarning,
                        modifier = Modifier.weight(1f),
                    )
                }
            }

            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    StatTile(
                        label = stringResource(R.string.home_waiting_to_sync),
                        value = (pendingVisits + pendingOutbox).toString(),
                        meta = if (sync.offline) "offline — will retry" else "records queued",
                        accent = if (pendingVisits + pendingOutbox > 0) StatusWarning else StatusSuccess,
                        modifier = Modifier.weight(1f),
                    )
                    StatTile(
                        label = stringResource(R.string.home_attendance),
                        value = attendance?.checkInAt?.let { Times.timeOnly(it) } ?: "—",
                        meta = attendance?.checkOutAt?.let { "out ${Times.timeOnly(it)}" } ?: "not checked out",
                        accent = if (attendance?.checkInAt != null) StatusSuccess else StatusDanger,
                        modifier = Modifier.weight(1f),
                    )
                }
            }

            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(stringResource(R.string.home_quick_actions), style = MaterialTheme.typography.titleSmall)
                        Spacer(Modifier.height(10.dp))

                        Button(onClick = onOpenAccounts, modifier = Modifier.fillMaxWidth()) {
                            Text(stringResource(R.string.home_start_visit))
                        }

                        Spacer(Modifier.height(8.dp))

                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = onOpenAttendance, modifier = Modifier.weight(1f)) {
                                Text(if (attendance?.checkInAt == null) stringResource(R.string.home_check_in) else stringResource(R.string.home_attendance))
                            }
                            OutlinedButton(onClick = onOpenReport, modifier = Modifier.weight(1f)) {
                                Text(stringResource(R.string.home_daily_report))
                            }
                        }
                    }
                }
            }

            if (unread > 0) {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Filled.Notifications, contentDescription = null)
                            Spacer(Modifier.height(8.dp))
                            Column(Modifier.weight(1f).padding(start = 10.dp)) {
                                Text(
                                    pluralStringResource(R.plurals.notifications_unread, unread, unread),
                                    style = MaterialTheme.typography.titleSmall,
                                )
                                Text(
                                    stringResource(R.string.home_targets_hint),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            OutlinedButton(onClick = onOpenNotifications) { Text(stringResource(R.string.action_open)) }
                        }
                    }
                }
            }

            item {
                Text(
                    stringResource(R.string.home_todays_visits),
                    style = MaterialTheme.typography.titleSmall,
                    modifier = Modifier.padding(top = 6.dp),
                )
            }

            if (todaysVisits.isEmpty()) {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        EmptyState(
                            icon = Icons.AutoMirrored.Filled.Assignment,
                            title = stringResource(R.string.home_no_visits_today),
                            message = stringResource(R.string.home_start_hint),
                        )
                    }
                }
            } else {
                items(todaysVisits, key = { it.uuid }) { visit ->
                    Card(
                        Modifier.fillMaxWidth(),
                    ) {
                        Column(Modifier.padding(14.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(visit.borrowerName, fontWeight = FontWeight.SemiBold)
                                    Text(
                                        visit.accountNumber,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }

                                StatusChip(
                                    text = when (visit.syncState) {
                                        SyncState.DRAFT -> "in progress"
                                        SyncState.PENDING -> "queued"
                                        SyncState.SYNCING -> "sending"
                                        SyncState.SYNCED -> "sent"
                                        SyncState.REJECTED -> "rejected"
                                        else -> "retrying"
                                    },
                                    tone = when (visit.syncState) {
                                        SyncState.SYNCED -> Tone.SUCCESS
                                        SyncState.REJECTED, SyncState.FAILED -> Tone.DANGER
                                        SyncState.DRAFT -> Tone.WARNING
                                        else -> Tone.NEUTRAL
                                    },
                                )
                            }

                            if (visit.syncMessage != null) {
                                Spacer(Modifier.height(6.dp))
                                Text(
                                    visit.syncMessage,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.error,
                                )
                            }

                            if (visit.syncState == SyncState.DRAFT) {
                                Spacer(Modifier.height(8.dp))
                                Button(onClick = { onOpenVisit(visit.uuid) }, modifier = Modifier.fillMaxWidth()) {
                                    Text(stringResource(R.string.home_continue_visit))
                                }
                            }
                        }
                    }
                }
            }

            item {
                sync.message?.let {
                    Text(
                        it,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.outline,
                        modifier = Modifier.padding(vertical = 10.dp),
                    )
                }
            }
        }
    }
}
