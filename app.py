from flask import Flask, request, jsonify, render_template_string

app = Flask(__name__)

# Guardaremos la última ubicación recibida
last_location = {"lat": 0, "lng": 0, "ts": 0}

# Endpoint que recibe la ubicación desde Android
@app.route("/location", methods=["POST"])
def location():
    global last_location
    data = request.json
    last_location = data
    return jsonify({"status": "ok", "received": data})

# Página HTML para ver en tiempo real
@app.route("/")
def index():
    return render_template_string("""
    <!DOCTYPE html>
    <html>
    <head>
        <title>Mapa en tiempo real</title>
        <style> #map { height: 100vh; width: 100%; } </style>
        <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_WEB_MAPS_API_KEY"></script>
        <script>
            let map, marker;
            async function fetchLocation() {
                const res = await fetch('/last_location');
                const data = await res.json();
                if (data.lat && data.lng) {
                    const pos = {lat: data.lat, lng: data.lng};
                    if (!marker) {
                        marker = new google.maps.Marker({position: pos, map: map});
                        map.setCenter(pos);
                    } else {
                        marker.setPosition(pos);
                        map.setCenter(pos);
                    }
                }
            }
            function initMap() {
                map = new google.maps.Map(document.getElementById("map"), {
                    zoom: 16,
                    center: {lat: 0, lng: 0}
                });
                setInterval(fetchLocation, 5000); // refresca cada 5 segundos
            }
        </script>
    </head>
    <body onload="initMap()">
        <div id="map"></div>
    </body>
    </html>
    """)

# Endpoint para devolver la última ubicación
@app.route("/last_location", methods=["GET"])
def last_location_api():
    return jsonify(last_location)

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=80)
