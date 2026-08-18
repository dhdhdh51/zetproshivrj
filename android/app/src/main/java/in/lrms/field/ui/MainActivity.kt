package `in`.lrms.field.ui

import android.Manifest
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Assignment
import androidx.compose.material.icons.filled.AccountBalance
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import `in`.lrms.field.ui.screens.AccountDetailScreen
import `in`.lrms.field.ui.screens.AccountsScreen
import `in`.lrms.field.ui.screens.AttendanceScreen
import `in`.lrms.field.ui.screens.ChangePasswordScreen
import `in`.lrms.field.ui.screens.DailyReportScreen
import `in`.lrms.field.ui.screens.HomeScreen
import `in`.lrms.field.ui.screens.LoginScreen
import `in`.lrms.field.ui.screens.NotificationsScreen
import `in`.lrms.field.ui.screens.OtpScreen
import `in`.lrms.field.ui.screens.OutboxScreen
import `in`.lrms.field.ui.screens.ProfileScreen
import `in`.lrms.field.ui.screens.VisitScreen
import `in`.lrms.field.ui.theme.LrmsTheme

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Field work is impossible without location, so the permission is asked
        // for up front rather than at the moment a visit starts.
        val permissionLauncher = registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions(),
        ) { }

        val permissions = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION,
            Manifest.permission.CAMERA,
        )

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            permissions += Manifest.permission.POST_NOTIFICATIONS
        }

        permissionLauncher.launch(permissions.toTypedArray())

        setContent {
            LrmsTheme {
                Surface {
                    LrmsApp()
                }
            }
        }
    }
}

private data class Tab(val route: String, val label: String, val icon: ImageVector)

private val tabs = listOf(
    Tab("home", "Home", Icons.Filled.Home),
    Tab("accounts", "Accounts", Icons.Filled.AccountBalance),
    Tab("attendance", "Attendance", Icons.AutoMirrored.Filled.Assignment),
    Tab("outbox", "Sync", Icons.Filled.Sync),
    Tab("profile", "Profile", Icons.Filled.Person),
)

@Composable
fun LrmsApp(viewModel: AppViewModel = viewModel()) {
    val auth by viewModel.auth.collectAsStateWithLifecycle()
    val navController = rememberNavController()
    val snackbar = remember { SnackbarHostState() }

    when {
        !auth.signedIn && auth.otpUserId != null -> OtpScreen(viewModel)
        !auth.signedIn -> LoginScreen(viewModel)
        auth.mustChangePassword -> ChangePasswordScreen(viewModel, forced = true, onDone = { })
        else -> SignedInApp(viewModel, navController, snackbar)
    }
}

@Composable
private fun SignedInApp(
    viewModel: AppViewModel,
    navController: NavHostController,
    snackbar: SnackbarHostState,
) {
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = backStackEntry?.destination
    val pendingSync by viewModel.pendingOutbox.collectAsStateWithLifecycle()
    val pendingVisits by viewModel.pendingVisits.collectAsStateWithLifecycle()
    val unread by viewModel.unreadNotifications.collectAsStateWithLifecycle()

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            NavigationBar {
                tabs.forEach { tab ->
                    val selected = currentDestination?.hierarchy?.any { it.route == tab.route } == true
                    val badge = when (tab.route) {
                        "outbox" -> pendingSync + pendingVisits
                        "home" -> unread
                        else -> 0
                    }

                    NavigationBarItem(
                        selected = selected,
                        onClick = {
                            navController.navigate(tab.route) {
                                popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                                launchSingleTop = true
                                restoreState = true
                            }
                        },
                        icon = {
                            if (badge > 0) {
                                BadgedBox(badge = { Badge { Text(badge.toString()) } }) {
                                    Icon(tab.icon, contentDescription = tab.label)
                                }
                            } else {
                                Icon(tab.icon, contentDescription = tab.label)
                            }
                        },
                        label = { Text(tab.label) },
                    )
                }
            }
        },
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = "home",
            modifier = Modifier.padding(padding),
        ) {
            composable("home") {
                HomeScreen(
                    viewModel = viewModel,
                    onOpenAccounts = { navController.navigate("accounts") },
                    onOpenAttendance = { navController.navigate("attendance") },
                    onOpenReport = { navController.navigate("daily-report") },
                    onOpenNotifications = { navController.navigate("notifications") },
                    onOpenVisit = { uuid -> navController.navigate("visit/$uuid") },
                )
            }

            composable("accounts") {
                AccountsScreen(
                    viewModel = viewModel,
                    onOpenAccount = { id -> navController.navigate("account/$id") },
                )
            }

            composable("account/{id}") { entry ->
                val id = entry.arguments?.getString("id")?.toLongOrNull() ?: 0L

                AccountDetailScreen(
                    viewModel = viewModel,
                    accountId = id,
                    onBack = { navController.popBackStack() },
                    onVisitStarted = { uuid ->
                        navController.navigate("visit/$uuid") {
                            popUpTo("accounts")
                        }
                    },
                )
            }

            composable("visit/{uuid}") { entry ->
                val uuid = entry.arguments?.getString("uuid") ?: ""

                VisitScreen(
                    viewModel = viewModel,
                    visitUuid = uuid,
                    onDone = {
                        navController.navigate("home") {
                            popUpTo("home") { inclusive = true }
                        }
                    },
                    onBack = { navController.popBackStack() },
                )
            }

            composable("attendance") { AttendanceScreen(viewModel) }

            composable("daily-report") {
                DailyReportScreen(viewModel, onBack = { navController.popBackStack() })
            }

            composable("outbox") { OutboxScreen(viewModel) }

            composable("notifications") {
                NotificationsScreen(viewModel, onBack = { navController.popBackStack() })
            }

            composable("profile") {
                ProfileScreen(
                    viewModel = viewModel,
                    onChangePassword = { navController.navigate("password") },
                )
            }

            composable("password") {
                ChangePasswordScreen(viewModel, forced = false, onDone = { navController.popBackStack() })
            }
        }
    }
}
