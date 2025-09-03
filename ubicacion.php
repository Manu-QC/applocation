<?php
header("Content-Type: text/html; charset=UTF-8");

// 📍 Archivo donde guardamos la última ubicación
$filePath = __DIR__ . "/ultima_ubicacion.json";

// ✅ Si llega una petición POST (desde la app Android), guardamos la ubicación
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $lat = $_POST["lat"] ?? null;
    $lon = $_POST["lon"] ?? null;

    if ($lat && $lon) {
        $data = [
            "latitud" => $lat,
            "longitud" => $lon,
            "fecha" => date("Y-m-d H:i:s")
        ];
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "✅ Ubicación guardada correctamente";
    } else {
        echo "❌ Faltan parámetros (lat, lon)";
    }
    exit; // salimos aquí, no cargamos el HTML
}

// ✅ Si no es POST, cargamos la vista en HTML
$lat = null;
$lon = null;
$fecha = null;

if (file_exists($filePath)) {
    $data = json_decode(file_get_contents($filePath), true);
    $lat = $data["latitud"] ?? null;
    $lon = $data["longitud"] ?? null;
    $fecha = $data["fecha"] ?? null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ubicación en Tiempo Real</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        body { font-family: Arial, sans-serif; text-align: center; margin: 20px; }
        #map { height: 400px; width: 100%; margin-top: 20px; border: 2px solid #333; }
    </style>
</head>
<body>
    <?php if ($lat !== null && $lon !== null): ?>
        <h2>📍 Última ubicación recibida</h2>
        <p id="coords">
            <strong>Latitud:</strong> <?= htmlspecialchars($lat) ?><br>
            <strong>Longitud:</strong> <?= htmlspecialchars($lon) ?><br>
            <em>Fecha:</em> <?= htmlspecialchars($fecha) ?><br><br>
            <strong>👤 Nombre:</strong> Manuel Eduardo Quispe Condori<br>
            <strong>🎓 Código:</strong> 200858<br>
            <strong>🏫 Universidad:</strong> UNSAAC
        </p>

        <div id="map"></div>

        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            var map = L.map('map').setView([<?= $lat ?>, <?= $lon ?>], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            var marker = L.marker([<?= $lat ?>, <?= $lon ?>]).addTo(map)
                .bindPopup("Última ubicación<br>Lat: <?= $lat ?><br>Lon: <?= $lon ?>")
                .openPopup();

            // 🔄 Actualizar cada 1 segundo
            setInterval(function() {
                fetch("ultima_ubicacion.json?nocache=" + new Date().getTime())
                    .then(response => response.json())
                    .then(data => {
                        var lat = data.latitud;
                        var lon = data.longitud;
                        var fecha = data.fecha;

                        if (!lat || !lon) return;

                        // Actualizar texto
                        document.getElementById("coords").innerHTML =
                            "<strong>Latitud:</strong> " + lat + "<br>" +
                            "<strong>Longitud:</strong> " + lon + "<br>" +
                            "<em>Fecha:</em> " + fecha + "<br><br>" +
                            "<strong>👤 Nombre:</strong> Manuel Eduardo Quispe Condori<br>" +
                            "<strong>🎓 Código:</strong> 200858<br>" +
                            "<strong>🏫 Universidad:</strong> UNSAAC";

                        // Mover marcador sin recrear el mapa
                        marker.setLatLng([lat, lon])
                              .setPopupContent("Última ubicación<br>Lat: " + lat + "<br>Lon: " + lon);

                        // Mantener el mapa centrado
                        map.setView([lat, lon]);
                    })
                    .catch(error => console.error("Error al actualizar:", error));
            }, 1000);
        </script>
    <?php else: ?>
        <h2>❌ No se ha recibido ninguna ubicación todavía</h2>
    <?php endif; ?>
</body>
</html>
