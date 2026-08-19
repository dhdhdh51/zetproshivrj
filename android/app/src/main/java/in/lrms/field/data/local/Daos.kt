package `in`.lrms.field.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

@Dao
interface AccountDao {
    @Upsert
    suspend fun upsertAll(accounts: List<AccountEntity>)

    @Query("DELETE FROM accounts WHERE id IN (:ids)")
    suspend fun deleteByIds(ids: List<Long>)

    @Query("DELETE FROM accounts")
    suspend fun clear()

    @Query("SELECT * FROM accounts WHERE id = :id")
    suspend fun find(id: Long): AccountEntity?

    @Query("SELECT COUNT(*) FROM accounts")
    suspend fun count(): Int

    /**
     * The account list the supervisor works from. `filter` is one of all,
     * pending, visited, ptp, krm_ots, ckcc_od2.
     */
    @Query(
        """
        SELECT * FROM accounts
         WHERE (:query = ''
                OR accountNumber LIKE '%' || :query || '%'
                OR borrowerName LIKE '%' || :query || '%'
                OR IFNULL(fatherName, '') LIKE '%' || :query || '%'
                OR IFNULL(mobile, '') LIKE '%' || :query || '%'
                OR IFNULL(village, '') LIKE '%' || :query || '%')
           AND (:filter = 'all'
                OR (:filter = 'pending' AND visitCount = 0)
                OR (:filter = 'visited' AND visitCount > 0)
                OR (:filter = 'ptp' AND recoveryStatus = 'ptp')
                OR (:filter = 'krm_ots' AND loanCategory = 'krm_ots')
                OR (:filter = 'ckcc_od2' AND loanCategory = 'ckcc_od2'))
         ORDER BY overdue DESC
         LIMIT 400
        """,
    )
    fun observe(query: String, filter: String): Flow<List<AccountEntity>>

    @Query("SELECT COUNT(*) FROM accounts WHERE visitCount = 0")
    fun observePendingCount(): Flow<Int>
}

@Dao
interface VisitDao {
    @Upsert
    suspend fun upsert(visit: VisitEntity)

    @Query("SELECT * FROM visits WHERE uuid = :uuid")
    suspend fun find(uuid: String): VisitEntity?

    /** The work stream of the account this visit belongs to. */
    @Query(
        "SELECT a.loanCategory FROM visits v JOIN accounts a ON a.id = v.accountId " +
            "WHERE v.uuid = :uuid LIMIT 1",
    )
    suspend fun loanCategoryForVisit(uuid: String): String?

    @Query("SELECT * FROM visits WHERE uuid = :uuid")
    fun observe(uuid: String): Flow<VisitEntity?>

    @Query("SELECT * FROM visits ORDER BY createdAt DESC LIMIT 100")
    fun observeRecent(): Flow<List<VisitEntity>>

    @Query("SELECT * FROM visits WHERE visitDate = :date ORDER BY createdAt DESC")
    fun observeForDate(date: String): Flow<List<VisitEntity>>

    @Query("SELECT * FROM visits WHERE syncState IN ('pending', 'failed') ORDER BY createdAt ASC LIMIT :limit")
    suspend fun pending(limit: Int = 25): List<VisitEntity>

    @Query("SELECT COUNT(*) FROM visits WHERE syncState IN ('pending', 'failed')")
    fun observePendingCount(): Flow<Int>

    @Query("SELECT COUNT(*) FROM visits WHERE visitDate = :date AND syncState <> 'draft'")
    fun observeCountForDate(date: String): Flow<Int>

    @Query("UPDATE visits SET syncState = :state, syncMessage = :message, attempts = attempts + 1, serverVisitId = :serverId, updatedAt = :now WHERE uuid = :uuid")
    suspend fun markSync(uuid: String, state: String, message: String?, serverId: Long?, now: Long)

    @Query("DELETE FROM visits WHERE syncState = 'synced' AND updatedAt < :before")
    suspend fun pruneSynced(before: Long)

    @Query("DELETE FROM visits WHERE uuid = :uuid")
    suspend fun delete(uuid: String)
}

