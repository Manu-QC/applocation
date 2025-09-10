<?php
$filePath = __DIR__ . "/ubicaciones.json";

/* --- Funciones auxiliares --- */
function readJsonFile($path) {
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    return $data;
}
function saveJsonFile($path, $data) {
    return file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/* --- API para la app --- */
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "POST") {
    // Detectar si llega como JSON o como form-data
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (!$json && isset($_POST["data"])) {
        $json = json_decode($_POST["data"], true);
    }

    if (!$json || !isset($json["deviceId"])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "msg" => "Falta deviceId o JSON inválido"]);
        exit;
    }

    $ubicaciones = readJsonFile($filePath);
    $id = $json["deviceId"];
    if (!isset($ubicaciones[$id])) $ubicaciones[$id] = [];

    // Asegurar timestamps legibles
    $json["fecha_ms"] = $json["fecha_ms"] ?? round(microtime(true) * 1000);
    $json["fecha"] = date("Y-m-d H:i:s");

    // Guardar
    $ubicaciones[$id][] = $json;

    // Mantener solo últimos 500 registros
    if (count($ubicaciones[$id]) > 500) {
        $ubicaciones[$id] = array_slice($ubicaciones[$id], -500);
    }

    saveJsonFile($filePath, $ubicaciones);
    echo json_encode(["status" => "ok", "msg" => "Ubicación guardada"]);
    exit;
}

if ($method === "DELETE") {
    saveJsonFile($filePath, []);
    echo json_encode(["status" => "ok", "msg" => "Historial borrado"]);
    exit;
}

