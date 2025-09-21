package com.example.geolocationapp

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import android.provider.Settings
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.localbroadcastmanager.content.LocalBroadcastManager
import com.android.volley.Response
import com.android.volley.toolbox.StringRequest
import com.android.volley.toolbox.Volley
import com.google.android.gms.location.*
import org.json.JSONObject
import kotlin.math.*

// ---------------------- SERVICE PRINCIPAL ----------------------
class LocationService : Service() {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var locationCallback: LocationCallback
    private lateinit var deviceId: String

    private val CHANNEL_ID = "LocationChannel"
    private val updateInterval: Long = 1000L
    private val SERVER_URL = "http://18.191.60.64/applocation/ubicacion.php"
    private val MAX_GPS_ACCURACY = 15f

    private var lastSteps: Int = 0
    private var lastSentLat: Double? = null
    private var lastSentLon: Double? = null

    private val kalman = KalmanFilter9D()

    override fun onCreate() {
        super.onCreate()
        deviceId = Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID)
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)

        createNotificationChannel()
        startForeground(1, createNotification())

        locationCallback = object : LocationCallback() {
            override fun onLocationResult(locationResult: LocationResult) {
                for (location in locationResult.locations) {
                    val acc = location.accuracy
                    if (acc > MAX_GPS_ACCURACY) continue

                    val lat = location.latitude
                    val lon = location.longitude
                    val gpsSpeed = location.speed.toDouble()

                    val (estLat, estLon) = kalman.update(
                        measLat = lat,
                        measLon = lon,
                        azimut = MainActivityValues.orient[0].toDouble(),
                        pitch = MainActivityValues.orient[1].toDouble(),
                        roll = MainActivityValues.orient[2].toDouble(),
                        accelX = MainActivityValues.accel[0].toDouble(),
                        accelY = MainActivityValues.accel[1].toDouble(),
                        accelZ = MainActivityValues.accel[2].toDouble(),
                        stepDiff = MainActivityValues.steps - lastSteps,
                        speed = gpsSpeed,
                        accuracy = acc.toDouble()
                    )

                    lastSteps = MainActivityValues.steps

                    // Evitar envíos redundantes
                    if (lastSentLat != null && lastSentLon != null) {
                        val distance = haversine(lastSentLat!!, lastSentLon!!, estLat, estLon)
                        if (distance < 1.2 && kalman.isIndoor == kalman.lastIndoorState) continue
                    }

                    lastSentLat = estLat
                    lastSentLon = estLon
                    kalman.lastIndoorState = kalman.isIndoor

                    val data = JSONObject().apply {
                        put("gpsLat", lat)
                        put("gpsLon", lon)
                        put("accuracy", acc)
                        put("estLat", estLat)
                        put("estLon", estLon)
                        put("deviceId", deviceId)
                        put("model", Build.MODEL)
                        put("manufacturer", Build.MANUFACTURER)
                        put("androidVersion", Build.VERSION.RELEASE)
                        put("accelX", MainActivityValues.accel[0])
                        put("accelY", MainActivityValues.accel[1])
                        put("accelZ", MainActivityValues.accel[2])
                        put("azimut", MainActivityValues.orient[0])
                        put("pitch", MainActivityValues.orient[1])
                        put("roll", MainActivityValues.orient[2])
                        put("steps", MainActivityValues.steps)
                        put("indoor", kalman.isIndoor)
                    }

                    sendDataToServer(data)
                    broadcastToUI(lat, lon, estLat, estLon)
                }
            }
        }

        startContinuousUpdates()
    }

    private fun haversine(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Double {
        val R = 6371000.0
        val dLat = Math.toRadians(lat2 - lat1)
        val dLon = Math.toRadians(lon2 - lon1)
        val a = sin(dLat / 2).pow(2.0) +
                cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) *
                sin(dLon / 2).pow(2.0)
        val c = 2 * atan2(sqrt(a), sqrt(1 - a))
        return R * c
    }

    private fun startContinuousUpdates() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED &&
            ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) != PackageManager.PERMISSION_GRANTED
        ) return

        val locationRequest = LocationRequest.Builder(
            Priority.PRIORITY_HIGH_ACCURACY, updateInterval
        ).apply {
            setMinUpdateIntervalMillis(500)
            setMaxUpdateDelayMillis(1500)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                setWaitForAccurateLocation(true)
            }
        }.build()

        fusedLocationClient.requestLocationUpdates(locationRequest, locationCallback, Looper.getMainLooper())
    }

    private fun sendDataToServer(data: JSONObject) {
        val stringRequest = object : StringRequest(
            Method.POST, SERVER_URL,
            Response.Listener { response -> Log.d("SERVER_RESPONSE", "Respuesta: $response") },
            Response.ErrorListener { error -> Log.e("SERVER_ERROR", "Error: ${error?.message}") }
        ) {
            override fun getParams(): Map<String, String> = mapOf("data" to data.toString())
        }
        Volley.newRequestQueue(applicationContext).add(stringRequest)
    }

    private fun broadcastToUI(lat: Double, lon: Double, estLat: Double, estLon: Double) {
        val intent = Intent("LOCATION_UPDATE").apply {
            putExtra("gpsLat", lat)
            putExtra("gpsLon", lon)
            putExtra("estLat", estLat)
            putExtra("estLon", estLon)
            putExtra("steps", MainActivityValues.steps)
            putExtra("accelX", MainActivityValues.accel[0])
            putExtra("accelY", MainActivityValues.accel[1])
            putExtra("accelZ", MainActivityValues.accel[2])
            putExtra("azimut", MainActivityValues.orient[0])
            putExtra("pitch", MainActivityValues.orient[1])
            putExtra("roll", MainActivityValues.orient[2])
            putExtra("indoor", kalman.isIndoor)
        }
        LocalBroadcastManager.getInstance(this).sendBroadcast(intent)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val serviceChannel = NotificationChannel(CHANNEL_ID, "Location Service", NotificationManager.IMPORTANCE_LOW)
            getSystemService(NotificationManager::class.java)?.createNotificationChannel(serviceChannel)
        }
    }

    private fun createNotification(): Notification {
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Enviando ubicación y sensores")
            .setContentText("La app sigue enviando datos cada segundo")
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setOngoing(true)
            .build()
    }

    override fun onBind(intent: Intent?): IBinder? = null
    override fun onDestroy() {
        super.onDestroy()
        fusedLocationClient.removeLocationUpdates(locationCallback)
    }
}

