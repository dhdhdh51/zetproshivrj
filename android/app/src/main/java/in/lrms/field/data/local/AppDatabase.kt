package `in`.lrms.field.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

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
    version = 1,
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
                    // Schema changes ship with the app; on-device data is a cache
                    // plus an outbox, and the outbox is drained before an update
                    // is installed in practice. A destructive migration is
                    // therefore acceptable and avoids a half-migrated database in
                    // the field.
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
