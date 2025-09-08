<?php
header("Content-Type: text/html; charset=UTF-8");

$filePath = __DIR__ . "/ubicaciones.json";
$logPath  = __DIR__ . "/log.txt";

// 📏 Distancia Haversine
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

// JSON seguro
function readJsonFile($path) {
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    return $data;
}

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

        // Hora desde dispositivo
        if (isset($_POST['hora_ms'])) {
            $hora_ms = (int) $_POST['hora_ms'];
        } elseif (isset($_POST['hora'])) {
            $hora_ms = (int) (strtotime($_POST['hora']) * 1000);
        } else {
            $hora_ms = (int) round(microtime(true) * 1000);
        }

        $accuracy = isset($_POST['accuracy']) ? (float) $_POST['accuracy'] : null;

        $data = readJsonFile($filePath);
        if (!isset($data[$usuario]) || !is_array($data[$usuario])) {
            $data[$usuario] = [];
        }

        // 🚦 Filtros
        $minMove      = 3.0;   // movimientos <3m ignorados
        $maxAccuracy  = 3.0;   // descartar precisión peor a 3m
        $maxVelocidad = 5.0;   // descartar >5 m/s
        $minDeltaT    = 5.0;   // intervalo mínimo 5s

        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            exit("⚠️ Punto descartado (precisión {$accuracy}m)");
        }

        if (!empty($data[$usuario])) {
            $ultimo = end($data[$usuario]);
            $lastLat = (float) ($ultimo['latitud'] ?? 0);
            $lastLon = (float) ($ultimo['longitud'] ?? 0);
            $lastFechaMs = isset($ultimo['fecha_ms']) ? (int) $ultimo['fecha_ms'] : $hora_ms - 1000;

            $distancia = distanciaMetros($lastLat, $lastLon, $lat, $lon);
            $deltaT = max(0.1, ($hora_ms - $lastFechaMs) / 1000.0);
            $velocidad = $distancia / $deltaT;

            if ($deltaT < $minDeltaT) {
                exit("⏳ Intervalo menor a {$minDeltaT}s ignorado");
            }
            if ($distancia < $minMove) {
                exit("ℹ️ Movimiento menor a {$minMove}m ignorado");
            }
            if ($velocidad > $maxVelocidad) {
                exit("⚠️ Movimiento incoherente ({$velocidad} m/s)");
            }
        }

        // ✅ Guardar
        $nuevoPunto = [
            "latitud"  => $lat,
            "longitud" => $lon,
            "accuracy" => $accuracy,
            "fecha_ms" => $hora_ms,
            "fecha"    => gmdate("Y-m-d H:i:s", (int)($hora_ms / 1000))
        ];

        // 🔧 Filtro de suavizado (EMA)
        if (!empty($data[$usuario])) {
            $alpha = 0.4;
            $ultimo = end($data[$usuario]);
            $nuevoPunto["latitud"]  = $alpha * $lat + (1-$alpha) * $ultimo["latitud"];
            $nuevoPunto["longitud"] = $alpha * $lon + (1-$alpha) * $ultimo["longitud"];
        }

        $data[$usuario][] = $nuevoPunto;

        if (count($data[$usuario]) > 1000000) {
            $data[$usuario] = array_slice($data[$usuario], -1000000);
        }

        writeJsonFileAtomic($filePath, $data);
        exit("✅ Ubicación guardada de $usuario");
    } else {
        exit("❌ Faltan parámetros (lat, lon)");
    }
}

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
        .filtros { margin: 20px; }
    </style>