// ---------------------- VALORES DE SENSOR ----------------------
object MainActivityValues {
    var accel = floatArrayOf(0f, 0f, 0f)
    var orient = floatArrayOf(0f, 0f, 0f)
    var steps = 0
}

// ---------------------- KALMAN 9D ULTRA-MEJORADO ----------------------
class KalmanFilter9D {

    private var lat = 0.0
    private var lon = 0.0
    private var vLat = 0.0
    private var vLon = 0.0
    private var aLat = 0.0
    private var aLon = 0.0
    private var P = DoubleArray(6) { 1.0 }
    private var initialized = false
    private var lastTime = System.currentTimeMillis()
    private var lastSpeed = 0.0
    private var lastAzimut = 0.0

    var isIndoor: Boolean = false
    var lastIndoorState: Boolean = false

    private val gpsBuffer = mutableListOf<Pair<Double, Double>>()
    private val azimutBuffer = mutableListOf<Double>()

    private fun adaptFactor(accuracy: Double, speed: Double): Double =
        min(1.0, 5.0 / (accuracy + 1.0)) * if (speed < 0.5) 0.6 else 1.0

    private fun detectIndoor(accelX: Double, accelY: Double, accelZ: Double, gpsAcc: Double) {
        val accelMag = sqrt(accelX.pow(2) + accelY.pow(2) + accelZ.pow(2))
        isIndoor = gpsAcc > 10.0 || accelMag > 20.0
    }

    private fun correctAnomalies(measLat: Double, measLon: Double, dt: Double): Pair<Double, Double> {
        if (gpsBuffer.isEmpty()) return Pair(measLat, measLon)
        val last = gpsBuffer.last()
        val distance = haversine(last.first, last.second, measLat, measLon)
        val maxDistance = max(0.5, lastSpeed * dt * 1.5)
        return if (distance > maxDistance) {
            val ratio = maxDistance / distance
            Pair(last.first + (measLat - last.first) * ratio, last.second + (measLon - last.second) * ratio)
        } else Pair(measLat, measLon)
    }

    private fun smoothAzimut(currentAzimut: Double, dt: Double): Double {
        val diff = ((currentAzimut - lastAzimut + 540) % 360) - 180
        val maxChange = 30 * dt
        val change = diff.coerceIn(-maxChange, maxChange)
        lastAzimut = (lastAzimut + change + 360) % 360
        return lastAzimut
    }

    private fun haversine(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Double {
        val R = 6371000.0
        val dLat = Math.toRadians(lat2 - lat1)
        val dLon = Math.toRadians(lon2 - lon1)
        val a = sin(dLat / 2).pow(2.0) +
                cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) *
                sin(dLon / 2).pow(2.0)
        val c = 2 * atan2(sqrt(a), sqrt(1 - a))
        return R * c
    }

