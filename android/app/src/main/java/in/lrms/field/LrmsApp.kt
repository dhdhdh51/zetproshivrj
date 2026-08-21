package `in`.lrms.field

import android.app.Application
import android.content.Context
import androidx.work.Configuration
import androidx.work.WorkManager
import `in`.lrms.field.data.local.AppDatabase
import `in`.lrms.field.data.prefs.SessionStore
import `in`.lrms.field.data.remote.ApiClient
import `in`.lrms.field.data.remote.ApiService
import `in`.lrms.field.data.repo.FieldRepository
import `in`.lrms.field.sync.SyncWorker
import `in`.lrms.field.util.AppLanguage

class LrmsApp : Application() {

    override fun onCreate() {
        super.onCreate()

        // Before anything reads a string. Notification channels, the sync worker
        // and the foreground service all resolve text without an Activity in
        // sight, and they must speak the language the supervisor chose.
        AppLanguage.apply(AppLanguage.fromTag(ServiceLocator.session(this).languageTag()))

        // WorkManager is initialised here (its default initialiser is removed in
        // the manifest) so it is always configured before the first sync request.
        WorkManager.initialize(
            this,
            Configuration.Builder()
                .setMinimumLoggingLevel(if (BuildConfig.DEBUG) android.util.Log.DEBUG else android.util.Log.WARN)
                .build(),
        )

        if (ServiceLocator.session(this).isSignedIn()) {
            SyncWorker.schedule(this)
        }
    }
}

/**
 * Manual dependency wiring.
 *
 * A dependency-injection framework would add an annotation processor and a build
 * step for four objects; this keeps the build simple and the graph obvious.
 */
object ServiceLocator {

    @Volatile
    private var sessionStore: SessionStore? = null

    @Volatile
    private var apiService: ApiService? = null

    @Volatile
    private var fieldRepository: FieldRepository? = null

    fun session(context: Context): SessionStore =
        sessionStore ?: synchronized(this) {
            sessionStore ?: SessionStore(context.applicationContext).also { sessionStore = it }
        }

    fun api(context: Context): ApiService =
        apiService ?: synchronized(this) {
            apiService ?: run {
                // Before the first request, so a refusal that carries no words of its own can
                // still be explained in the language the supervisor chose.
                ApiClient.attach(context)
                ApiClient.create(session(context)).also { apiService = it }
            }
        }

    fun repository(context: Context): FieldRepository =
        fieldRepository ?: synchronized(this) {
            fieldRepository ?: FieldRepository(
                context = context.applicationContext,
                db = AppDatabase.get(context),
                api = api(context),
                session = session(context),
            ).also { fieldRepository = it }
        }

    /** Called after sign-out, when the database has been deleted. */
    fun reset() {
        synchronized(this) {
            fieldRepository = null
        }
    }
}
