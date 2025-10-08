# Moderner Zähler

Eine moderne, deutschsprachige Webanwendung, die eine Zahl mittig auf dem
Bildschirm darstellt. Jeder Klick auf den Button (oder die Betätigung per Enter
bzw. Leertaste, solange der Button fokussiert ist) erhöht den Wert um eins und
spielt eine kleine Hervorhebungsanimation ab.

## Nutzung

1. Öffne die Datei `index.html` direkt im Browser **oder** starte einen lokalen
   HTTP-Server, z. B. mit Python:

   ```bash
   python -m http.server 8000
   ```

2. Rufe anschließend [http://localhost:8000](http://localhost:8000) im Browser
   auf.

Der aktuelle Zählerstand bleibt während der Sitzung erhalten, solange die Seite
nicht neu geladen wird.
