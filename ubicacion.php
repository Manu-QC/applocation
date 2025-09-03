<?php
header("Content-Type: text/html; charset=UTF-8");

// Archivo donde guardamos la última ubicación
$filePath = __DIR__ . "/ultima_ubicacion.json";

$lat = null;
$lon = null;
$fecha = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['lat']) && isset($_POST['lon'])) {
        $lat = filter_var($_POST['lat'], FILTER_VALIDATE_FLOAT);
        $lon = filter_var($_POST['lon'], FILTER_VALIDATE_FLOAT);

        if ($lat !== false && $lon !== false) {
            $fecha = date("Y-m-d H:i:s");

            // Guardar en JSON
            $data = [
                "latitud" => $lat,
                "longitud" => $lon,
                "fecha" => $fecha
            ];
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
        }
    }
} elseif (file_exists($filePath)) {
    $data = json_decode(file_get_contents($filePath), true);
    $lat = $data["latitud"];
    $lon = $data["longitud"];
    $fecha = $data["fecha"];
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
        <p><strong>Latitud:</strong> <?= htmlspecialchars($lat) ?></p>
        <p><strong>Longitud:</strong> <?= htmlspecialchars($lon) ?></p>
        <p><em>Fecha:</em> <?= htmlspecialchars($fecha) ?></p>

        <div id="map"></div>

        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            var map = L.map('map').setView([<?= $lat ?>, <?= $lon ?>], 15);

            // Cargar mapa de OpenStreetMap
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            // Agregar marcador
            L.marker([<?= $lat ?>, <?= $lon ?>]).addTo(map)
                .bindPopup("Última ubicación<br>Lat: <?= $lat ?><br>Lon: <?= $lon ?>")
                .openPopup();
        </script>
    <?php else: ?>
        <h2>❌ No se ha recibido ninguna ubicación todavía</h2>
    <?php endif; ?>
</body>
</html>
