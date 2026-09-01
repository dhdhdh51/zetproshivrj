# LRMS Field — release shrinking rules.

# Keep the wire models: Moshi's reflective adapter reads their constructors and
# property names, so obfuscating them would break every API call.
-keep class in.lrms.field.data.remote.** { *; }
-keep class in.lrms.field.data.local.** { *; }

# Kotlin metadata is required by moshi-kotlin's reflective adapter.
-keep class kotlin.Metadata { *; }
-keepclassmembers class kotlin.Metadata { public <methods>; }
-keep class kotlin.reflect.jvm.internal.** { *; }

# Moshi
-keepclasseswithmembers class * {
    @com.squareup.moshi.* <methods>;
}
-keep @com.squareup.moshi.JsonQualifier @interface *
-keepclassmembers @com.squareup.moshi.JsonClass class * { <init>(...); }
-dontwarn okio.**
-dontwarn com.squareup.moshi.**

# Retrofit keeps generic signatures and annotations on service interfaces.
-keepattributes Signature, InnerClasses, EnclosingMethod, RuntimeVisibleAnnotations, AnnotationDefault
-keep,allowobfuscation,allowshrinking interface retrofit2.Call
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation
-if interface * { @retrofit2.http.* public *** *(...); }
-keep,allowoptimization,allowshrinking,allowobfuscation class <3>
-dontwarn retrofit2.**
-dontwarn javax.annotation.**

# OkHttp
-dontwarn okhttp3.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Room generates implementations at build time; keep the generated classes.
-keep class * extends androidx.room.RoomDatabase { <init>(); }
-dontwarn androidx.room.paging.**

# WorkManager instantiates workers by name.
-keep class in.lrms.field.sync.SyncWorker { <init>(...); }

# Keep the Application and Activity referenced from the manifest.
-keep class in.lrms.field.LrmsApp { <init>(); }
-keep class in.lrms.field.ui.MainActivity { <init>(); }


# Tink (pulled in by androidx.security-crypto for the encrypted token store)
# references Error Prone annotations that are compile-time only.
-dontwarn com.google.errorprone.annotations.**
-dontwarn com.google.crypto.tink.**
-keep class com.google.crypto.tink.** { *; }
