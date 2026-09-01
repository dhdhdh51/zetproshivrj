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
        SssEntity::class,
    ],
    version = 6,
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
    abstract fun sss(): SssDao

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
                    .addMigrations(
                        MIGRATION_1_2,
                        MIGRATION_2_3,
                        MIGRATION_3_4,
                        MIGRATION_4_5,
                        MIGRATION_5_6,
                    )
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

/**
 * The fix taken when the report is filed, alongside the one taken when the visit was
 * started. Added as nullable columns so an outbox holding a day of unsynced visits
 * survives the update — those rows keep their opening fix and simply have no closing
 * one, which is the truth about them.
 */
private val MIGRATION_3_4 = object : Migration(3, 4) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `submitLatitude` REAL")
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `submitLongitude` REAL")
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `submitAccuracy` REAL")
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `submitAddress` TEXT")
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `submitIsMock` INTEGER NOT NULL DEFAULT 0")
        db.execSQL("ALTER TABLE `visits` ADD COLUMN `submitCapturedAt` TEXT")
    }
}

/**
 * The Social Security Scheme table.
 *
 * CREATE TABLE, nothing else touched. Letting Room fall back to a destructive migration
 * here would delete the outbox along with the schema, and the outbox is the only copy of
 * a day's field work until a signal returns.
 */
private val MIGRATION_4_5 = object : Migration(4, 5) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL(
            "CREATE TABLE IF NOT EXISTS `sss_enrolments` (" +
                "`date` TEXT NOT NULL, " +
                "`uuid` TEXT NOT NULL, " +
                "`apyCount` INTEGER NOT NULL, " +
                "`pmjjbyCount` INTEGER NOT NULL, " +
                "`pmsbyCount` INTEGER NOT NULL, " +
                "`pmjdyCount` INTEGER NOT NULL, " +
                "`remarks` TEXT, " +
                "`syncState` TEXT NOT NULL, " +
                "PRIMARY KEY(`date`))",
        )
    }
}

/**
 * A day's figures now carry whether the server has closed them, and why it refused if it
 * did. Two ADD COLUMNs, nothing else touched.
 *
 * `status` defaults to submitted: every row already in this table was reported, and a
 * default of anything else would present a supervisor's own history back to them as
 * unfinished work.
 *
 * The Admin's target is deliberately not here. It belongs to a month rather than to a day,
 * and a day with nothing typed into it yet has no row at all — a supervisor opening the
 * screen before they have enrolled anybody still needs to see what is expected of them, so
 * the target is cached in SessionStore instead.
 */
private val MIGRATION_5_6 = object : Migration(5, 6) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL(
            "ALTER TABLE `sss_enrolments` ADD COLUMN `status` TEXT NOT NULL DEFAULT 'submitted'",
        )
        db.execSQL("ALTER TABLE `sss_enrolments` ADD COLUMN `syncMessage` TEXT")
    }
}
