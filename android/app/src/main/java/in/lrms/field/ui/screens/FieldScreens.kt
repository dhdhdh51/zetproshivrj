package `in`.lrms.field.ui.screens

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import `in`.lrms.field.R
import `in`.lrms.field.BuildConfig
import `in`.lrms.field.camera.PhotoFiles
import `in`.lrms.field.data.local.SyncState
import `in`.lrms.field.ui.AppViewModel
import `in`.lrms.field.ui.components.DetailRow
import `in`.lrms.field.ui.components.EmptyState
import `in`.lrms.field.ui.components.InlineNotice
import `in`.lrms.field.ui.components.LanguageSwitch
import `in`.lrms.field.ui.components.LoadingBlock
import `in`.lrms.field.ui.components.StatTile
import `in`.lrms.field.ui.components.StatusChip
import `in`.lrms.field.ui.components.Tone
import `in`.lrms.field.ui.theme.StatusSuccess
import `in`.lrms.field.util.Times
import kotlinx.coroutines.delay
import java.io.File

/* ---------------------------------------------------------------- Attendance */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AttendanceScreen(viewModel: AppViewModel) {
    val context = LocalContext.current
    val attendance by viewModel.attendance.collectAsStateWithLifecycle()
    val locationState by viewModel.location.collectAsStateWithLifecycle()
    val todaysVisits by viewModel.todaysVisits.collectAsStateWithLifecycle()
    var message by remember { mutableStateOf<String?>(null) }
    var selfie by remember { mutableStateOf<File?>(null) }
    var selfieTaken by remember { mutableStateOf(false) }

    val cameraLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        selfieTaken = success && selfie?.let { PhotoFiles.compress(it) } == true
    }

    Scaffold(topBar = { TopAppBar(title = { Text(stringResource(R.string.home_attendance)) }) }) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            message?.let { InlineNotice(it, Tone.SUCCESS) }
            locationState.error?.let { InlineNotice(it, Tone.DANGER) }

            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatTile(
                    label = "Check in",
                    value = attendance?.checkInAt?.let { Times.timeOnly(it) } ?: "—",
                    meta = Times.humanDate(Times.today()),
                    accent = StatusSuccess,
                    modifier = Modifier.weight(1f),
                )
                StatTile(
                    label = "Check out",
                    value = attendance?.checkOutAt?.let { Times.timeOnly(it) } ?: "—",
                    meta = attendance?.workingMinutes?.takeIf { it > 0 }?.let { "${it / 60}h ${it % 60}m" },
                    modifier = Modifier.weight(1f),
                )
            }

            StatTile(
                label = "Visits today",
                value = todaysVisits.count { it.syncState != SyncState.DRAFT }.toString(),
                meta = "recorded on this device",
                modifier = Modifier.fillMaxWidth(),
            )

            if (locationState.busy) {
                LoadingBlock("Getting a GPS fix…")
            }

            if (attendance?.checkInAt == null) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(stringResource(R.string.attendance_start_day), style = MaterialTheme.typography.titleSmall)
                        Text(
                            stringResource(R.string.attendance_check_in_note),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )

                        Spacer(Modifier.height(10.dp))

                        OutlinedButton(
                            onClick = {
                                val file = PhotoFiles.newFile(context, "selfie")
                                selfie = file
                                cameraLauncher.launch(PhotoFiles.uriFor(context, file))
                            },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(if (selfieTaken) "Selfie captured — retake" else "Take selfie")
                        }

                        Spacer(Modifier.height(8.dp))

                        Button(
                            onClick = {
                                viewModel.captureLocation { fix ->
                                    if (fix != null) {
                                        val encoded = selfie?.let { PhotoFiles.toBase64(it) }

                                        viewModel.checkIn(fix, encoded) { note -> message = note }
                                    }
                                }
                            },
                            enabled = !locationState.busy,
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(stringResource(R.string.home_check_in))
                        }
                    }
                }
            } else if (attendance?.checkOutAt == null) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(stringResource(R.string.attendance_end_day), style = MaterialTheme.typography.titleSmall)
                        Text(
                            stringResource(R.string.attendance_check_out_note),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )

                        Spacer(Modifier.height(10.dp))

                        Button(
                            onClick = {
                                viewModel.captureLocation { fix ->
                                    if (fix != null) {
                                        viewModel.checkOut(fix) { note -> message = note }
                                    }
                                }
                            },
                            enabled = !locationState.busy,
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(stringResource(R.string.attendance_check_out))
                        }
                    }
                }
            } else {
                InlineNotice(stringResource(R.string.attendance_done), Tone.SUCCESS)
            }
        }
    }
}

