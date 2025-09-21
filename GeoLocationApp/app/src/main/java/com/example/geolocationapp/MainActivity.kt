package com.example.geolocationapp

import android.Manifest
import android.content.*
import android.content.pm.PackageManager
import android.hardware.*
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.widget.Button
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.localbroadcastmanager.content.LocalBroadcastManager
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationServices
import java.lang.Math.toDegrees

class MainActivity : AppCompatActivity(), SensorEventListener {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var tvLocation: TextView
    private lateinit var tvInfo: TextView
    private lateinit var tvSensors: TextView
    private lateinit var btnUpdate: Button
    private lateinit var btnCopy: Button

    private lateinit var sensorManager: SensorManager
    private var rotationMatrix = FloatArray(9)
    private var orientationValues = FloatArray(3)

    private var stepsCount = 0
    private var initialStepCount: Int? = null

    private val LOCATION_PERMISSION_REQUEST_CODE = 100
    private val NOTIFICATION_PERMISSION_REQUEST_CODE = 200

    private var lastValidLat: Double? = null
    private var lastValidLon: Double? = null

    // Receptor de actualizaciones de LocationService
    private val locationReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action == "LOCATION_UPDATE") {
                val lat = intent.getDoubleExtra("gpsLat", 0.0)
                val lon = intent.getDoubleExtra("gpsLon", 0.0)

                if (lat != 0.0 && lon != 0.0) {
                    lastValidLat = lat
                    lastValidLon = lon
                    tvLocation.text = "Lat: $lat, Lon: $lon"
                }
            }
        }
    }

    private val requestMultiplePermissions =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { results ->
            val fine = results[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
            val coarse = results[Manifest.permission.ACCESS_COARSE_LOCATION] ?: false
            val background = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                results[Manifest.permission.ACCESS_BACKGROUND_LOCATION] ?: false
            } else true
            val activityRecognition = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                results[Manifest.permission.ACTIVITY_RECOGNITION] ?: false
            } else true

            if (fine && coarse && background && activityRecognition) {
                ensureNotificationPermissionAndStart()
            } else {
                Toast.makeText(this, "Necesitas aceptar permisos de ubicación y actividad física", Toast.LENGTH_LONG).show()
                openAppSettings()
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        tvLocation = findViewById(R.id.tvLocation)
        tvInfo = findViewById(R.id.tvInfo)
        tvSensors = findViewById(R.id.tvSensors)
        btnUpdate = findViewById(R.id.btnUpdate)
        btnCopy = findViewById(R.id.btnCopy)

        tvLocation.text = "Esperando ubicación..."

        tvInfo.text = """
            Manuel Eduardo Quispe Condori
            Código: 200858
            UNSAAC
            Modelo: ${Build.MODEL}
            Fabricante: ${Build.MANUFACTURER}
            Android: ${Build.VERSION.RELEASE}
        """.trimIndent()

        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)

        btnUpdate.setOnClickListener { getLastLocationOnce() }
        btnCopy.setOnClickListener {
            val data = tvLocation.text.toString() + "\n" + tvSensors.text.toString()
            val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
            val clip = ClipData.newPlainText("Datos", data)
            clipboard.setPrimaryClip(clip)
            Toast.makeText(this, "Datos copiados", Toast.LENGTH_SHORT).show()
        }

        LocalBroadcastManager.getInstance(this).registerReceiver(
            locationReceiver,
            IntentFilter("LOCATION_UPDATE")
        )

        sensorManager = getSystemService(Context.SENSOR_SERVICE) as SensorManager
        try {
            sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)?.also {
                sensorManager.registerListener(this, it, SensorManager.SENSOR_DELAY_UI)
            }
            sensorManager.getDefaultSensor(Sensor.TYPE_ROTATION_VECTOR)?.also {
                sensorManager.registerListener(this, it, SensorManager.SENSOR_DELAY_UI)
            }
            sensorManager.getDefaultSensor(Sensor.TYPE_STEP_COUNTER)?.also {
                sensorManager.registerListener(this, it, SensorManager.SENSOR_DELAY_UI)
            } ?: run {
                tvSensors.append("\n⚠️ Sensor de pasos no disponible")
            }
        } catch (e: Exception) {
            tvSensors.append("\n⚠️ Error registrando sensores")
        }

        ensureLocationPermissionsAndStart()
    }

    override fun onSensorChanged(event: SensorEvent) {
        when (event.sensor.type) {
            Sensor.TYPE_ACCELEROMETER -> {
                MainActivityValues.accel[0] = event.values[0]
                MainActivityValues.accel[1] = event.values[1]
                MainActivityValues.accel[2] = event.values[2]
            }
            Sensor.TYPE_ROTATION_VECTOR -> {
                SensorManager.getRotationMatrixFromVector(rotationMatrix, event.values)
                SensorManager.getOrientation(rotationMatrix, orientationValues)
                MainActivityValues.orient[0] = toDegrees(orientationValues[0].toDouble()).toFloat()
                MainActivityValues.orient[1] = toDegrees(orientationValues[1].toDouble()).toFloat()
                MainActivityValues.orient[2] = toDegrees(orientationValues[2].toDouble()).toFloat()
            }
            Sensor.TYPE_STEP_COUNTER -> {
                val totalSteps = event.values[0].toInt()
                if (initialStepCount == null) initialStepCount = totalSteps
                stepsCount = totalSteps - (initialStepCount ?: 0)
                MainActivityValues.steps = stepsCount
            }
        }
        updateSensorText()
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}

    private fun updateSensorText() {
        tvSensors.text = """
            Acelerómetro → 
              X:${"%.2f".format(MainActivityValues.accel[0])}, 
              Y:${"%.2f".format(MainActivityValues.accel[1])}, 
              Z:${"%.2f".format(MainActivityValues.accel[2])}
            
            Orientación → 
              Azimut:${"%.1f".format(MainActivityValues.orient[0])}°, 
              Pitch:${"%.1f".format(MainActivityValues.orient[1])}°, 
              Roll:${"%.1f".format(MainActivityValues.orient[2])}°
            
            Pasos detectados → ${MainActivityValues.steps}
        """.trimIndent()
    }

    private fun ensureLocationPermissionsAndStart() {
        if (hasAllLocationPermissions()) {
            ensureNotificationPermissionAndStart()
        } else {
            val perms = mutableListOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION
            )
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                perms.add(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                perms.add(Manifest.permission.ACTIVITY_RECOGNITION)
            }
            requestMultiplePermissions.launch(perms.toTypedArray())
        }
    }

    private fun ensureNotificationPermissionAndStart() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ActivityCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED
            ) {
                ActivityCompat.requestPermissions(
                    this,
                    arrayOf(Manifest.permission.POST_NOTIFICATIONS),
                    NOTIFICATION_PERMISSION_REQUEST_CODE
                )
                return
            }
        }
        startLocationService()
    }

    private fun hasAllLocationPermissions(): Boolean {
        val fine = ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val coarse = ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val background = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION) == PackageManager.PERMISSION_GRANTED
        } else true
        val activityRecognition = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ActivityCompat.checkSelfPermission(this, Manifest.permission.ACTIVITY_RECOGNITION) == PackageManager.PERMISSION_GRANTED
        } else true
        return fine && coarse && background && activityRecognition
    }

    private fun startLocationService() {
        val serviceIntent = Intent(this, LocationService::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(serviceIntent)
        } else {
            startService(serviceIntent)
        }
        Toast.makeText(this, "Servicio de ubicación iniciado", Toast.LENGTH_SHORT).show()
    }

    private fun getLastLocationOnce() {
        if (!hasAllLocationPermissions()) {
            Toast.makeText(this, "Faltan permisos de ubicación o actividad", Toast.LENGTH_SHORT).show()
            ensureLocationPermissionsAndStart()
            return
        }

        try {
            fusedLocationClient.lastLocation
                .addOnSuccessListener { location ->
                    if (location != null) {
                        val lat = location.latitude
                        val lon = location.longitude
                        lastValidLat = lat
                        lastValidLon = lon
                        tvLocation.text = "Lat: $lat, Lon: $lon"
                    } else {
                        tvLocation.text = if (lastValidLat != null && lastValidLon != null) {
                            "Lat: $lastValidLat, Lon: $lastValidLon"
                        } else {
                            "Esperando actualización del servicio..."
                        }
                        startLocationService()
                    }
                }
                .addOnFailureListener {
                    tvLocation.text = "Error al obtener ubicación"
                }
        } catch (se: SecurityException) {
            Toast.makeText(this, "Permisos insuficientes", Toast.LENGTH_SHORT).show()
        }
    }

    private fun openAppSettings() {
        val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.fromParts("package", packageName, null)
        }
        startActivity(intent)
        Toast.makeText(this, "Activa permisos en Configuración", Toast.LENGTH_LONG).show()
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == NOTIFICATION_PERMISSION_REQUEST_CODE &&
            grantResults.isNotEmpty() &&
            grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            startLocationService()
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        sensorManager.unregisterListener(this)
        LocalBroadcastManager.getInstance(this).unregisterReceiver(locationReceiver)
    }
}
