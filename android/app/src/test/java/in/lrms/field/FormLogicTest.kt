package `in`.lrms.field

import `in`.lrms.field.data.local.FormFieldEntity
import `in`.lrms.field.util.FormLogic
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The client-side form rules must agree with the server, otherwise a supervisor
 * fills in a form the server then rejects — after they have left the customer.
 */
class FormLogicTest {

    private fun field(
        key: String,
        label: String = key,
        type: String = "text",
        required: Boolean = false,
        conditionField: String? = null,
        conditionOperator: String? = null,
        conditionValue: String? = null,
    ) = FormFieldEntity(
        fieldKey = key,
        label = label,
        type = type,
        required = required,
        options = null,
        placeholder = null,
        help = null,
        sortOrder = 0,
        conditionField = conditionField,
        conditionOperator = conditionOperator,
        conditionValue = conditionValue,
    )

    @Test
    fun `unconditional fields are always visible`() {
        assertTrue(FormLogic.isVisible(field("remarks"), emptyMap()))
    }

    @Test
    fun `equals condition matches case insensitively`() {
        val promise = field(
            key = "promise_amount",
            conditionField = "customer_available",
            conditionOperator = "equals",
            conditionValue = "Yes",
        )

        assertTrue(FormLogic.isVisible(promise, mapOf("customer_available" to "yes")))
        assertFalse(FormLogic.isVisible(promise, mapOf("customer_available" to "No")))
        assertFalse(FormLogic.isVisible(promise, emptyMap()))
    }

    @Test
    fun `not_equals shows the field until the parent matches`() {
        val currentAddress = field(
            key = "current_address",
            conditionField = "customer_available",
            conditionOperator = "not_equals",
            conditionValue = "Yes",
        )

        assertTrue(FormLogic.isVisible(currentAddress, mapOf("customer_available" to "No")))
        assertFalse(FormLogic.isVisible(currentAddress, mapOf("customer_available" to "Yes")))
    }

    @Test
    fun `in operator accepts any listed value`() {
        val condition = field(
            key = "reason",
            conditionField = "visit_status",
            conditionOperator = "in",
            conditionValue = "House locked, Customer not available",
        )

        assertTrue(FormLogic.isVisible(condition, mapOf("visit_status" to "House locked")))
        assertTrue(FormLogic.isVisible(condition, mapOf("visit_status" to "customer not available")))
        assertFalse(FormLogic.isVisible(condition, mapOf("visit_status" to "Customer met")))
    }

    @Test
    fun `filled and empty operators react to any answer`() {
        val filled = field(key = "a", conditionField = "parent", conditionOperator = "filled")
        val empty = field(key = "b", conditionField = "parent", conditionOperator = "empty")

        assertTrue(FormLogic.isVisible(filled, mapOf("parent" to "anything")))
        assertFalse(FormLogic.isVisible(filled, mapOf("parent" to "")))
        assertTrue(FormLogic.isVisible(empty, mapOf("parent" to "")))
        assertFalse(FormLogic.isVisible(empty, mapOf("parent" to "anything")))
    }

    @Test
    fun `hidden required fields are not demanded`() {
        val fields = listOf(
            field("customer_available", type = "yes_no", required = true),
            field(
                key = "promise_amount",
                label = "Promise amount",
                type = "decimal",
                required = true,
                conditionField = "customer_available",
                conditionOperator = "equals",
                conditionValue = "Yes",
            ),
        )

        // Customer was not available, so the promise field is hidden and not required.
        val answers = mapOf("customer_available" to "No")

        assertNull(FormLogic.firstMissingRequired(fields, answers))
    }

    @Test
    fun `visible required fields are demanded`() {
        val fields = listOf(
            field("customer_available", type = "yes_no", required = true),
            field(
                key = "promise_amount",
                label = "Promise amount",
                type = "decimal",
                required = true,
                conditionField = "customer_available",
                conditionOperator = "equals",
                conditionValue = "Yes",
            ),
        )

        val missing = FormLogic.firstMissingRequired(fields, mapOf("customer_available" to "Yes"))

        assertEquals("Promise amount", missing?.label)
    }

    @Test
    fun `captured field types are never demanded as typed answers`() {
        val fields = listOf(
            field("photo", type = "photo", required = true),
            field("gps", type = "gps", required = true),
            field("signature", type = "signature", required = true),
            field("account_context", type = "section", required = true),
        )

        assertNull(FormLogic.firstMissingRequired(fields, emptyMap()))
    }

    @Test
    fun `a visit cannot be submitted without a photograph`() {
        val error = FormLogic.validateVisit(
            photoCount = 0,
            minPhotos = 1,
            remarks = "Met the borrower",
            fields = emptyList(),
            answers = emptyMap(),
        )

        assertEquals("Take at least one photograph before submitting.", error)
    }

    @Test
    fun `a visit cannot be submitted without remarks`() {
        val error = FormLogic.validateVisit(
            photoCount = 2,
            minPhotos = 1,
            remarks = "   ",
            fields = emptyList(),
            answers = emptyMap(),
        )

        assertEquals("Remarks are required.", error)
    }

    @Test
    fun `a complete visit passes validation`() {
        val fields = listOf(field("customer_available", type = "yes_no", required = true))

        val error = FormLogic.validateVisit(
            photoCount = 1,
            minPhotos = 1,
            remarks = "Borrower met at home.",
            fields = fields,
            answers = mapOf("customer_available" to "Yes"),
        )

        assertNull(error)
    }

    @Test
    fun `the minimum photograph count from the server is respected`() {
        val error = FormLogic.validateVisit(
            photoCount = 1,
            minPhotos = 3,
            remarks = "Done",
            fields = emptyList(),
            answers = emptyMap(),
        )

        assertEquals("Take at least 3 photographs before submitting (1 taken).", error)
    }
}


/**
 * The `contains` operator, used by the KRM OTS and CKCC OD-2 field visit
 * verification reports so "Other document" appears only when "Other" is one of
 * the ticked entries in the documents checklist.
 *
 * A checkbox answer arrives as a comma joined list, so these cases mirror
 * App\Services\Forms::isVisible exactly — including that a substring must not
 * count as a match.
 */
class FormLogicContainsTest {

    private val documentsOther = FormFieldEntity(
        fieldKey = "documents_other",
        label = "Other document",
        type = "text",
        required = false,
        options = null,
        placeholder = null,
        help = null,
        sortOrder = 0,
        conditionField = "documents_verified",
        conditionOperator = "contains",
        conditionValue = "Other",
    )

    private fun visibleWhen(answer: String) =
        FormLogic.isVisible(documentsOther, mapOf("documents_verified" to answer))

    @Test
    fun `visible when the ticked list includes the expected choice`() {
        assertTrue(visibleWhen("Aadhaar Card,Other"))
        assertTrue(visibleWhen("Other"))
    }

    @Test
    fun `hidden when the choice was not ticked`() {
        assertFalse(visibleWhen("Aadhaar Card,Passbook"))
        assertFalse(visibleWhen(""))
    }

    @Test
    fun `a substring is not a match`() {
        // "Other Land Record" contains the letters of "Other" but is a different
        // choice, so the dependent field must stay hidden.
        assertFalse(visibleWhen("Other Land Record"))
    }

    @Test
    fun `matching tolerates case and spacing`() {
        assertTrue(visibleWhen("aadhaar card, other"))
    }
}
