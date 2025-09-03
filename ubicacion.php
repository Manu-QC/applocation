<?php
header("Content-Type: text/html; charset=UTF-8");

// 📍 Archivo donde guardamos todas las ubicaciones
$filePath = __DIR__ . "/ubicaciones.json";

// ✅ Máximo de usuarios permitidos
$MAX_USUARIOS = 2;

// ✅ Si llega una petición POST (desde la app Android), guardamos la ubicación
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $lat = $_POST["lat"] ?? null;
    $lon = $_POST["lon"] ?? null;
    $usuario = $_POST["usuario"] ?? "desconocido"; // ID del dispositivo

    if ($lat && $lon) {
        // Si ya existe archivo, lo leemos, sino creamos uno nuevo
        $data = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];

        // 🚫 Limitar a máximo 2 usuarios
        if (!isset($data[$usuario]) && count($data) >= $MAX_USUARIOS) {
            echo "❌ Límite de $MAX_USUARIOS usuarios alcanzado";
            exit;
        }

        // Inicializar historial para el usuario si no existe
        if (!isset($data[$usuario])) {
            $data[$usuario] = [];
        }

        // Agregar nueva ubicación al historial
        $data[$usuario][] = [
            "latitud" => $lat,
            "longitud" => $lon,
            "fecha" => date("Y-m-d H:i:s")
        ];

        // Mantener solo las últimas 500 posiciones por usuario
        if (count($data[$usuario]) > 500) {
            $data[$usuario] = array_slice($data[$usuario], -500);
        }

        // Guardamos en el archivo
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "✅ Ubicación guardada de $usuario";
    } else {
        echo "❌ Faltan parámetros (lat, lon)";
    }
    exit;
}

// ✅ Si no es POST, cargamos las ubicaciones
$ubicaciones = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ubicaciones en Tiempo Real</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        body { font-family: Arial, sans-serif; text-align: center; margin: 20px; }
        #map { height: 400px; width: 100%; margin-top: 20px; border: 2px solid #333; }
        .datos { margin-bottom: 15px; padding: 10px; border: 2px solid #555; display: inline-block; background: #f9f9f9; }
        .info-usuarios { margin-top: 20px; text-align: left; display: inline-block; padding: 10px; border: 1px solid #aaa; background: #fafafa; }
    </style>
</head>
<body>
    <h2>📍 Ubicaciones en Tiempo Real</h2>

    <!-- 👤 Tus datos personales siempre visibles -->
    <div class="datos">
        <strong>👤 Nombre:</strong> Manuel Eduardo Quispe Condori<br>
        <strong>🎓 Código:</strong> 200858<br>
        <strong>🏫 Universidad:</strong> UNSAAC
    </div>

    <?php if (!empty($ubicaciones)): ?>
        <div id="map"></div>

        <!-- Info en texto de cada usuario -->
        <div class="info-usuarios" id="infoUsuarios">
            <h3>📌 Últimas posiciones recibidas</h3>
            <p>Cargando...</p>
        </div>

        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            var map = L.map('map').setView([0, 0], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            var markers = {};
            var polylines = {};
            var colores = ["red", "blue"]; // 🎨 Solo dos colores (máx 2 usuarios)
            var colorIndex = {};

            function getColor(usuario) {
                if (!colorIndex[usuario]) {
                    var index = Object.keys(colorIndex).length % colores.length;
                    colorIndex[usuario] = colores[index];
                }
                return colorIndex[usuario];
            }

            function actualizarUbicaciones() {
                fetch("ubicaciones.json?nocache=" + new Date().getTime())
                    .then(response => response.json())
                    .then(data => {
                        var bounds = [];
                        var infoHtml = "<h3>📌 Últimas posiciones recibidas</h3>";

                        var count = 0;
                        for (var usuario in data) {
                            if (count >= 2) break; // 🚫 Máximo 2 usuarios
                            count++;

                            var historial = data[usuario];
                            if (historial.length === 0) continue;

                            var ultimo = historial[historial.length - 1];
                            var lat = ultimo.latitud;
                            var lon = ultimo.longitud;
                            var fecha = ultimo.fecha;
                            var color = getColor(usuario);

                            // Texto fijo con coordenadas y fecha de cada usuario
                            infoHtml += "<p><strong>👤 Usuario:</strong> " + usuario +
                                        "<br>🌍 Lat: " + lat +
                                        "<br>🌍 Lon: " + lon +
                                        "<br>⏰ Fecha: " + fecha + "</p>";

                            // Crear o actualizar marcador
                            var popupText = "👤 Usuario: " + usuario + "<br>" +
                                            "Lat: " + lat + "<br>" +
                                            "Lon: " + lon + "<br>" +
                                            "⏰ " + fecha;

                            if (!markers[usuario]) {
                                markers[usuario] = L.marker([lat, lon]).addTo(map).bindPopup(popupText);
                            } else {
                                markers[usuario].setLatLng([lat, lon]).setPopupContent(popupText);
                            }

                            // Crear o actualizar polilínea
                            var coords = historial.map(p => [p.latitud, p.longitud]);
                            if (!polylines[usuario]) {
                                polylines[usuario] = L.polyline(coords, {color: color, weight: 4}).addTo(map);
                            } else {
                                polylines[usuario].setLatLngs(coords);
                            }

                            bounds.push([lat, lon]);
                        }

                        // Mostrar la info en texto
                        document.getElementById("infoUsuarios").innerHTML = infoHtml;

                        // Ajustar mapa
                        if (bounds.length > 0) {
                            map.fitBounds(bounds);
                        }
                    })
                    .catch(error => console.error("Error al actualizar:", error));
            }

            setInterval(actualizarUbicaciones, 1000); // 🔄 cada segundo
            actualizarUbicaciones();
        </script>
    <?php else: ?>
        <h3>❌ No se ha recibido ninguna ubicación todavía</h3>
    <?php endif; ?>
</body>
</html>
