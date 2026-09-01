package `in`.lrms.field.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import `in`.lrms.field.ServiceLocator
import java.util.concurrent.TimeUnit

/**
 * Drains the outbox whenever the device has a connection.
 *
 * WorkManager is used rather than a foreground service so synchronisation
 * survives the app being closed, the device rebooting and Doze — a supervisor who
 * finishes their round in a village with no signal gets their work uploaded the
 * moment they reach one, without having to remember to open the app.
 */
class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val repository = ServiceLocator.repository(applicationContext)

        if (!repository.store.isSignedIn()) {
            return Result.success()
        }

        val report = repository.sync()

        return when {
            report.unauthenticated -> {
                // Nothing to gain from retrying; the UI will ask for sign-in.
                Result.success()
            }

            report.offline -> Result.retry()

            report.failed > 0 && report.pushed == 0 -> Result.retry()

            else -> Result.success()
        }
    }

    companion object {
        private const val PERIODIC = "lrms-sync-periodic"
        private const val ONE_OFF = "lrms-sync-now"

        private val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        /** Background sync every 15 minutes — WorkManager's minimum interval. */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                PERIODIC,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }

        /** "Sync now", and also fired right after anything is queued. */
        fun syncNow(context: Context) {
            val request = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS)
                .build()

            WorkManager.getInstance(context).enqueueUniqueWork(
                ONE_OFF,
                ExistingWorkPolicy.REPLACE,
                request,
            )
        }

        fun cancelAll(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(PERIODIC)
            WorkManager.getInstance(context).cancelUniqueWork(ONE_OFF)
        }
    }
}