@Dao
interface PhotoDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(photo: PhotoEntity): Long

    @Query("SELECT * FROM visit_photos WHERE visitUuid = :uuid ORDER BY localId ASC")
    fun observeForVisit(uuid: String): Flow<List<PhotoEntity>>

    @Query("SELECT * FROM visit_photos WHERE visitUuid = :uuid ORDER BY localId ASC")
    suspend fun forVisit(uuid: String): List<PhotoEntity>

    @Query("SELECT COUNT(*) FROM visit_photos WHERE visitUuid = :uuid")
    fun observeCountForVisit(uuid: String): Flow<Int>

    @Query("UPDATE visit_photos SET syncState = :state, serverPhotoId = :serverId WHERE localId = :localId")
    suspend fun markSync(localId: Long, state: String, serverId: Long?)

    @Query("DELETE FROM visit_photos WHERE localId = :localId")
    suspend fun delete(localId: Long)
}

@Dao
interface OutboxDao {
    @Upsert
    suspend fun upsert(item: OutboxEntity)

    @Query("SELECT * FROM outbox WHERE syncState IN ('pending', 'failed') ORDER BY createdAt ASC LIMIT :limit")
    suspend fun pending(limit: Int = 50): List<OutboxEntity>

    @Query("SELECT * FROM outbox ORDER BY createdAt DESC LIMIT 100")
    fun observeRecent(): Flow<List<OutboxEntity>>

    @Query("SELECT COUNT(*) FROM outbox WHERE syncState IN ('pending', 'failed')")
    fun observePendingCount(): Flow<Int>

    @Query("UPDATE outbox SET syncState = :state, syncMessage = :message, attempts = attempts + 1, updatedAt = :now WHERE uuid = :uuid")
    suspend fun markSync(uuid: String, state: String, message: String?, now: Long)

    @Query("SELECT * FROM outbox WHERE type = :type AND syncState IN ('pending','failed') LIMIT 1")
    suspend fun firstPendingOfType(type: String): OutboxEntity?

    @Query("DELETE FROM outbox WHERE syncState = 'synced' AND updatedAt < :before")
    suspend fun pruneSynced(before: Long)
}

@Dao
interface NotificationDao {
    @Upsert
    suspend fun upsertAll(items: List<NotificationEntity>)

    @Query("SELECT * FROM notifications ORDER BY createdAt DESC LIMIT 60")
    fun observe(): Flow<List<NotificationEntity>>

    @Query("SELECT COUNT(*) FROM notifications WHERE isRead = 0")
    fun observeUnreadCount(): Flow<Int>

    @Query("UPDATE notifications SET isRead = 1 WHERE id = :id")
    suspend fun markRead(id: Long)

    @Query("UPDATE notifications SET isRead = 1")
    suspend fun markAllRead()
}

@Dao
interface FormDao {
    @Query("DELETE FROM form_fields")
    suspend fun clear()

    @Upsert
    suspend fun upsertAll(fields: List<FormFieldEntity>)

    @Query("SELECT * FROM form_fields ORDER BY sortOrder ASC")
    suspend fun all(): List<FormFieldEntity>

    @Query("SELECT * FROM form_fields WHERE visitType = :visitType ORDER BY sortOrder ASC")
    suspend fun forType(visitType: String): List<FormFieldEntity>

    @Query("SELECT * FROM form_fields WHERE visitType = :visitType ORDER BY sortOrder ASC")
    fun observeForType(visitType: String): Flow<List<FormFieldEntity>>

    @Query("SELECT COUNT(*) FROM form_fields WHERE visitType = :visitType")
    suspend fun countForType(visitType: String): Int

    @Query("SELECT * FROM form_fields ORDER BY sortOrder ASC")
    fun observe(): Flow<List<FormFieldEntity>>

    @Query("SELECT COUNT(*) FROM form_fields")
    suspend fun count(): Int
}

@Dao
interface AttendanceDao {
    @Upsert
    suspend fun upsert(attendance: AttendanceEntity)

    @Query("SELECT * FROM attendance WHERE date = :date")
    fun observe(date: String): Flow<AttendanceEntity?>

    @Query("SELECT * FROM attendance WHERE date = :date")
    suspend fun find(date: String): AttendanceEntity?
}
