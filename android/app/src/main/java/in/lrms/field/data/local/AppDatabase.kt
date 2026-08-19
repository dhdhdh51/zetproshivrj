package `in`.lrms.field.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

@Database(
    entities = [
        AccountEntity::class,
        VisitEntity::class,
        PhotoEntity::class,
        OutboxEntity::class,
        NotificationEntity::class,
        FormFieldEntity::class,
        AttendanceEntity::class,
    ],
    version = 3,
    exportSchema = false,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun accounts(): AccountDao
    abstract fun visits(): VisitDao
    abstract fun photos(): PhotoDao
    abstract fun outbox(): OutboxDao
    abstract fun notifications(): NotificationDao
    abstract fun forms(): FormDao
    abstract fun attendance(): AttendanceDao

    companion object {
        @Volatile
        private var instance: AppDatabase? = null

        fun get(context: Context): AppDatabase =
            instance ?: synchronized(this) {
                instance ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "lrms-field.db",
                )
                    .addMigrations(MIGRATION_1_2, MIGRATION_2_3)
                    // Only for a database from an unknown build. Real schema
                    // changes get a migration above, because this database is not
                    // only a cache: it holds the outbox, and a supervisor who
                    // updates the app before a signal returns would otherwise lose
                    // a day of visits.
                    .fallbackToDestructiveMigration()
                    .build()
                    .also { instance = it }
            }

        /** Wipes local data on sign-out so a shared handset leaks nothing. */
        fun destroy(context: Context) {
            synchronized(this) {
                instance?.close()
                instance = null
                context.applicationContext.deleteDatabase("lrms-field.db")
            }
        }
    }
}

/**
 * form_fields gains visitType and a composite key, so the customer, KRM OTS and
 * CKCC OD-2 forms can live side by side.
 *
 * The table is recreated rather than altered: it is a pure cache, refilled on the
 * next sync. Everything else — visits, photographs, the outbox — is left alone,
 * which is the whole point of migrating instead of letting Room wipe the file.
 */
private val MIGRATION_1_2 = object : Migration(1, 2) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL("DROP TABLE IF EXISTS `form_fields`")
        db.execSQL(
            "CREATE TABLE IF NOT EXISTS `form_fields` (" +
                "`visitType` TEXT NOT NULL, " +
                "`fieldKey` TEXT NOT NULL, " +
                "`label` TEXT NOT NULL, " +
                "`type` TEXT NOT NULL, " +
                "`required` INTEGER NOT NULL, " +
                "`options` TEXT, " +
                "`placeholder` TEXT, " +
                "`help` TEXT, " +
                "`sortOrder` INTEGER NOT NULL, " +
                "`conditionField` TEXT, " +
                "`conditionOperator` TEXT, " +
                "`conditionValue` TEXT, " +
                "PRIMARY KEY(`visitType`, `fieldKey`))",
        )
        db.execSQL("CREATE INDEX IF NOT EXISTS `index_form_fields_visitType_sortOrder` ON `form_fields` (`visitType`, `sortOrder`)")
    }
}

/**
 * visits gains visitType.
 *
 * ALTER TABLE with a default, not a rebuild: this table holds visits a supervisor
 * has recorded and not yet synced, and recreating it would throw away field work
 * that exists nowhere else yet.
 */
private val MIGRATION_2_3 = object : Migration(2, 3) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `visitType` TEXT NOT NULL DEFAULT 'customer'")
    }
}
