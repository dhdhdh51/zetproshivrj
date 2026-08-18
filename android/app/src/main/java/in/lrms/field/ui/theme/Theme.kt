package `in`.lrms.field.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

private val Navy = Color(0xFF1E3A5F)
private val NavyDark = Color(0xFF0B1220)
private val NavyLight = Color(0xFFE8EEF7)
private val Success = Color(0xFF15803D)
private val Warning = Color(0xFFA16207)
private val Danger = Color(0xFFB91C1C)
private val Canvas = Color(0xFFF4F6FA)

val StatusSuccess = Success
val StatusWarning = Warning
val StatusDanger = Danger

private val LightColors = lightColorScheme(
    primary = Navy,
    onPrimary = Color.White,
    primaryContainer = NavyLight,
    onPrimaryContainer = Navy,
    secondary = Color(0xFF24507F),
    onSecondary = Color.White,
    background = Canvas,
    onBackground = Color(0xFF0F172A),
    surface = Color.White,
    onSurface = Color(0xFF0F172A),
    surfaceVariant = Color(0xFFEEF1F6),
    onSurfaceVariant = Color(0xFF475569),
    error = Danger,
    onError = Color.White,
    outline = Color(0xFFCBD5E1),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF8FB4E0),
    onPrimary = NavyDark,
    primaryContainer = Color(0xFF1B2B45),
    onPrimaryContainer = Color(0xFFD6E4F7),
    background = NavyDark,
    onBackground = Color(0xFFE2E8F0),
    surface = Color(0xFF151F35),
    onSurface = Color(0xFFE2E8F0),
    surfaceVariant = Color(0xFF1E2A42),
    onSurfaceVariant = Color(0xFFA8B7CC),
    error = Color(0xFFF19292),
    onError = NavyDark,
    outline = Color(0xFF33415B),
)

private val LrmsTypography = Typography(
    titleLarge = TextStyle(fontSize = 20.sp, fontWeight = FontWeight.SemiBold),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold),
    titleSmall = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 15.sp),
    bodyMedium = TextStyle(fontSize = 14.sp),
    bodySmall = TextStyle(fontSize = 12.5.sp),
    labelLarge = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium),
    labelSmall = TextStyle(fontSize = 11.sp, fontWeight = FontWeight.Medium),
)

@Composable
fun LrmsTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = LrmsTypography,
        content = content,
    )
}
