# Ecomm Agent

**Ecomm Agent** ist eine webbasierte PHP-Anwendung zur automatisierten Artikel- und Bildverarbeitung über einen externen **n8n-Workflow**.  
Die Anwendung ermöglicht es Benutzern, ein Bild hochzuladen, dieses an n8n zu senden und die zeitversetzt zurückgelieferten Informationen (z. B. Artikeltitel, Beschreibung, generierte Bilder) zu empfangen, zu speichern und über eine History-Funktion wieder anzuzeigen.  
Sie ist **multi-user-fähig** und verfügt über ein integriertes Registrierungs- und Login-System.

---

## 🚀 Funktionsübersicht

- 📤 **Upload an n8n:** sendet Bild + user_id an einen definierten n8n-WebHook  
- 🔄 **Rückkanal:** empfängt asynchrone Antworten (JSON + Bilder) von n8n  
- 💾 **Persistenz:** speichert Artikeldaten, generierte Bilder und Logs in MySQL  
- 👥 **Multi-User:** jeder Benutzer hat eigene Runs, Logs und History  
- 🧩 **API-Endpunkte:** zur Abfrage des aktuellen Status und vergangener Läufe  
- 🧱 **Saubere Architektur:** PHP 8.2 + Vanilla JS + MySQL + PHPMailer  

---

## 📁 Projektstruktur

/v4
├── api/ # API-Endpunkte für eingeloggte Benutzer
│ ├── get-runs.php
│ ├── get-run-details.php
│ └── get-latest-item.php
├── auth/ # Registrierung, Login, Passwort-Reset, Mail
├── assets/ # Platzhaltergrafiken, Icons
├── uploads/ # Hochgeladene und verarbeitete Bilder
├── config.php # Zentrale Konfiguration (DB, Webhooks, SMTP)
├── db.php # PDO-Wrapper
├── index.php # Hauptfrontend
├── init.php # Systeminitialisierung
├── upload.php # Upload-Endpunkt für den Browser
├── receiver.php # JSON-Webhook für n8n
├── webhook_image.php # Bild-Webhook für n8n
├── import.sql # Datenbankschema
├── script.js # Frontend-Logik (Upload, Polling, Sidebar)
├── style.css # UI-Styling
└── README.md # Diese Datei


---

## ⚙️ Installation

### 1. Voraussetzungen
- Apache Webserver (mod_rewrite aktiviert)  
- PHP ≥ 8.2 (mit PDO, OpenSSL, Fileinfo, GD oder Imagick)  
- MySQL ≥ 8.0  
- n8n-Instanz mit konfiguriertem Webhook-Workflow  
- SMTP-Zugangsdaten für Auth-Mails

### 2. Dateien hochladen
Lege alle Dateien (z. B. ins Verzeichnis `/var/www/html/ecommagent/`).

### 3. Datenbank importieren
```bash
mysql -u USERNAME -p ecommagent < import.sql

4. Konfiguration anpassen

Öffne config.php und trage deine Daten ein:

return [
  // Basis-URLs
  'base_url' => 'https://example.com/ecommagent',
  'asset_base_url' => '/assets',

  // Uploads
  'upload_dir' => __DIR__ . '/uploads',

  // n8n Webhook-Ziel
  'workflow_webhook' => 'https://n8n.example.com/webhook/abcd1234',

  // Sicherheit für Rückkanäle
  'receiver_api_token' => 'supersecrettoken',
  'receiver_api_allowed_ips' => ['1.2.3.4', '::1'],

  // Datenbank
  'db' => [
    'dsn' => 'mysql:host=localhost;dbname=ecommagent;charset=utf8mb4',
    'username' => 'root',
    'password' => '',
  ],

  // SMTP für Registrierung / Passwort-Reset
  'smtp' => [
    'host' => 'mail.example.com',
    'port' => 587,
    'auth' => true,
    'username' => 'noreply@example.com',
    'password' => '********',
  ],

  'mail_from' => 'noreply@example.com',
  'mail_from_name' => 'Ecomm Agent',
];

5. Rechte setzen

chmod -R 755 uploads
chmod 644 config.php

6. Zugriff im Browser

Rufe anschließend auf:
👉 https://example.com/ecommagent/
🔄 Ablaufübersicht

    Benutzer lädt ein Bild hoch (upload.php)

    PHP sendet Bild + user_id → n8n Webhook

    n8n verarbeitet das Bild (z. B. Vision, KI, OCR …)

    n8n ruft receiver.php auf (JSON mit Artikeldaten)

    n8n ruft webhook_image.php auf (Bilder, separat)

    Anwendung aktualisiert workflow_runs, item_notes, item_images, status_logs

    Frontend pollt api/get-latest-item.php → Live-Statusanzeige

    Nach Abschluss: Nutzer sieht fertigen Artikel + Bilder

    Alle Läufe sind in der Sidebar abrufbar

🧠 Datenbank-Überblick
Tabelle	Zweck
users	Authentifizierung, Registrierung, Passwort-Reset
workflow_runs	Jeder n8n-Durchlauf eines Benutzers
user_state	Letzter Status pro Benutzer (für Polling)
item_notes	Artikeldaten (Name, Beschreibung)
item_images	Zugehörige Bilder
status_logs	Systemmeldungen, Debug-Infos
🪄 Beispiel n8n-Integration
In n8n (HTTP Request Node → dein Server)

Webhook-URL:

POST https://example.com/ecommagent/receiver.php
Authorization: Bearer supersecrettoken
Content-Type: application/json

Body:

{
  "user_id": 5,
  "product_name": "Beispielartikel",
  "product_description": "Beschreibung automatisch generiert",
  "isrunning": true
}

Für Bilder:

POST https://example.com/ecommagent/webhook_image.php
Authorization: Bearer supersecrettoken
Content-Type: multipart/form-data
file=@/data/output/image1.png
user_id=5

🔐 Sicherheitshinweise

    Rückkanäle (receiver.php, webhook_image.php) sind ausschließlich über receiver_api_token oder IP-Whitelist zugänglich.

    Session-Login schützt alle Benutzer-Endpunkte (index.php, /api/*).

    Uploads liegen außerhalb des Webroots oder werden per .htaccess geschützt.

    Passwörter sind mit PHP password_hash() verschlüsselt.

👨‍💻 Mitwirkende

    Artur Zimner – Konzept, Entwicklung, Architektur

    ChatGPT (GPT-5) – technische Dokumentation & Codex-Prompt