package `in`.lrms.field

import `in`.lrms.field.util.AppLanguage
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The stored-tag mapping, tested without an Android framework.
 *
 * fromTag() reads a value that has been sitting in a preferences file, possibly
 * written by a different build of the app. It must never throw: a language setting
 * is not worth crashing over, and falling back to the phone's language is always a
 * defensible answer.
 */
class AppLanguageTest {

    @Test
    fun `known tags resolve`() {
        assertEquals(AppLanguage.ENGLISH, AppLanguage.fromTag("en"))
        assertEquals(AppLanguage.HINDI, AppLanguage.fromTag("hi"))
    }

    @Test
    fun `a region qualified tag resolves on its language`() {
        // Android can hand back "hi-IN" where "hi" was stored.
        assertEquals(AppLanguage.HINDI, AppLanguage.fromTag("hi-IN"))
        assertEquals(AppLanguage.ENGLISH, AppLanguage.fromTag("en-GB"))
    }

    @Test
    fun `case does not matter`() {
        assertEquals(AppLanguage.HINDI, AppLanguage.fromTag("HI"))
    }

    @Test
    fun `an empty or missing tag follows the phone`() {
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag(null))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag(""))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("   "))
    }

    @Test
    fun `an unrecognised tag falls back rather than throwing`() {
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("fr"))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("not-a-language"))
    }

    @Test
    fun `a stored tag survives a round trip`() {
        AppLanguage.entries.forEach { language ->
            assertEquals(language, AppLanguage.fromTag(AppLanguage.tagFor(language)))
        }
    }

    @Test
    fun `following the phone is stored as an empty tag`() {
        assertEquals("", AppLanguage.tagFor(AppLanguage.SYSTEM))
    }

    @Test
    fun `each language is named in its own script`() {
        // A supervisor who has switched into a language they cannot read must
        // still recognise the way back, so these are never translated.
        assertEquals("English", AppLanguage.ENGLISH.nativeName)
        assertEquals("हिन्दी", AppLanguage.HINDI.nativeName)
    }

    @Test
    fun `english is offered before hindi`() {
        val offered = AppLanguage.CHOICES

        assertTrue(offered.indexOf(AppLanguage.ENGLISH) < offered.indexOf(AppLanguage.HINDI))
        assertTrue(offered.contains(AppLanguage.SYSTEM))
    }
}
