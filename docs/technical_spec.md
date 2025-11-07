---

## 📘 Datei: `docs/technical_spec.md`

```markdown
# Technische Spezifikation – Ecomm Agent

## 1. Architekturüberblick

**Frontend:**  
- HTML5, Vanilla JS, CSS  
- Live-Polling, History-Sidebar, sanfte Bild-Animationen (Fade-In)

**Backend:**  
- PHP 8.2  
- MySQL / MariaDB  
- n8n-Webhook-Kommunikation über HTTP (JSON & Multipart)

**Hauptfluss:**
1. Nutzer lädt ein Bild hoch (`upload.php`)
2. Anwendung erstellt neuen Run (`workflow_runs`)
3. Anwendung sendet Bild + user_id + run_id an n8n
4. n8n verarbeitet Bild in mehreren Schritten  
   → sendet Textdaten (receiver.php)  
   → sendet Bilder (webhook_image.php)
5. Anwendung speichert Daten pro run_id  
6. Frontend pollt API und zeigt Live-Ergebnisse an

---

## 2. Datenbankstruktur

| Tabelle           | Zweck |
|-------------------|-------|
| `users`           | Registrierung, Login |
| `workflow_runs`   | Jeder n8n-Durchlauf, inkl. Zeitstempel & Status |
| `item_notes`      | Artikeldaten: Name, Beschreibung, Quelle |
| `item_images`     | Zugehörige Bilder pro run_id |
| `user_state`      | Letzter bekannter Workflow-Status je User |

**Beziehung:**

users 1─∞ workflow_runs 1─∞ item_notes
│
└──∞ item_images


---

## 3. Server-Endpunkte

| Datei | Beschreibung |
|-------|---------------|
| **upload.php** | Startet neuen Run, sendet Bild + user_id + run_id an n8n |
| **receiver.php** | Empfängt n8n-Callbacks mit Artikeldaten (Name, Beschreibung, Status) |
| **webhook_image.php** | Empfängt Bilddateien von n8n und legt sie im korrekten Pfad `/uploads/{user_id}/{run_id}/` ab |
| **api/get-latest-item.php** | Liefert aktuellen Run (item_notes + item_images) für Polling |
| **api/get-history.php** | Liefert alle abgeschlossenen Runs eines Users |

---

## 4. Frontend-Komponenten

| Komponente | Aufgabe |
|-------------|----------|
| **Upload-Bereich** | Bild auswählen → Upload starten |
| **Live-Statusanzeige** | Zeigt aktuellen Workflow-Fortschritt |
| **Image-Container** | Zeigt eingehende oder gespeicherte Bilder mit Fade-In-Effekt |
| **History-Sidebar** | Klickbare Liste vergangener Runs mit Datum + Artikelnamen |

---

## 5. Fade-In-Animation (neu)

**Ziel:** Sanftes Einblenden aller Bilder (live & aus Verlauf)

**CSS:**
```css
.fade-in {
  opacity: 0;
  transform: translateY(4px);
  transition: opacity 0.25s ease-out, transform 0.25s ease-out;
}
.fade-in.is-visible {
  opacity: 1;
  transform: translateY(0);
}

JavaScript-Hilfsfunktion (script.js):

function attachFadeIn(imgEl) {
  if (!imgEl) return;
  imgEl.classList.add('fade-in');
  if (imgEl.complete) {
    requestAnimationFrame(() => imgEl.classList.add('is-visible'));
  } else {
    imgEl.addEventListener('load', () => imgEl.classList.add('is-visible'), { once: true });
  }
}

Verwendung:
Bei jedem dynamisch erzeugten <img>:

const img = document.createElement('img');
img.src = imageUrl;
container.appendChild(img);
attachFadeIn(img);

6. n8n-Kommunikation

    Upload (Client → n8n):
    → POST multipart/form-data mit:
    { user_id, run_id, file }

    n8n → receiver.php:
    JSON z. B.:

{
  "produktname": "Weite High-Waist Jeans Hellblau mit Fransensaum",
  "produktbeschreibung": "...",
  "statusmessage": "Name und Beschreibung erfolgreich erstellt",
  "user_id": 2,
  "run_id": 43,
  "isrunning": true
}

n8n → webhook_image.php:
multipart/form-data mit { user_id, run_id, file }

n8n → receiver.php (final):

    { "user_id": 2, "run_id": 43, "isrunning": false }

7. Ablauf pro Workflow

    Upload → neuer workflow_runs-Eintrag

    n8n erhält user_id + run_id

    n8n sendet mehrere Antworten an receiver.php und webhook_image.php

    receiver.php legt/aktualisiert item_notes

    webhook_image.php legt Bilder unter /uploads/{user_id}/{run_id}/ ab

    Wenn isrunning:false → Run auf „finished“ gesetzt

    Frontend zeigt Ergebnisse → Bilder + Texte → Fade-in

8. Sicherheits- & Designrichtlinien

    Datei-Upload: MIME-Type-Check, max. Größe 20 MB

    Prepared Statements in allen SQL-Abfragen

    Keine clientseitige Speicherung sensibler Tokens

    Isolierung pro user_id (kein Zugriff auf fremde Runs)

9. Letzte Änderungen (Version 1.2)

    Einführung eindeutiger run_id pro Workflow

    Deutsche Feldnamen (produktname, produktbeschreibung) unterstützt

    receiver.php prüft run_id vor Update, legt Run bei Bedarf an

    404-Fehlerbehandlung korrigiert (kein Fehler bei „rowCount() = 0“)

    CSS-basierter Fade-In-Effekt für alle Bilder

    Frontend-Code refaktoriert für DOM-basierte Renderlogik

    user_state bleibt erhalten, wird aber nicht mehr zur run_id-Ermittlung genutzt

10. Ausblick

    🔄 WebSocket-Unterstützung statt Polling

    🧾 Exportfunktion (PDF / CSV) pro Run

    🧠 Optionale AI-Modelle für semantische Kategorisierung

    🎨 UI-Theming (Dark / Light)