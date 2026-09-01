package `in`.lrms.field.util

import `in`.lrms.field.data.local.FormFieldEntity

/**
 * Client-side rules for the configurable visit form.
 *
 * This mirrors App\Services\Forms on the server (conditional visibility and
 * required-field checking). Duplicating it is deliberate: the supervisor must be
 * told about a missing answer while they are still standing in front of the
 * customer, not hours later when the queued visit is finally rejected. The server
 * remains the authority.
 */
object FormLogic {

    /** Field types whose value is captured by the platform, not typed in. */
    val capturedTypes = setOf("section", "photo", "gps", "signature")

    fun isVisible(field: FormFieldEntity, answers: Map<String, String>): Boolean =
        isVisible(field.conditionField, field.conditionOperator, field.conditionValue, answers)

    fun isVisible(
        conditionField: String?,
        operator: String?,
        expectedValue: String?,
        answers: Map<String, String>,
    ): Boolean {
        val parent = conditionField?.takeIf { it.isNotBlank() } ?: return true
        val expected = expectedValue?.trim()?.lowercase().orEmpty()
        val actual = answers[parent]?.trim()?.lowercase().orEmpty()

        return when (operator) {
            "not_equals" -> actual != expected
            "in" -> expected.split(',').map { it.trim() }.contains(actual)
            // For a checkbox parent, whose answer is a comma joined list: true
            // when the expected choice is one of the ticked values. Compared per
            // item, so "Other" does not match "Other Land Record".
            "contains" -> actual.split(',').map { it.trim() }.contains(expected)
            "filled" -> actual.isNotEmpty()
            "empty" -> actual.isEmpty()
            else -> actual == expected
        }
    }

    /**
     * The first required field that is visible and unanswered, or null when the
     * form is complete.
     */
    fun firstMissingRequired(fields: List<FormFieldEntity>, answers: Map<String, String>): FormFieldEntity? =
        fields.firstOrNull { field ->
            field.required &&
                field.type !in capturedTypes &&
                isVisible(field, answers) &&
                answers[field.fieldKey].isNullOrBlank()
        }

    /**
     * What stops a visit being submitted.
     *
     * Nothing, by the client's instruction: no field on this form is mandatory. A
     * supervisor at a locked house, with no one to photograph, no signal for a fix
     * and nothing to write, still has a real finding to file — and every check here
     * used to turn that finding into a visit that was never recorded.
     *
     * The function stays rather than being deleted at every call site, because the
     * server still reports genuine failures (an unknown account, an expired session)
     * and the screen needs somewhere to put them. It also means re-enabling a check
     * is one edit here, not a hunt through the UI.
     *
     * What is missing is not lost: the report prints which photographs came with a
     * visit and whether the location was verified, so a thin report is visible as a
     * thin report instead of being impossible to file.
     */
    @Suppress("UNUSED_PARAMETER")
    fun validateVisit(
        photoCount: Int,
        minPhotos: Int,
        remarks: String,
        fields: List<FormFieldEntity>,
        answers: Map<String, String>,
    ): String? = null
}