    fun update(
        measLat: Double, measLon: Double,
        azimut: Double, pitch: Double, roll: Double,
        accelX: Double, accelY: Double, accelZ: Double,
        stepDiff: Int, speed: Double, accuracy: Double
    ): Pair<Double, Double> {

        if (accuracy > 15.0) return Pair(lat, lon)
        val currentTime = System.currentTimeMillis()
        val dt = max((currentTime - lastTime) / 1000.0, 0.001)
        lastTime = currentTime

        if (!initialized) {
            lat = measLat
            lon = measLon
            P[0] = accuracy.pow(2.0)
            P[1] = accuracy.pow(2.0)
            initialized = true
            lastSpeed = speed
            lastAzimut = azimut
            detectIndoor(accelX, accelY, accelZ, accuracy)
            gpsBuffer.add(Pair(lat, lon))
            azimutBuffer.add(azimut)
            return Pair(lat, lon)
        }

        detectIndoor(accelX, accelY, accelZ, accuracy)
        val factor = adaptFactor(accuracy, speed)
        val (corrLat, corrLon) = correctAnomalies(measLat, measLon, dt)

        gpsBuffer.add(Pair(corrLat, corrLon))
        if (gpsBuffer.size > 7) gpsBuffer.removeAt(0)
        azimutBuffer.add(azimut)
        if (azimutBuffer.size > 7) azimutBuffer.removeAt(0)

        val avgLat = weightedAverage(gpsBuffer.map { it.first })
        val avgLon = weightedAverage(gpsBuffer.map { it.second })
        val smoothAz = smoothAzimut(azimut, dt)

        // Movimiento GPS
        lat += factor * (speed * dt * cos(Math.toRadians(smoothAz))) / 111320.0
        lon += factor * (speed * dt * sin(Math.toRadians(smoothAz))) / (111320.0 * cos(Math.toRadians(lat)))

        // Pasos
        if (stepDiff > 0) applyStepCorrection(stepDiff, smoothAz, factor)

        // Acelerómetro
        applyAccelCorrection(accelX, accelY, accelZ, pitch, roll, factor, dt)

        // Predicción híbrida
        val predFactor = min(1.0, speed / 2.0)
        lat += vLat * predFactor
        lon += vLon * predFactor

        // Suavizado GPS ponderado
        val R = accuracy.pow(2.0)
        val KLat = P[0] / (P[0] + R)
        val KLon = P[1] / (P[1] + R)
        lat += KLat * (avgLat - lat)
        lon += KLon * (avgLon - lon)
        P[0] *= (1 - KLat)
        P[1] *= (1 - KLon)

        lastSpeed = speed
        return Pair(lat, lon)
    }

    private fun weightedAverage(values: List<Double>): Double {
        val weights = (1..values.size).map { it.toDouble() }
        val sumWeights = weights.sum()
        return values.mapIndexed { i, v -> v * weights[i] }.sum() / sumWeights
    }

    private fun applyStepCorrection(stepDiff: Int, azimut: Double, factor: Double) {
        val stepLength = if (isIndoor) 0.65 else 0.75 * (1 + lastSpeed / 2.0)
        val distance = stepDiff * stepLength
        val dLatStep = (distance * cos(Math.toRadians(azimut))) / 111320.0
        val dLonStep = (distance * sin(Math.toRadians(azimut))) / (111320.0 * cos(Math.toRadians(lat)))
        val stepFactor = if (isIndoor) 0.2 else 0.35
        lat += stepFactor * factor * dLatStep
        lon += stepFactor * factor * dLonStep
    }

    private fun applyAccelCorrection(ax: Double, ay: Double, az: Double, pitch: Double, roll: Double, factor: Double, dt: Double) {
        val accelFactor = if (isIndoor) 0.2 else 0.5
        val accelMag = sqrt(ax.pow(2) + ay.pow(2) + az.pow(2))
        val adjFactor = if (accelMag > 25.0) 25.0 / accelMag else 1.0

        aLat = (1 - accelFactor) * aLat + accelFactor * (ax * cos(Math.toRadians(pitch)) - ay * sin(Math.toRadians(roll))) * adjFactor
        aLon = (1 - accelFactor) * aLon + accelFactor * (ay * cos(Math.toRadians(roll)) + ax * sin(Math.toRadians(pitch))) * adjFactor
        vLat += aLat * dt
        vLon += aLon * dt
        lat += factor * vLat * dt
        lon += factor * vLon * dt
    }
}
