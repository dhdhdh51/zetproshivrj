package `in`.lrms.field

import `in`.lrms.field.data.local.OutboxType
import `in`.lrms.field.util.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * The contract between this app's SSS screen and the server.
 *
 * These are string-matching tests, which is unusual, but the things being checked are
 * strings that cross a network boundary and are never compiled together. A typo in the
 * outbox type or a payload key does not fail to build: it fails on a supervisor's handset,
 * a day later, with the figures already gone from the screen.
 *
 * The type string is the worst of them. The server throws on an unknown type, and a throw
 * is treated as retryable, so a misspelled type would retry forever instead of failing
 * loudly — a queue that never drains and never complains.
 */
class SssTest {

    private fun source(path: String): String {
        val candidates = listOf(File(path), File("app/$path"), File("../app/$path"))

        val file = candidates.firstOrNull { it.isFile }
            ?: throw AssertionError(
                "Cannot find $path from ${File(".").absolutePath}. " +
                    "This test must never pass without reading the file it is about."
            )

        return file.readText()
    }

    /**
     * A file in the server half of the repository.
     *
     * Walks up rather than guessing a relative depth, because the working directory for
     * unit tests is the Gradle module and the server lives above the Android project.
     */
    private fun serverSource(path: String): String {
        var directory: File? = File(".").absoluteFile

        repeat(6) {
            val candidate = directory?.resolve(path)

            if (candidate != null && candidate.isFile) {
                return candidate.readText()
            }

            directory = directory?.parentFile
        }

        throw AssertionError(
            "Cannot find $path above ${File(".").absolutePath}. " +
                "This test must never pass without reading the file it is about."
        )
    }

    @Test
    fun `the outbox type is exactly what the server dispatches on`() {
        assertEquals("sss", OutboxType.SSS)

        val controller = serverSource("app/Controllers/Api/SyncController.php")

        assertTrue(
            "SyncController must have an arm for the type this app queues",
            controller.contains("'sss' =>"),
        )
    }

    @Test
    fun `the queued payload uses the keys the server reads`() {
        val repository = source("src/main/java/in/lrms/field/data/repo/FieldRepository.kt")
        val queueSss = repository.substringAfter("suspend fun queueSss(").substringBefore("\n    }")

        for (key in listOf(
            "enrolment_date",
            "apy_count",
            "pmjjby_count",
            "pmsby_count",
            "pmjdy_count",
            "remarks",
            "uuid",
        )) {
            assertTrue("queueSss must send \"$key\"", queueSss.contains("\"$key\""))
        }

        // British spelling, matching the server's column. "enrollment_date" would be
        // silently ignored and every day would be filed against today.
        assertTrue(
            "the date key is spelled the way the server spells it",
            !queueSss.contains("enrollment_date"),
        )
    }

    @Test
    fun `the day is the natural key, so a correction reuses its uuid`() {
        val repository = source("src/main/java/in/lrms/field/data/repo/FieldRepository.kt")
        val queueSss = repository.substringAfter("suspend fun queueSss(").substringBefore("\n    }")

        // Without this, correcting a figure while offline queues a second entry for the
        // same day. The server would rewrite the day rather than double it, but the outbox
        // would carry two rows and the second would report itself a duplicate.
        //
        // Checked as two facts rather than one exact expression: the day is looked up, and
        // its uuid is preferred to a new one. Pinning the whole line meant this failed when
        // the lookup was widened to read the day's status as well, which is a change of
        // shape and not of contract.
        assertTrue(
            "queueSss must look up the day already stored",
            queueSss.contains("db.sss().find(date)"),
        )
        assertTrue(
            "queueSss must reuse that day's uuid rather than minting a second one",
            queueSss.contains("?.uuid ?: newUuid()"),
        )
    }

    @Test
    fun `the counts survive the JSON round trip the outbox depends on`() {
        val payload = mapOf(
            "uuid" to "8a1f2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d",
            "enrolment_date" to "2026-08-18",
            "apy_count" to 4,
            "pmjjby_count" to 0,
            "pmsby_count" to 12,
            "pmjdy_count" to 7,
            "remarks" to "Camp at the panchayat office.",
        )

        val decoded = Json.decodeMap(Json.encodeAny(payload))

        assertEquals("2026-08-18", decoded["enrolment_date"])
        assertEquals("Camp at the panchayat office.", decoded["remarks"])

        // Moshi writes every number as a Double, so the figures come back as 4.0 rather
        // than 4. That is fine — PHP casts with (int) — but it is worth pinning, because
        // anything that reads these back on the device must not expect an Int.
        for (key in listOf("apy_count", "pmjjby_count", "pmsby_count", "pmjdy_count")) {
            val value = decoded[key]
            assertTrue("$key should decode to a number, got ${value?.javaClass}", value is Number)
        }

        assertEquals(4, (decoded["apy_count"] as Number).toInt())
        assertEquals(0, (decoded["pmjjby_count"] as Number).toInt())
        assertEquals(12, (decoded["pmsby_count"] as Number).toInt())
        assertEquals(7, (decoded["pmjdy_count"] as Number).toInt())
    }

