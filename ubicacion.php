<?php
header("Content-Type: text/html; charset=UTF-8");

$filePath = __DIR__ . "/ubicaciones.json";
$logPath  = __DIR__ . "/log.txt";

// 📏 Función para calcular distancia en metros (haversine)
function distanciaMetros($lat1, $lon1, $lat2, $lon2) {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

// Leer JSON seguro
function readJsonFile($path) {
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    return $data;
}

// Escribir JSON atómico
function writeJsonFileAtomic($path, $data) {
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

// 📌 Guardar ubicación
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["lat"], $_POST["lon"])) {
        $lat = (float) $_POST["lat"];
        $lon = (float) $_POST["lon"];
        $usuario = trim($_POST["usuario"] ?? "desconocido");

        // Hora preferida: del dispositivo
        if (isset($_POST['hora_ms'])) {
            $hora_ms = (int) $_POST['hora_ms'];
        } elseif (isset($_POST['hora'])) {
            $hora_ms = (int) (strtotime($_POST['hora']) * 1000);
        } else {
            $hora_ms = (int) round(microtime(true) * 1000);
        }

        // accuracy opcional
        $accuracy = isset($_POST['accuracy']) ? (float) $_POST['accuracy'] : null;

        // Registrar POST para debug
        @file_put_contents($logPath, date('Y-m-d H:i:s') . " POST=" . json_encode($_POST) . PHP_EOL, FILE_APPEND);

        $data = readJsonFile($filePath);
        if (!isset($data[$usuario]) || !is_array($data[$usuario])) {
            $data[$usuario] = [];
        }

        // 🚦 Parámetros más estables
        $minMove = 10.0;       // ignorar movimientos <10m
        $maxAccuracy = 10.0;   // ignorar puntos con precisión peor a 10m
        $maxVelocidad = 3.0;   // descartar >3 m/s (≈ 10.8 km/h)

        // ❌ Descartar por baja precisión
        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            echo "⚠️ Punto descartado por baja precisión ({$accuracy}m)";
            exit;
        }

        if (!empty($data[$usuario])) {
            $ultimo = end($data[$usuario]);

            $lastLat = (float) ($ultimo['latitud'] ?? 0);
            $lastLon = (float) ($ultimo['longitud'] ?? 0);
            $lastFechaMs = isset($ultimo['fecha_ms']) ? (int) $ultimo['fecha_ms'] :
                           (isset($ultimo['fecha']) ? (int) (strtotime($ultimo['fecha']) * 1000) : $hora_ms - 1000);

            $distancia = distanciaMetros($lastLat, $lastLon, $lat, $lon);
            $deltaT = max(0.1, ($hora_ms - $lastFechaMs) / 1000.0);
            $velocidad = $distancia / $deltaT;

            @file_put_contents($logPath, date('Y-m-d H:i:s') .
                " INFO usuario=$usuario distancia={$distancia}m deltaT={$deltaT}s velocidad={$velocidad}m/s accuracy={$accuracy}" . PHP_EOL, FILE_APPEND);

            // ❌ Ignorar movimientos muy pequeños
            if ($distancia < $minMove) {
                echo "ℹ️ Movimiento menor a {$minMove}m ignorado";
                exit;
            }

            // ❌ Ignorar velocidades irreales
            if ($velocidad > $maxVelocidad) {
                echo "⚠️ Movimiento incoherente ignorado (" . round($velocidad,2) . " m/s)";
                exit;
            }
        }

        // ✅ Guardar nueva ubicación
        $nuevoPunto = [
            "latitud"  => $lat,
            "longitud" => $lon,
            "accuracy" => $accuracy,
            "fecha_ms" => $hora_ms,
            "fecha"    => gmdate("Y-m-d H:i:s", (int)($hora_ms / 1000))
        ];

        // Filtro suavizado: promedio de últimos 2 + nuevo
        if (count($data[$usuario]) >= 2) {
            $p1 = $data[$usuario][count($data[$usuario]) - 1];
            $p2 = $data[$usuario][count($data[$usuario]) - 2];
            $nuevoPunto["latitud"]  = ($lat + $p1["latitud"] + $p2["latitud"]) / 3.0;
            $nuevoPunto["longitud"] = ($lon + $p1["longitud"] + $p2["longitud"]) / 3.0;
        }

        $data[$usuario][] = $nuevoPunto;

        if (count($data[$usuario]) > 500) {
            $data[$usuario] = array_slice($data[$usuario], -500);
        }

        if (writeJsonFileAtomic($filePath, $data)) {
            echo "✅ Ubicación guardada de $usuario";
        } else {
            echo "❌ Error al guardar ubicación";
        }
    } else {
        echo "❌ Faltan parámetros (lat, lon)";
    }
    exit;
}