/* --- Vista web --- */
$ubicaciones = readJsonFile($filePath);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>🌌 Panel Futurista de Ubicaciones</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    body { font-family:"Segoe UI",sans-serif; margin:0; background:radial-gradient(circle at top,#0f2027,#203a43,#2c5364); color:#eee; display:flex; flex-direction:column; height:100vh; }
    h2 { text-align:center; padding:15px; color:#00e5ff; text-shadow:0 0 8px #0ff; margin:0; }
    .container { display:flex; flex:1; }
    #map { height:100%; width:75%; border-radius:12px; box-shadow:0 0 15px #00e5ff88; }
    .sidebar { width:25%; background:#111; overflow-y:auto; padding:15px; box-shadow:0 0 10px #00e5ff55; }
    .boton { margin:5px; padding:8px 12px; border:none; border-radius:8px; font-weight:bold; cursor:pointer; transition:transform .2s, box-shadow .2s; font-size:14px; }
    .boton:hover { transform:scale(1.05); box-shadow:0 0 8px #0ff; }
    .azul { background:#007bff; color:#fff; } .verde { background:#28a745; color:#fff; } .rojo { background:#dc3545; color:#fff; }
    .filtros { margin-bottom:15px; display:flex; flex-direction:column; gap:8px; }
    .filtros input { padding:5px; border-radius:6px; border:1px solid #444; background:#222; color:#eee; width:100%; }
    .usuario-card { border:1px solid #00e5ff55; padding:10px; border-radius:8px; margin:10px 0; background:#1a1a1a; text-align:left; box-shadow:0 0 5px #00e5ff33; font-size:14px; }
    canvas { background:#111; border-radius:10px; padding:10px; margin-top:10px; }
</style>
</head>
<body>
<h2>🌌 Panel Futurista de Ubicaciones y Sensores</h2>

<div class="container">
    <div id="map"></div>
    <div class="sidebar">
        <div class="filtros">
            <label>Desde: <input type="datetime-local" id="fechaInicio"></label>
            <label>Hasta: <input type="datetime-local" id="fechaFin"></label>
            <button class="boton azul" onclick="filtrarDatos()">🔍 Filtrar</button>
            <button class="boton verde" onclick="resetFiltros()">♻ Ver Todo</button>
            <button class="boton rojo" onclick="borrarTodo()">🗑 Borrar Todo</button>
        </div>
        <h3>📊 Gráfico de actividad (pasos por hora)</h3>
        <canvas id="chartActividad" height="120"></canvas>
        <div id="infoUsuarios"><h3>📌 Últimas posiciones</h3><p>Cargando...</p></div>
    </div>
</div>

<?php if (!empty($ubicaciones)): ?>
<script>
var map=L.map('map').setView([0,0],2);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);

var markers={}, polylines={}, datosGlobal={};
var colores=["#00e5ff","#ff4081","#00ff7f","#ffa500","#ff1493","#8a2be2"];

function borrarTodo(){
    if(confirm("¿Seguro que deseas borrar todo el historial?")){
        fetch("<?php echo basename(__FILE__); ?>",{method:"DELETE"}).then(()=>location.reload());
    }
}

function actualizarUbicaciones(){
    return fetch("ubicaciones.json?nocache="+Date.now())
    .then(r=>r.json()).then(data=>{
        datosGlobal=data; renderizar(data);
    });
}

function renderizar(data){
    var infoHtml="", actividadHoras={};
    var fechaIni=document.getElementById("fechaInicio").value;
    var fechaFin=document.getElementById("fechaFin").value;
    var tIni=fechaIni? new Date(fechaIni).getTime():null;
    var tFin=fechaFin? new Date(fechaFin).getTime():null;

    for(var usuario in data){
        var historial=data[usuario]; if(!historial) continue;
        var filtrados=historial.filter(p=>{
            var t=parseInt(p.fecha_ms);
            if(tIni && t<tIni) return false;
            if(tFin && t>tFin) return false;
            return true;
        });
        if(filtrados.length===0) continue;

        var ultimo=filtrados[filtrados.length-1];
        var lat=parseFloat(ultimo.latitud||ultimo.gpsLat), lon=parseFloat(ultimo.longitud||ultimo.gpsLon);
        var fechaLocal=new Date(parseInt(ultimo.fecha_ms)).toLocaleString();

        var popupText=`👤 ${usuario}<br>
        🌍 Lat:${lat}<br>🌍 Lon:${lon}<br>
        ⏰ ${fechaLocal}<br>
        📏 Precisión:${(ultimo.accuracy||"?")} m<br>
        🚶 Pasos:${(ultimo.steps||0)}<br>
        📱 ${(ultimo.fabricante||"")} ${(ultimo.modelo||"")} (Android ${(ultimo.android||"")})<br>
        ⚡ Accel:[${ultimo.accelX},${ultimo.accelY},${ultimo.accelZ}]<br>
        🧭 Azimut:${ultimo.azimut} Pitch:${ultimo.pitch} Roll:${ultimo.roll}`;

        if(!markers[usuario]){
            var color=colores[Object.keys(markers).length%colores.length];
            markers[usuario]=L.marker([lat,lon],{title:usuario}).addTo(map).bindPopup(popupText);
            polylines[usuario]=L.polyline([], {color:color, weight:3}).addTo(map);
        } else {
            markers[usuario].setLatLng([lat,lon]).setPopupContent(popupText);
        }
        polylines[usuario].setLatLngs(filtrados.map(p=>[parseFloat(p.latitud||p.gpsLat),parseFloat(p.longitud||p.gpsLon)]));

        filtrados.forEach(p=>{
            var hora=new Date(parseInt(p.fecha_ms)).getHours();
            actividadHoras[hora]=(actividadHoras[hora]||0)+(p.steps||0);
        });

        infoHtml+=`<div class="usuario-card">
            <strong>👤 Usuario:</strong> ${usuario}<br>
            🌍 Lat:${lat} Lon:${lon}<br>
            🚶 Pasos:${ultimo.steps||0}<br>
            ⚡ Acelerómetro:X:${ultimo.accelX} Y:${ultimo.accelY} Z:${ultimo.accelZ}<br>
            ⏰ ${fechaLocal}<br>
            <button class="boton azul" onclick="centrarUsuario('${usuario}')">🎯 Centrar</button>
        </div>`;
    }
    document.getElementById("infoUsuarios").innerHTML=infoHtml;
    renderChart(actividadHoras);
}

function centrarUsuario(usuario){
    if(markers[usuario]){
        map.setView(markers[usuario].getLatLng(),15);
        markers[usuario].openPopup();
    }
}

function renderChart(actividad){
    var ctx=document.getElementById("chartActividad").getContext("2d");
    var horas=Array.from({length:24},(_,i)=>i);
    var valores=horas.map(h=>actividad[h]||0);
    if(window.grafico) window.grafico.destroy();
    window.grafico=new Chart(ctx,{
        type:"bar",
        data:{ labels:horas.map(h=>h+":00"), datasets:[{label:"Pasos",data:valores,backgroundColor:"#00e5ff"}]},
        options:{scales:{x:{ticks:{color:"#eee"}},y:{ticks:{color:"#eee"}}}}
    });
}

function filtrarDatos(){ renderizar(datosGlobal); }
function resetFiltros(){ document.getElementById("fechaInicio").value=""; document.getElementById("fechaFin").value=""; renderizar(datosGlobal); }

setInterval(actualizarUbicaciones,5000);
actualizarUbicaciones();
</script>
<?php else: ?>
<h3 style="text-align:center">❌ No se ha recibido ninguna ubicación todavía</h3>
<?php endif; ?>
</body>
</html>