    @Test
    fun `a blank count is not sent as a blank string`() {
        // The screen hands the ViewModel whatever was typed, including "". The server reads
        // a missing or unreadable figure as none, but sending "" where a number belongs
        // makes the payload lie about its own shape, so the ViewModel converts first.
        val viewModel = source("src/main/java/in/lrms/field/ui/AppViewModel.kt")
        val submitSss = viewModel.substringAfter("fun submitSss(").substringBefore("\n    }")

        assertTrue(
            "submitSss must turn typed text into integers before queueing",
            submitSss.contains("toIntOrNull() ?: 0"),
        )
    }

    @Test
    fun `the Room table is created by a migration, not by wiping the database`() {
        val database = source("src/main/java/in/lrms/field/data/local/AppDatabase.kt")

        assertTrue("the database version must be bumped for the new table", database.contains("version = 6"))
        assertTrue("a 4 to 5 migration must exist", database.contains("Migration(4, 5)"))
        assertTrue(
            "the migration must be registered, or Room falls back to destroying the outbox",
            database.contains("MIGRATION_4_5"),
        )
        assertTrue(
            "the migration must create the table rather than drop anything",
            database.substringAfter("Migration(4, 5)").contains("CREATE TABLE IF NOT EXISTS `sss_enrolments`"),
        )
    }

    @Test
    fun `the submit lock arrives by migration, without touching the outbox`() {
        val database = source("src/main/java/in/lrms/field/data/local/AppDatabase.kt")

        assertTrue("a 5 to 6 migration must exist", database.contains("Migration(5, 6)"))
        assertTrue(
            "the migration must be registered, or Room falls back to destroying the outbox",
            database.contains("MIGRATION_5_6"),
        )

        val migration = database.substringAfter("Migration(5, 6)")

        assertTrue(
            "the lock state must be added as a column",
            migration.contains("ADD COLUMN `status`"),
        )
        assertTrue(
            "the refusal reason must be added as a column, or the screen cannot explain itself",
            migration.contains("ADD COLUMN `syncMessage`"),
        )
        assertTrue(
            "days already in the table were reported, so they must default to submitted",
            migration.contains("DEFAULT 'submitted'"),
        )
        assertTrue(
            "a migration that drops anything would take the outbox with it",
            !migration.substringBefore("private val").contains("DROP"),
        )
    }

    @Test
    fun `a submitted day is closed to the app but a redelivery is not refused`() {
        val service = serverSource("app/Services/Sss.php")

        assertTrue(
            "changing a submitted day must be refused with a conflict, not quietly accepted",
            service.contains("HttpException(409"),
        )
        assertTrue(
            "the refusal is shown to the supervisor verbatim, so it must say what to do",
            service.contains("re-open"),
        )
        assertTrue(
            "the outbox delivers at least once, so identical figures arriving twice must pass",
            service.contains("sameFigures"),
        )
    }

    @Test
    fun `the app has no way to send a target, a percentage or a gap`() {
        val api = source("src/main/java/in/lrms/field/data/remote/ApiService.kt")

        // The target belongs to the BC Supervisor. It reaches the handset and stops there: if no
        // request can carry one, no supervisor can move the bar they are measured against,
        // and that is a stronger guarantee than a disabled input.
        assertTrue(
            "there must be no endpoint that sends a target from the app",
            !api.contains("target"),
        )
    }

    @Test
    fun `the SSS screen tells the supervisor when the server refused the day`() {
        val screen = source("src/main/java/in/lrms/field/ui/screens/SssScreen.kt")

        assertTrue(
            "a refused day must show why, not sit reading as sent",
            screen.contains("syncMessage"),
        )
        assertTrue("a locked day must say so", screen.contains("sss_locked"))
        assertTrue("a re-opened day must say so", screen.contains("sss_reopened"))
        assertTrue(
            "every sync state must be named, including rejected",
            screen.contains("sss_state_rejected"),
        )
    }

    @Test
    fun `every SSS string exists in Hindi as well as English`() {
        val english = source("src/main/res/values/strings.xml")
        val hindi = source("src/main/res/values-hi/strings.xml")

        val pattern = Regex("""name="((?:sss|home_sss|msg_sss)[a-z_]*)"""")
        val englishKeys = pattern.findAll(english).map { it.groupValues[1] }.toSortedSet()
        val hindiKeys = pattern.findAll(hindi).map { it.groupValues[1] }.toSortedSet()

        assertTrue("there should be SSS strings to check", englishKeys.isNotEmpty())
        assertEquals(
            "an SSS string missing from Hindi falls back to English without warning",
            englishKeys,
            hindiKeys,
        )
    }
}
