pluginManagement {
    repositories {
        gradlePluginPortal()
        google()
        mavenCentral()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()       // Necesario para Play Services
        mavenCentral() // Necesario para la mayoría de librerías
    }
}

rootProject.name = "GeoLocationApp"
include(":app")
