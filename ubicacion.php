<?php
header("Content-Type: text/html; charset=UTF-8");

$filePath = __DIR__ . "/ubicaciones.json";

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

// ✅ Guardar ubicación cuando se recibe POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $lat = isset($_POST["lat"]) ? (float)$_POST["lat"] : null;
    $lon = isset($_POST["lon"]) ? (float)$_POST["lon"] : null;
    $usuario = $_POST["usuario"] ?? "desconocido";

    if ($lat && $lon) {
        $data = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];

        if (!isset($data[$usuario])) {
            $data[$usuario] = [];
        }

        // 📏 Verificar coherencia con última posición
        if (!empty($data[$usuario])) {
            $ultimo = end($data[$usuario]);
            $distancia = distanciaMetros($ultimo["latitud"], $ultimo["longitud"], $lat, $lon);

            if ($distancia > 3) { // 👈 ahora solo se acepta cambio si es <= 1m
                echo "⚠️ Movimiento incoherente mayor a 1m ignorado";
                exit;
            }
        }

        // Guardar nueva ubicación
        $data[$usuario][] = [
            "latitud" => $lat,
            "longitud" => $lon,
            "fecha" => date("Y-m-d H:i:s")
        ];

        // Mantener máximo 500 registros
        if (count($data[$usuario]) > 500) {
            $data[$usuario] = array_slice($data[$usuario], -500);
        }

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "✅ Ubicación guardada de $usuario";
    } else {
        echo "❌ Faltan parámetros (lat, lon)";
    }
    exit;
}

$ubicaciones = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ubicaciones en Tiempo Real</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background: #f9f9f9;
            color: #222;
            text-align: center;
        }
        h2 { padding: 15px; color: #333; }
        #map { height: 500px; width: 90%; margin: 20px auto; border: 2px solid #333; border-radius: 12px; }
        .datos {
            margin: 15px auto;
            padding: 15px;
            border: 2px solid #555;
            border-radius: 10px;
            background: #fff;
            max-width: 400px;
        }
        .info-usuarios {
            margin: 20px auto;
            padding: 15px;
            border: 1px solid #aaa;
            border-radius: 8px;
            background: #fff;
            max-width: 600px;
            text-align: left;
        }
        .botones { text-align: center; margin: 15px; }
        .boton {
            margin: 5px;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            color: #fff;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .boton:hover { transform: scale(1.1); }
    </style>
</head>
<body>
    <h2>📍 Ubicaciones en Tiempo Real</h2>

    <!-- 👤 Datos del creador -->
    <div class="datos">
        <strong>👤 Nombre:</strong> Manuel Eduardo Quispe Condori<br>
        <strong>🎓 Código:</strong> 200858<br>
        <strong>🏫 Universidad:</strong> UNSAAC
    </div>

    <?php if (!empty($ubicaciones)): ?>
        <div id="map"></div>

        <!-- Botones dinámicos -->
        <div class="botones" id="botones"></div>

        <div class="info-usuarios" id="infoUsuarios">
            <h3>📌 Últimas posiciones recibidas</h3>
            <p>Cargando...</p>
        </div>

        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            var map = L.map('map').setView([0, 0], 2);

            // 🔹 Mapa claro estándar
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            var markers = {};
            var polylines = {};
            var colores = ["#ff4c4c", "#4cff4c", "#4c4cff", "#ffcc00", "#ff66ff", "#00ffff", "#ffa500", "#00ffcc"];
            var colorIndex = {};
            var lineVisible = {};
            var firstFit = true;

            function getColor(usuario) {
                if (!colorIndex[usuario]) {
                    var index = Object.keys(colorIndex).length % colores.length;
                    colorIndex[usuario] = colores[index];
                }
                return colorIndex[usuario];
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
                    .then(response => response.json())
                    .then(data => {
                        var bounds = [];
                        var infoHtml = "<h3>📌 Últimas posiciones recibidas</h3>";
                        var botonesHtml = "";

                        for (var usuario in data) {
                            var historial = data[usuario];
                            if (historial.length === 0) continue;

                            var ultimo = historial[historial.length - 1];
                            var lat = ultimo.latitud;
                            var lon = ultimo.longitud;
                            var fecha = ultimo.fecha;
                            var color = getColor(usuario);

                            infoHtml += "<p><strong>👤 Usuario:</strong> " + usuario +
                                        "<br>🌍 Lat: " + lat +
                                        "<br>🌍 Lon: " + lon +
                                        "<br>⏰ Fecha: " + fecha + "</p>";

                            botonesHtml += "<button class='boton' style='background:" + color + "' onclick=\"toggleLinea('" + usuario + "')\">👁 " + usuario + "</button>";

                            var popupText = "👤 Usuario: " + usuario + "<br>" +
                                            "Lat: " + lat + "<br>" +
                                            "Lon: " + lon + "<br>" +
                                            "⏰ " + fecha;

                            if (!markers[usuario]) {
                                markers[usuario] = L.marker([lat, lon]).addTo(map).bindPopup(popupText);
                            } else {
                                markers[usuario].setLatLng([lat, lon]).setPopupContent(popupText);
                            }

                            var coords = historial.map(p => [p.latitud, p.longitud]);
                            if (!polylines[usuario]) {
                                polylines[usuario] = L.polyline(coords, {color: color, weight: 3}).addTo(map);
                                lineVisible[usuario] = true;
                            } else {
                                polylines[usuario].setLatLngs(coords);
                                if (!lineVisible[usuario]) map.removeLayer(polylines[usuario]);
                            }

                            bounds.push([lat, lon]);
                        }

                        document.getElementById("infoUsuarios").innerHTML = infoHtml;
                        document.getElementById("botones").innerHTML = botonesHtml;

                        if (firstFit && bounds.length > 0) {
                            map.fitBounds(bounds);
                            firstFit = false;
                        }
                    })
                    .catch(error => console.error("Error al actualizar:", error));
            }

            setInterval(actualizarUbicaciones, 1000);
            actualizarUbicaciones();
        </script>
    <?php else: ?>
        <h3>❌ No se ha recibido ninguna ubicación todavía</h3>
    <?php endif; ?>
</body>
</html>
