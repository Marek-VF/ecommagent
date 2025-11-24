# Ecomm Agent  
PHP 8.2 · MySQL 8.4 · n8n Integration

Ecomm Agent ist eine Multi-User Webanwendung zur automatisierten Generierung von
Produktdaten und KI-Bildern. Benutzer laden ein oder zwei Ausgangsbilder hoch,
starten einen n8n-Workflow und erhalten anschließend automatisch generierte
Produkttexte und Bilder zurück.  

Die Anwendung verwaltet Uploads, Workflow-Runs, Statusmeldungen und eine
komplette Historie je Benutzer.

---

## 🚀 Features

- Multi-User Login/Registrierung (Sessions, PHPMailer)
- Upload von bis zu **zwei Originalbildern pro Run**
- Übergabe der Run-Daten an einen externen n8n-Workflow
- Rückkanäle für:
  - Produktname, Beschreibung, Status
  - generierte KI-Bilder
- Live-Polling (alle 2 Sekunden)
- Verlaufs-Sidebar mit vollständiger Run-Historie
- Dark-Theme Frontend, dynamische Bildslots
- Settings-Seite inkl. Bildratio-Präferenz (`image_ratio_preference`)

---

## 🏗 Architekturüberblick

Frontend (index.php + script.js)
⇄ API (get-latest-item, get-runs, get-run-details)
⇄ upload.php / start-workflow.php
⇄ receiver.php / webhook_image.php
⇄ MySQL 8.4
⇄ n8n (Workflow-Webhook + Rückkanäle)


Weitere Details findest du in der vollständigen technischen Spezifikation:  
**`docs/technical_spec.txt`**

---

## 📦 Installation

### 1. Repository klonen

```bash
git clone <repo-url>
cd ecommagent

2. Composer installieren (optional, falls PHPMailer nicht enthalten ist)

composer install

3. config.php einrichten

Kopiere ggf. config.example.php:

cp config.example.php config.php

Wichtige Parameter:

$base_url = "https://example.com/ecommagent";
$upload_dir = __DIR__ . "/uploads/";
$workflow_webhook = "https://n8n.example.com/webhook/start";

$receiver_api_token = "YOUR-SECURE-TOKEN";
$receiver_api_allowed_ips = ["YOUR_N8N_IP"]; // optional

4. Datenbank importieren

Die Datei befindet sich hier:

/mnt/data/import.sql

Import:

mysql -u <user> -p <database> < import.sql

5. Schreibrechte setzen

chmod -R 775 uploads/

Oder je nach Hosting:

chown -R www-data:www-data uploads/

6. Webserver konfigurieren

    Apache mit aktiviertem mod_rewrite

    PHP ≥ 8.2 (PDO, GD, mbstring empfohlen)

🎛 Erster Start

    Aufruf der URL im Browser

    Registrierung über /auth/register.php

    Login und Upload von bis zu zwei Bildern

    Workflow über „Starten“-Button auslösen

    n8n erledigt den Rest – Status & Ergebnisse erscheinen automatisch

🧩 API Endpoints (Auszug)
Endpoint	Methode	Beschreibung
/api/get-latest-item.php	GET	Aggregiert Run + Text + generierte Bilder
/api/get-runs.php	GET	Übersicht aller Runs eines Users
/api/get-run-details.php	GET	Details eines spezifischen Runs
/upload.php	POST	Erstellt neuen Run + speichert Originalbilder
/start-workflow.php	POST	Startet den n8n-Workflow
/receiver.php	POST	Nimmt JSON-Daten von n8n entgegen
/webhook_image.php	POST	Nimmt generierte Bilder von n8n entgegen

Alle Endpoints außer Webhooks sind session-geschützt.
🔐 Sicherheit

    Password Hashing (password_hash)

    Session-basierte Auth

    CSRF-sichere POST-Formulare

    Webhook-Schutz:

        Bearer-Token

        optional: IP Whitelist (receiver_api_allowed_ips)

    PDO Prepared Statements für alle Datenbankoperationen

🧱 Datenbankmodell

Wichtige Tabellen:

    users – Benutzerkonten

    user_state – letzter Status je User (für Polling optimiert)

    workflow_runs – jeder Workflow-Durchlauf

    run_images – Originalbilder (User-Uploads)

    item_notes – Produktname/Beschreibung (aus n8n)

    item_images – generierte Bilder

    status_logs – Status-/Fehlerprotokoll

DB-Schema:
➡️ /mnt/data/import.sql
🔄 Workflow-Ablauf

    Upload → run_images

    Neuer Run in workflow_runs

    Start via start-workflow.php

    Übergabe an n8n

    Rückkanal (Produktdaten) → receiver.php

    Rückkanal (Bilder) → webhook_image.php

    UI-Polling → get-latest-item.php

    Historie → get-runs.php + get-run-details.php

🛠 Entwicklung
Lokales Debugging

    XAMPP / MAMP / Laragon geeignet

    Zeitzone & Error Reporting in php.ini aktivieren

    Browser-Konsole zeigt Statuswechsel (Polling)

Änderungen entwickeln

    Upload-Handler → upload.php

    Workflow-Start → start-workflow.php

    Rückkanäle → receiver.php & webhook_image.php

    Frontend → index.php + script.js

    Styles → style.css

📄 Dokumentation

Alle technischen Details stehen hier:
➡️ docs/technical_spec.txt