// Leer ubicaciones para mostrar en el mapa
$ubicaciones = readJsonFile($filePath);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ubicaciones en Tiempo Real</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f9f9f9; color: #222; text-align: center; }
        h2 { padding: 15px; color: #333; }
        #map { height: 500px; width: 90%; margin: 20px auto; border: 2px solid #333; border-radius: 12px; }
        .datos, .info-usuarios { margin: 15px auto; padding: 15px; border-radius: 10px; background: #fff; max-width: 600px; }
        .datos { border: 2px solid #555; max-width: 400px; }
        .info-usuarios { border: 1px solid #aaa; text-align: left; }
        .botones { text-align: center; margin: 15px; }
        .boton { margin: 5px; padding: 10px 15px; border: none; border-radius: 8px; font-weight: bold; color: #fff; cursor: pointer; transition: transform 0.2s; }
        .boton:hover { transform: scale(1.1); }
    </style>
</head>
<body>
    <h2>📍 Ubicaciones en Tiempo Real</h2>

    <div class="datos">
        <strong>👤 Nombre:</strong> Manuel Eduardo Quispe Condori<br>
        <strong>🎓 Código:</strong> 200858<br>
        <strong>🏫 Universidad:</strong> UNSAAC
    </div>

    <?php if (!empty($ubicaciones)): ?>
        <div id="map"></div>
        <div class="botones" id="botones"></div>
        <div class="info-usuarios" id="infoUsuarios"><h3>📌 Últimas posiciones recibidas</h3><p>Cargando...</p></div>

        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            var map = L.map('map').setView([0, 0], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            var markers = {}, polylines = {}, colorIndex = {}, lineVisible = {};
            var colores = ["#ff4c4c", "#4cff4c", "#4c4cff", "#ffcc00", "#ff66ff", "#00ffff", "#ffa500", "#00ffcc", "#8A2BE2", "#FF4500"];
            var firstFit = true;

            function getColor(usuario) {
                if (!(usuario in colorIndex)) {
                    var idx = Object.keys(colorIndex).length;
                    colorIndex[usuario] = idx;
                }
                return colores[colorIndex[usuario] % colores.length];
            }

            function toggleLinea(usuario) {
                if (polylines[usuario]) {
                    if (lineVisible[usuario]) {
                        map.removeLayer(polylines[usuario]);
                        lineVisible[usuario] = false;
                    } else {
                        polylines[usuario].addTo(map);
                        lineVisible[usuario] = true;
                    }
                }
            }

            function actualizarUbicaciones() {
                fetch("ubicaciones.json?nocache=" + new Date().getTime())
                    .then(r => r.json())
                    .then(data => {
                        var bounds = [], infoHtml = "<h3>📌 Últimas posiciones recibidas</h3>", botonesHtml = "";

                        for (var usuario in data) {
                            var historial = data[usuario];
                            if (!historial || historial.length === 0) continue;

                            var ultimo = historial[historial.length - 1];
                            var lat = parseFloat(ultimo.latitud), lon = parseFloat(ultimo.longitud);
                            var color = getColor(usuario);

                            var fechaLocal = new Date().toLocaleString();

                            infoHtml += "<p><strong>👤 Usuario:</strong> " + usuario +
                                        "<br>🌍 Lat: " + lat +
                                        "<br>🌍 Lon: " + lon +
                                        (ultimo.accuracy ? ("<br>📏 Error: " + ultimo.accuracy + " m") : "") +
                                        "<br>⏰ Fecha: " + fechaLocal + "</p>";

                            botonesHtml += "<button class='boton' style='background:" + color + "' onclick=\"toggleLinea('" + usuario + "')\">👁 " + usuario + "</button>";

                            var popupText = "👤 Usuario: " + usuario + "<br>" +
                                            "Lat: " + lat + "<br>" +
                                            "Lon: " + lon + "<br>" +
                                            (ultimo.accuracy ? ("Error: " + ultimo.accuracy + " m<br>") : "") +
                                            "⏰ " + fechaLocal;

                            if (!markers[usuario]) {
                                markers[usuario] = L.marker([lat, lon], {title: usuario}).addTo(map).bindPopup(popupText);
                            } else {
                                markers[usuario].setLatLng([lat, lon]).setPopupContent(popupText);
                            }

                            var coords = historial.map(function(p){ return [parseFloat(p.latitud), parseFloat(p.longitud)]; });

                            if (!polylines[usuario]) {
                                polylines[usuario] = L.polyline(coords, {color: color, weight:3}).addTo(map);
                                lineVisible[usuario] = true;
                            } else {
                                polylines[usuario].setLatLngs(coords);
                                if (!lineVisible[usuario]) map.removeLayer(polylines[usuario]);
                            }

                            bounds.push([lat, lon]);
                        }

                        document.getElementById("infoUsuarios").innerHTML = infoHtml;
                        document.getElementById("botones").innerHTML = botonesHtml;

                        if (firstFit && bounds.length) {
                            try { map.fitBounds(bounds); } catch(e) {}
                            firstFit = false;
                        }
                    })
                    .catch(err => console.error("Error al actualizar:", err));
            }

            setInterval(actualizarUbicaciones, 2000); // menos frecuente para estabilidad
            actualizarUbicaciones();
        </script>
    <?php else: ?>
        <h3>❌ No se ha recibido ninguna ubicación todavía</h3>
    <?php endif; ?>
</body>
</html>
