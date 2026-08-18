package `in`.lrms.field

import `in`.lrms.field.util.Json
import `in`.lrms.field.util.Times
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Money and countdown formatting, and the JSON round trip the offline queue
 * depends on. Bank staff read Indian-grouped rupees, and a queued visit that
 * cannot be decoded again is a lost visit.
 */
class FormattingTest {

    @Test
    fun `amounts use Indian digit grouping`() {
        assertEquals("₹1,500.00", Times.money(1500.0))
        assertEquals("₹12,345.67", Times.money(12345.67))
        assertEquals("₹1,23,456.78", Times.money(123456.78))
        assertEquals("₹12,34,567.00", Times.money(1234567.0))
        assertEquals("₹0.00", Times.money(0.0))
    }

    @Test
    fun `negative amounts keep their sign`() {
        assertEquals("-₹2,500.00", Times.money(-2500.0))
    }

    @Test
    fun `large amounts are shown compactly on tiles`() {
        assertEquals("₹1.50 L", Times.compactMoney(150000.0))
        assertEquals("₹1.20 Cr", Times.compactMoney(12000000.0))
        assertEquals("₹8,200.00", Times.compactMoney(8200.0))
    }

    @Test
    fun `countdown reads naturally`() {
        assertEquals("Deadline passed", Times.countdown(0))
        assertEquals("Deadline passed", Times.countdown(-10))
        assertEquals("45s left", Times.countdown(45))
        assertEquals("5m 30s left", Times.countdown(330))
        assertEquals("2h 05m left", Times.countdown(7500))
    }

    @Test
    fun `form answers survive a JSON round trip`() {
        val answers = mapOf(
            "visit_status" to "Customer met",
            "customer_available" to "Yes",
            "remarks" to "Borrower met at home; promised ₹5,000 by month end.",
        )

        val decoded = Json.decodeStringMap(Json.encodeStringMap(answers))

        assertEquals(answers, decoded)
    }

    @Test
    fun `queued payloads survive a JSON round trip`() {
        val payload = mapOf(
            "loan_account_id" to 42.0,
            "amount" to 1500.5,
            "payment_mode" to "Cash",
            "receipt_number" to null,
            "gps" to mapOf("latitude" to 25.5389, "longitude" to 87.5719),
        )

        val decoded = Json.decodeMap(Json.encodeAny(payload))

        assertEquals(1500.5, decoded["amount"])
        assertEquals("Cash", decoded["payment_mode"])
        assertTrue(decoded["gps"] is Map<*, *>)
    }

    @Test
    fun `malformed JSON decodes to an empty map instead of crashing`() {
        assertTrue(Json.decodeMap("{not json").isEmpty())
        assertTrue(Json.decodeMap(null).isEmpty())
        assertTrue(Json.decodeStringMap("").isEmpty())
    }

    @Test
    fun `dates are formatted the way the server expects`() {
        val today = Times.today()

        assertTrue("expected yyyy-MM-dd, got $today", today.matches(Regex("\\d{4}-\\d{2}-\\d{2}")))
        assertTrue(Times.nowServerFormat().matches(Regex("\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}")))
    }

    @Test
    fun `missing dates render as a dash rather than an error`() {
        assertEquals("—", Times.humanDate(null))
        assertEquals("—", Times.humanDateTime(""))
        assertEquals("—", Times.timeOnly(null))
    }
}