/* -------------------------------------------------------------- Daily report */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DailyReportScreen(viewModel: AppViewModel, onBack: () -> Unit) {
    val todaysVisits by viewModel.todaysVisits.collectAsStateWithLifecycle()
    var summary by remember { mutableStateOf("") }
    var lateReason by remember { mutableStateOf("") }
    var message by remember { mutableStateOf<String?>(null) }
    var secondsRemaining by remember { mutableLongStateOf(viewModel.deadlineState().secondsRemaining) }

    LaunchedEffect(Unit) {
        while (true) {
            secondsRemaining = viewModel.deadlineState().secondsRemaining
            delay(1000)
        }
    }

    val deadline = viewModel.deadlineState()
    val passed = deadline.locked || secondsRemaining <= 0L

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.home_daily_report)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.action_back))
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

            if (deadline.deadlineAt == null) {
                InlineNotice(stringResource(R.string.report_no_deadline), Tone.WARNING)
            } else if (passed) {
                InlineNotice(
                    stringResource(R.string.report_deadline_passed, Times.timeOnly(deadline.deadlineAt)),
                    Tone.DANGER,
                )
            } else {
                InlineNotice(
                    stringResource(
                        R.string.report_deadline_left,
                        Times.timeOnly(deadline.deadlineAt),
                        Times.countdown(secondsRemaining),
                    ),
                    Tone.INFO,
                )
            }

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.report_today_work), style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(6.dp))
                    DetailRow(stringResource(R.string.label_date), Times.humanDate(Times.today()))
                    DetailRow(
                            stringResource(R.string.report_visits_recorded),
                            todaysVisits.count { it.syncState != SyncState.DRAFT }.toString(),
                        )
                    DetailRow(
                            stringResource(R.string.report_in_progress),
                            todaysVisits.count { it.syncState == SyncState.DRAFT }.toString(),
                        )
                }
            }

            OutlinedTextField(
                value = summary,
                onValueChange = { summary = it },
                label = { Text(stringResource(R.string.report_summary)) },
                minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )

            if (passed) {
                OutlinedTextField(
                    value = lateReason,
                    onValueChange = { lateReason = it },
                    label = { Text(stringResource(R.string.report_late_reason)) },
                    minLines = 2,
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            Button(
                onClick = {
                    viewModel.submitDailyReport(summary, lateReason.ifBlank { null }) { note ->
                        message = note
                        summary = ""
                        lateReason = ""
                    }
                },
                enabled = summary.isNotBlank() && (!passed || lateReason.isNotBlank()),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(if (passed) R.string.report_submit_late else R.string.report_submit))
            }

            Text(
                stringResource(R.string.report_queue_note),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/* -------------------------------------------------------------------- Outbox */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OutboxScreen(viewModel: AppViewModel) {
    val sync by viewModel.sync.collectAsStateWithLifecycle()
    val outbox by viewModel.outbox.collectAsStateWithLifecycle()
    val visits by viewModel.todaysVisits.collectAsStateWithLifecycle()
    val pendingVisits by viewModel.pendingVisits.collectAsStateWithLifecycle()
    val pendingOutbox by viewModel.pendingOutbox.collectAsStateWithLifecycle()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.sync_title)) },
                actions = {
                    IconButton(onClick = { viewModel.syncNow() }, enabled = !sync.busy) {
                        Icon(Icons.Filled.Sync, contentDescription = stringResource(R.string.home_sync_now))
                    }
                },
            )
        },
    ) { padding ->
        LazyColumn(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                Spacer(Modifier.height(4.dp))

                if (sync.busy) {
                    LoadingBlock(stringResource(R.string.sync_synchronising))
                } else {
                    InlineNotice(
                        sync.message ?: stringResource(R.string.sync_auto_note),
                        if (sync.offline) Tone.WARNING else Tone.INFO,
                    )
                }
            }

            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    StatTile(
                        label = "Visits queued",
                        value = pendingVisits.toString(),
                        modifier = Modifier.weight(1f),
                    )
                    StatTile(
                        label = "Other records",
                        value = pendingOutbox.toString(),
                        modifier = Modifier.weight(1f),
                    )
                }
            }

            item {
                Button(
                    onClick = { viewModel.syncNow() },
                    enabled = !sync.busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.home_sync_now))
                }
            }

            if (visits.isNotEmpty()) {
                item {
                    Text(
                        "Visits",
                        style = MaterialTheme.typography.titleSmall,
                        modifier = Modifier.padding(top = 8.dp),
                    )
                }

                items(visits, key = { "visit-${it.uuid}" }) { visit ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(visit.borrowerName, fontWeight = FontWeight.SemiBold)
                                Text(
                                    "${visit.accountNumber} · ${Times.humanDate(visit.visitDate)}",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )

                                visit.syncMessage?.let {
                                    Text(
                                        it,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.error,
                                    )
                                }
                            }

                            StatusChip(
                                text = visit.syncState,
                                tone = when (visit.syncState) {
                                    SyncState.SYNCED -> Tone.SUCCESS
                                    SyncState.FAILED, SyncState.REJECTED -> Tone.DANGER
                                    SyncState.DRAFT -> Tone.WARNING
                                    else -> Tone.NEUTRAL
                                },
                            )
                        }
                    }
                }
            }

            if (outbox.isNotEmpty()) {
                item {
                    Text(
                        stringResource(R.string.sync_queued_entries),
                        style = MaterialTheme.typography.titleSmall,
                        modifier = Modifier.padding(top = 8.dp),
                    )
                }

                items(outbox, key = { "outbox-${it.uuid}" }) { item ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(item.label, style = MaterialTheme.typography.bodyMedium)
                                Text(
                                    item.type.replace('_', ' '),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )

                                item.syncMessage?.let {
                                    Text(
                                        it,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.error,
                                    )
                                }
                            }

                            StatusChip(
                                text = item.syncState,
                                tone = when (item.syncState) {
                                    SyncState.SYNCED -> Tone.SUCCESS
                                    SyncState.FAILED, SyncState.REJECTED -> Tone.DANGER
                                    else -> Tone.NEUTRAL
                                },
                            )
                        }
                    }
                }
            }

            if (visits.isEmpty() && outbox.isEmpty()) {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        EmptyState(
                            icon = Icons.Filled.CheckCircle,
                            title = stringResource(R.string.sync_nothing_waiting),
                            message = stringResource(R.string.sync_all_sent),
                        )
                    }
                }
            }

            item { Spacer(Modifier.height(20.dp)) }
        }
    }
}

