from flask import Flask, request, jsonify, render_template_string
from flask_cors import CORS

app = Flask(__name__)
CORS(app)  # Habilita CORS para que Android pueda enviar POST sin problemas

# Última ubicación recibida
last_location = {"lat": 0, "lng": 0, "ts": 0}

# Endpoint para recibir la ubicación desde Android
@app.route("/location", methods=["POST"])
def location():
    global last_location
    data = request.json
    print("Datos recibidos:", data)  # Ver en consola
    last_location = data
    return jsonify({"status": "ok", "received": data})

# Página HTML para ver la ubicación en tiempo real
@app.route("/")
def index():
    return render_template_string("""
    <!DOCTYPE html>
    <html>
    <head>
        <title>Ubicación en tiempo real</title>
        <meta charset="UTF-8">
    </head>
    <body>
        <h1>Ubicación actual</h1>
        <div id="coords">Esperando datos...</div>
        <script>
            async function update() {
                const res = await fetch('/last_location');
                const data = await res.json();
                document.getElementById('coords').innerText =
                    "Lat: " + data.lat + " | Lng: " + data.lng + "\\nTS: " + data.ts;
            }
            setInterval(update, 3000); // Actualiza cada 3 segundos
        </script>
    </body>
    </html>
    """)

# Endpoint que devuelve la última ubicación
@app.route("/last_location")
def last_location_api():
    return jsonify(last_location)

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
