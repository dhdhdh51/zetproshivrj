import java.io.FileInputStream
import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.ksp)
}

/**
 * Release signing.
 *
 * Nothing secret lives in the repository. CI reconstructs a keystore from the
 * KEYSTORE_BASE64 secret and passes its path and passwords through either
 * keystore.properties or -P properties. When no keystore is available the release
 * build falls back to the debug signing config so the pipeline still produces an
 * installable artifact for testing.
 */
val keystorePropertiesFile = rootProject.file("keystore.properties")
val keystoreProperties = Properties().apply {
    if (keystorePropertiesFile.exists()) {
        load(FileInputStream(keystorePropertiesFile))
    }
}

fun secret(name: String, propertyName: String): String? =
    keystoreProperties.getProperty(name)
        ?: (project.findProperty(propertyName) as String?)
        ?: System.getenv(name)

val keystorePath = secret("LRMS_KEYSTORE_FILE", "lrmsKeystoreFile")
val hasReleaseKeystore = !keystorePath.isNullOrBlank() && file(keystorePath).exists()

android {
    namespace = "in.lrms.field"
    compileSdk = 35

    defaultConfig {
        applicationId = "in.lrms.field"
        minSdk = 24
        targetSdk = 35
        versionCode = (project.findProperty("lrmsVersionCode") as String?)?.toIntOrNull() ?: 1
        versionName = (project.findProperty("lrmsVersionName") as String?) ?: "1.0.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // The locales that survive into the APK. Anything not listed here is
        // stripped, translations included — this said "en" alone, so values-hi
        // was silently dropped from every build and the app could only ever
        // display English however the language was set.
        resourceConfigurations += listOf("en", "hi")
    }

    signingConfigs {
        if (hasReleaseKeystore) {
            create("release") {
                storeFile = file(keystorePath!!)
                storePassword = secret("LRMS_KEYSTORE_PASSWORD", "lrmsKeystorePassword")
                keyAlias = secret("LRMS_KEY_ALIAS", "lrmsKeyAlias")
                keyPassword = secret("LRMS_KEY_PASSWORD", "lrmsKeyPassword")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
            isMinifyEnabled = false
            // Emulator loopback to a host machine running `php -S`. Only the debug
            // build reads lrmsDebugApiUrl, so a developer default in
            // gradle.properties can never reach a staging or release build.
            buildConfigField("String", "API_BASE_URL", "\"${debugApiUrl()}\"")
            buildConfigField("String", "ENVIRONMENT", "\"development\"")
            buildConfigField("boolean", "ALLOW_CLEARTEXT", "true")
            manifestPlaceholders["usesCleartextTraffic"] = "true"
        }

        create("staging") {
            initWith(getByName("debug"))
            applicationIdSuffix = ".staging"
            versionNameSuffix = "-staging"
            matchingFallbacks += listOf("debug")
            buildConfigField("String", "API_BASE_URL", "\"${apiUrl("https://staging.example.com/api/v1/", "staging")}\"")
            buildConfigField("String", "ENVIRONMENT", "\"staging\"")
            buildConfigField("boolean", "ALLOW_CLEARTEXT", "false")
            manifestPlaceholders["usesCleartextTraffic"] = "false"
        }

        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // Never a development URL in a release build: the value comes from
            // -PlrmsApiUrl or the committed lrmsReleaseApiUrl, and apiUrl() fails
            // the build if it is not HTTPS.
            buildConfigField("String", "API_BASE_URL", "\"${apiUrl(releaseApiUrl(), "release")}\"")
            buildConfigField("String", "ENVIRONMENT", "\"production\"")
            buildConfigField("boolean", "ALLOW_CLEARTEXT", "false")
            manifestPlaceholders["usesCleartextTraffic"] = "false"
            signingConfig = if (hasReleaseKeystore) {
                signingConfigs.getByName("release")
            } else {
                // Documented fallback: an unsigned-for-store but installable APK.
                signingConfigs.getByName("debug")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    packaging {
        resources {
            excludes += setOf(
                "/META-INF/{AL2.0,LGPL2.1}",
                "META-INF/DEPENDENCIES",
                "META-INF/LICENSE*",
                "META-INF/NOTICE*",
            )
        }
    }

    lint {
        // The pipeline should fail on real errors but not on style warnings.
        abortOnError = true
        warningsAsErrors = false
        checkReleaseBuilds = true
        disable += setOf("MissingTranslation", "UnusedResources")
        xmlReport = true
        htmlReport = true
    }

    testOptions {
        unitTests.isReturnDefaultValues = true
    }
}

private fun normaliseUrl(url: String): String = if (url.endsWith("/")) url else "$url/"

private fun overrideUrl(): String? =
    (project.findProperty("lrmsApiUrl") as String?)?.takeIf { it.isNotBlank() }

/**
 * Resolves the API URL for a shipped build type (staging, release).
 *
 * `-PlrmsApiUrl` overrides the default, and the result must be HTTPS: rather than
 * silently shipping a development or cleartext endpoint to field devices, the
 * build fails.
 */
fun apiUrl(default: String, buildType: String): String {
    val url = normaliseUrl(overrideUrl() ?: default)

    if (!url.startsWith("https://")) {
        throw GradleException(
            "Refusing to build '$buildType' with the non-HTTPS API URL $url.\n" +
                "Pass -PlrmsApiUrl=https://your-server/api/v1/ (CI reads the LRMS_API_URL " +
                "repository variable). Development URLs must never be compiled into a " +
                "staging or release build.",
        )
    }

    return url
}

/**
 * The production API URL baked into release builds, from `lrmsReleaseApiUrl` in
 * gradle.properties. The literal fallback only survives if someone deletes that
 * property, and `-PlrmsApiUrl` still overrides both.
 */
fun releaseApiUrl(): String =
    (project.findProperty("lrmsReleaseApiUrl") as String?)?.takeIf { it.isNotBlank() }
        ?: "https://lrms.example.com/api/v1/"

/**
 * The API URL for debug builds, from `lrmsDebugApiUrl`.
 *
 * Falls back to the production URL rather than an emulator loopback: a debug APK
 * built against 10.0.2.2 installs happily on a phone and then times out on every
 * request, which is impossible to tell apart from a bad signal. A developer who
 * wants a local server sets `lrmsDebugApiUrl`; `-PlrmsApiUrl` still wins over
 * both.
 */
fun debugApiUrl(): String {
    val local = (project.findProperty("lrmsDebugApiUrl") as String?)?.takeIf { it.isNotBlank() }

    return normaliseUrl(overrideUrl() ?: local ?: releaseApiUrl())
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    // For AppCompatDelegate.setApplicationLocales: the per-app language
    // picker, backported below API 33 (minSdk here is 24).
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons)
    implementation(libs.androidx.navigation.compose)

    implementation(libs.androidx.room.runtime)
    implementation(libs.androidx.room.ktx)
    ksp(libs.androidx.room.compiler)

    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.androidx.security.crypto)
    implementation(libs.androidx.exifinterface)

    implementation(libs.retrofit)
    implementation(libs.retrofit.moshi)
    implementation(libs.moshi.kotlin)
    implementation(libs.okhttp.logging)

    debugImplementation(libs.androidx.compose.ui.tooling)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)

    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
}