/* ------------------------------------------------------------- Notifications */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationsScreen(viewModel: AppViewModel, onBack: () -> Unit) {
    val notifications by viewModel.notifications.collectAsStateWithLifecycle()
    val unread by viewModel.unreadNotifications.collectAsStateWithLifecycle()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.home_notifications)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.action_back))
                    }
                },
                actions = {
                    if (unread > 0) {
                        IconButton(onClick = { viewModel.markAllNotificationsRead() }) {
                            Icon(Icons.Filled.CheckCircle, contentDescription = stringResource(R.string.notifications_mark_all))
                        }
                    }
                },
            )
        },
    ) { padding ->
        if (notifications.isEmpty()) {
            EmptyState(
                icon = Icons.Filled.Notifications,
                title = stringResource(R.string.notifications_empty_title),
                message = stringResource(R.string.notifications_empty_message),
                modifier = Modifier.padding(padding),
            )

            return@Scaffold
        }

        LazyColumn(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item { Spacer(Modifier.height(4.dp)) }

            items(notifications, key = { it.id }) { notification ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                notification.title,
                                fontWeight = if (notification.isRead) FontWeight.Normal else FontWeight.SemiBold,
                                modifier = Modifier.weight(1f),
                            )

                            StatusChip(
                                text = notification.type,
                                tone = when (notification.type) {
                                    "alert" -> Tone.DANGER
                                    "warning", "deadline" -> Tone.WARNING
                                    "approval", "inspection" -> Tone.INFO
                                    else -> Tone.NEUTRAL
                                },
                            )
                        }

                        notification.body?.let {
                            Spacer(Modifier.height(4.dp))
                            Text(it, style = MaterialTheme.typography.bodySmall)
                        }

                        Spacer(Modifier.height(6.dp))

                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                Times.humanDateTime(notification.createdAt),
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.weight(1f),
                            )

                            if (!notification.isRead) {
                                OutlinedButton(onClick = { viewModel.markNotificationRead(notification.id) }) {
                                    Text(stringResource(R.string.notifications_mark_read))
                                }
                            }
                        }
                    }
                }
            }

            item { Spacer(Modifier.height(20.dp)) }
        }
    }
}

