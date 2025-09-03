<?php
header("Content-Type: text/html; charset=UTF-8");

// Ruta del archivo donde guardaremos la última ubicación
$filePath = __DIR__ . "/ultima_ubicacion.json";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validar que vengan lat y lon
    if (isset($_POST['lat']) && isset($_POST['lon'])) {
        $lat = filter_var($_POST['lat'], FILTER_VALIDATE_FLOAT);
        $lon = filter_var($_POST['lon'], FILTER_VALIDATE_FLOAT);

        if ($lat !== false && $lon !== false) {
            // Guardar en JSON
            $data = [
                "latitud" => $lat,
                "longitud" => $lon,
                "fecha" => date("Y-m-d H:i:s")
            ];
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));

            // Mostrar resultado
            echo "<h2>✅ Ubicación recibida</h2>";
            echo "<p><strong>Latitud:</strong> $lat</p>";
            echo "<p><strong>Longitud:</strong> $lon</p>";
            echo "<p><em>Fecha:</em> " . $data["fecha"] . "</p>";
        } else {
            echo "<h2>❌ Coordenadas inválidas</h2>";
        }
    } else {
        echo "<h2>⚠️ No se recibieron coordenadas</h2>";
    }
} else {
    // Si se abre en el navegador, mostrar la última ubicación guardada
    if (file_exists($filePath)) {
        $data = json_decode(file_get_contents($filePath), true);
        echo "<h2>📍 Última ubicación recibida</h2>";
        echo "<p><strong>Latitud:</strong> " . $data["latitud"] . "</p>";
        echo "<p><strong>Longitud:</strong> " . $data["longitud"] . "</p>";
        echo "<p><em>Fecha:</em> " . $data["fecha"] . "</p>";
    } else {
        echo "<h2>❌ Aún no se recibió ninguna ubicación</h2>";
    }
}
?>