</head>
<body>
    <h2>📍 Ubicaciones en Tiempo Real</h2>

    <div class="datos">
        <strong>👤 Nombre:</strong> Manuel Eduardo Quispe Condori<br>
        <strong>🎓 Código:</strong> 200858<br>
        <strong>🏫 Universidad:</strong> UNSAAC
    </div>

    <div class="filtros">
        <label>Desde (fecha): <input type="date" id="fechaDesde"></label>
        <label>Hora: <input type="time" id="horaDesde"></label><br><br>
        <label>Hasta (fecha): <input type="date" id="fechaHasta"></label>
        <label>Hora: <input type="time" id="horaHasta"></label><br><br>
        <button onclick="actualizarUbicaciones()">📌 Filtrar</button>
        <button onclick="limpiarFiltros()">📌 Mostrar Todo</button>
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
            var colores = ["#ff4c4c","#4cff4c","#4c4cff","#ffcc00","#ff66ff","#00ffff","#ffa500","#00ffcc","#8A2BE2","#FF4500"];
            var firstFit = true;
            var fechaDesde = null, fechaHasta = null;

            function limpiarFiltros() {
                document.getElementById("fechaDesde").value = "";
                document.getElementById("horaDesde").value = "";
                document.getElementById("fechaHasta").value = "";
                document.getElementById("horaHasta").value = "";
                fechaDesde = null; fechaHasta = null;
                actualizarUbicaciones();
            }

            function combinarFechaHora(f,h){
                if(!f) return null;
                let fecha = new Date(f);
                if(h){
                    let partes = h.split(":");
                    fecha.setHours(partes[0], partes[1]);
                }
                return fecha.getTime();
            }

            function getColor(usuario){
                if(!(usuario in colorIndex)){
                    var idx = Object.keys(colorIndex).length;
                    colorIndex[usuario] = idx;
                }
                return colores[colorIndex[usuario] % colores.length];
            }

            function toggleLinea(usuario){
                if(polylines[usuario]){
                    if(lineVisible[usuario]){
                        map.removeLayer(polylines[usuario]);
                        lineVisible[usuario] = false;
                    } else {
                        polylines[usuario].addTo(map);
                        lineVisible[usuario] = true;
                    }
                }
            }

            function actualizarUbicaciones(){
                fechaDesde = combinarFechaHora(
                    document.getElementById("fechaDesde").value,
                    document.getElementById("horaDesde").value
                );
                fechaHasta = combinarFechaHora(
                    document.getElementById("fechaHasta").value,
                    document.getElementById("horaHasta").value
                );

                fetch("ubicaciones.json?nocache="+new Date().getTime())
                .then(r=>r.json())
                .then(data=>{
                    var bounds=[], infoHtml="<h3>📌 Posiciones filtradas</h3>", botonesHtml="";
                    for(var usuario in data){
                        var historial=data[usuario];
                        if(!historial||historial.length===0) continue;

                        var filtrados=historial.filter(function(p){
                            var t=p.fecha_ms?parseInt(p.fecha_ms):Date.parse(p.fecha);
                            if(fechaDesde && t<fechaDesde) return false;
                            if(fechaHasta && t>fechaHasta) return false;
                            return true;
                        });
                        if(filtrados.length===0) continue;

                        var ultimo=filtrados[filtrados.length-1];
                        var lat=parseFloat(ultimo.latitud), lon=parseFloat(ultimo.longitud);
                        var color=getColor(usuario);
                        var fechaLocal=new Date(parseInt(ultimo.fecha_ms)).toLocaleString();

                        infoHtml+="<p><strong>👤 Usuario:</strong> "+usuario+
                            "<br>🌍 Lat: "+lat+
                            "<br>🌍 Lon: "+lon+
                            (ultimo.accuracy?("<br>📏 Error: "+ultimo.accuracy+" m"):"")+
                            "<br>⏰ Fecha: "+fechaLocal+"</p>";

                        botonesHtml+="<button class='boton' style='background:"+color+"' onclick=\"toggleLinea('"+usuario+"')\">👁 "+usuario+"</button>";

                        var popupText="👤 Usuario: "+usuario+"<br>"+
                            "Lat: "+lat+"<br>"+
                            "Lon: "+lon+"<br>"+
                            (ultimo.accuracy?("Error: "+ultimo.accuracy+" m<br>"):"")+
                            "⏰ "+fechaLocal;

                        if(!markers[usuario]){
                            markers[usuario]=L.marker([lat,lon],{title:usuario}).addTo(map).bindPopup(popupText);
                        } else {
                            markers[usuario].setLatLng([lat,lon]).setPopupContent(popupText);
                        }

                        var coords=filtrados.map(function(p){return [parseFloat(p.latitud),parseFloat(p.longitud)];});
                        if(!polylines[usuario]){
                            polylines[usuario]=L.polyline(coords,{color:color,weight:3}).addTo(map);
                            lineVisible[usuario]=true;
                        } else {
                            polylines[usuario].setLatLngs(coords);
                            if(!lineVisible[usuario]) map.removeLayer(polylines[usuario]);
                        }
                        bounds.push([lat,lon]);
                    }
                    document.getElementById("infoUsuarios").innerHTML=infoHtml;
                    document.getElementById("botones").innerHTML=botonesHtml;
                    if(firstFit&&bounds.length){
                        try{map.fitBounds(bounds);}catch(e){}
                        firstFit=false;
                    }
                });
            }
            setInterval(actualizarUbicaciones,5000);
            actualizarUbicaciones();
        </script>
    <?php else: ?>
        <h3>❌ No se ha recibido ninguna ubicación todavía</h3>
    <?php endif; ?>
</body>
</html>