/* ------------------------------------------------------------------- Profile */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProfileScreen(viewModel: AppViewModel, onChangePassword: () -> Unit) {
    val sync by viewModel.sync.collectAsStateWithLifecycle()
    val pendingVisits by viewModel.pendingVisits.collectAsStateWithLifecycle()
    val pendingOutbox by viewModel.pendingOutbox.collectAsStateWithLifecycle()
    var confirmSignOut by remember { mutableStateOf(false) }

    Scaffold(topBar = { TopAppBar(title = { Text(stringResource(R.string.tab_profile)) }) }) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(viewModel.session.userName(), style = MaterialTheme.typography.titleMedium)
                    Text(
                        "BC Supervisor",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(8.dp))

                    DetailRow("BC code", viewModel.session.bcCode())
                    DetailRow("Branch", viewModel.session.branchName())
                    DetailRow("Username", viewModel.session.username())
                    DetailRow("Last sync", Times.humanDateTime(viewModel.session.lastSyncAt()))
                }
            }

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.profile_this_device), style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(6.dp))
                    DetailRow("Environment", BuildConfig.ENVIRONMENT)
                    DetailRow("App version", "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
                    DetailRow("Server", BuildConfig.API_BASE_URL)
                    DetailRow("Device id", viewModel.session.deviceUuid().take(13) + "…")
                    DetailRow("Queued records", (pendingVisits + pendingOutbox).toString())
                }
            }

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(stringResource(R.string.language), style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(8.dp))
                    // The phone option belongs here rather than on the sign-in
                    // screen: it is a considered setting, not a first-run choice.
                    LanguageSwitch(showSystemOption = true)
                }
            }

            OutlinedButton(onClick = onChangePassword, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.password_change))
            }

            OutlinedButton(
                onClick = { viewModel.syncNow() },
                enabled = !sync.busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.home_sync_now))
            }

            Button(
                onClick = { confirmSignOut = true },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.action_sign_out))
            }

            if (pendingVisits + pendingOutbox > 0) {
                InlineNotice(
                    "${pendingVisits + pendingOutbox} record(s) have not reached LRMS yet. Sync before " +
                        "signing out — signing out clears this device.",
                    Tone.WARNING,
                )
            }
        }
    }

    if (confirmSignOut) {
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { confirmSignOut = false },
            title = { Text(stringResource(R.string.sign_out_title)) },
            text = {
                Text(
                    if (pendingVisits + pendingOutbox > 0) {
                    if (pendingVisits + pendingOutbox > 0) {
                        pluralStringResource(
                            R.plurals.sign_out_queued_warning,
                            pendingVisits + pendingOutbox,
                            pendingVisits + pendingOutbox,
                        )
                    } else {
                        stringResource(R.string.sign_out_cached_warning)
                    },
                )
            },
            confirmButton = {
                androidx.compose.material3.TextButton(onClick = {
                    confirmSignOut = false
                    viewModel.signOut()
                }) {
                    Text(stringResource(R.string.action_sign_out))
                }
            },
            dismissButton = {
                androidx.compose.material3.TextButton(onClick = { confirmSignOut = false }) { Text(stringResource(R.string.action_cancel)) }
            },
        )
    }
}
