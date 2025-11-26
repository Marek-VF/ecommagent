# Ecomm Agent – AI-gestützte E-Commerce Automationsplattform
Backend: PHP 8.2 · Datenbank: MySQL 8.4 · Frontend: HTML/JS · Automation: n8n

---

## Inhaltsverzeichnis
1. Überblick
2. Kernfunktionen
3. Systemarchitektur
4. End-to-End Workflow
5. Verwendete Technologien
6. Dateistruktur
7. API Endpunkte
8. Datenbankschema (vereinfacht)
9. Installation & Setup
10. Konfiguration (`config.php`)
11. Sicherheit
12. Lizenz / Nutzung

---

## 1. Überblick

Ecomm Agent ist eine Multi-User Webanwendung zur automatisierten Erstellung von:

- Produktbildern  
- Produkttexten  
- Analyse-Daten zu hochgeladenen Kleidungsstücken  
- Prompt-basierten Bildvarianten  

Die Anwendung fungiert als Schnittstelle zwischen dem Nutzer und einem externen **n8n-Server**, welcher KI-Funktionen wie Bildgenerierung, Bildanalyse und Textgenerierung ausführt.

---

## 2. Kernfunktionen

### 💠 Produktbild-Upload
Drag & Drop Upload, Validierung, Speicherung, automatischer Workflow-Start.

### 💠 AI-Bildgenerierung (n8n)
- Garment Analysis  
- Closeups  
- Full Body Shots  
- Editorial / Lifestyle Varianten  
- Produktfoto-Variante (weißes Studio)

### 💠 Prompt-Varianten-System
User-abhängige Promptsets basierend auf der gewählten Branche / Kategorie.

### 💠 Echtzeit Status-Updates
Über n8n-Callbacks → Speicherung in `status_logs` → Live-Polling im Frontend.

### 💠 Multi-Image / Single-Image Logik
N8N entscheidet anhand der Webhook Payload, wie viele Images verarbeitet werden.

### 💠 Settings
- Bildformat (Image Ratio)
- Branche / Kategorie
- Prompt-Labels & Defaults

---

## 3. Systemarchitektur

Frontend (index.php, script.js)
↓ Upload
upload.php
↓
workflow_runs DB Entry
↓
start-workflow.php → n8n Webhook (workflow_webhook)
↓
n8n führt KI-Workflows aus
↓
n8n Callback → receiver.php
↓
status_logs, item_images, workflow_runs Updates
↓
Frontend Polling /api/get-run-details.php


Backend = Orchestrierung  
n8n = AI / Automation Engine  
DB = Persistenz + Logging  

---

## 4. End-to-End Workflow

### **1. User lädt Bilder hoch**
- via `upload.php`  
- Speicherung im `/uploads` Ordner  
- Eintrag in `workflow_runs`  
- Rückgabe: `run_id`

### **2. Workflow wird gestartet**
`start-workflow.php` sendet Request an n8n:

```json
{
  "run_id": 123,
  "user_id": 5,
  "image_url_1": "...",
  "image_url_2": "...",
  "receiver_api_token": "..."
}

3. n8n ruft Promptvarianten ab

Über:

/api/get-prompt-variants.php
→ Liefert prompt_category des Users
→ Liefert passende Variants
4. n8n generiert Bilder, Analysen & Texte

    Analyse → garment JSON

    Bildgenerierung (verschiedene Modi)

    Textvarianten

    Produktfoto (für Single-Image Fälle)

5. Callback an Backend

receiver.php erhält z. B.:

{
  "run_id": 123,
  "user_id": 5,
  "statusmeldung": "image_generated",
  "image_url": "https://...",
  "image_base64": "...."
}

Verarbeitung:

    Speichern in item_images

    Status in workflow_runs

    Log in status_logs

6. Frontend Polling

script.js pollt:

/api/get-runs.php
/api/get-run-details.php?run_id=…

7. Darstellung in der UI

Sobald Bilder / Text vorliegen, erscheinen sie im Dashboard.
5. Verwendete Technologien
Bereich	Technologie
Backend	PHP 8.2, PDO, MySQL 8.4
Frontend	HTML5, CSS, JavaScript, Dark Mode
Auth	PHPMailer SMTP
Automation / KI	n8n (Webhook gesteuert)
Hosting	Apache (Rewrite aktiviert)
6. Dateistruktur

/api
    get-runs.php
    get-run-details.php
    get-latest-item.php
    get-prompt-variants.php

/auth
    login.php
    register.php
    verify.php
    reset_password.php
    phpmailer/

/settings
    update_image_settings.php
    update_category.php
    prompt_labels.php
    default_variants.json

/uploads (dynamisch)
/assets (CSS, JS, Icons)

index.php
script.js
style.css
upload.php
start-workflow.php
receiver.php
db.php
config.php
.htaccess

7. API Endpunkte
📌 /upload.php (POST)

Upload eines Originalbildes
→ Erstellt workflow_run
📌 /start-workflow.php (POST)

Startet den n8n AI Workflow.
📌 /receiver.php (POST)

n8n sendet hier alle Statusmeldungen.

Header muss enthalten:
X-API-TOKEN: <receiver_api_token>
📌 /api/get-runs.php (GET)

Liste aller Workflows eines Users.
📌 /api/get-run-details.php (GET)

Detaildaten inkl. Bilder, Log, Text.
📌 /api/get-prompt-variants.php (GET)

Promptvarianten je Kategorie.
8. Datenbankschema (vereinfacht)
users
Feld	Beschreibung
id	Primary Key
email	Login
password_hash	Passwort
verified_at	E-Mail bestätigt
receiver_api_token	Token für n8n
image_ratio_preference	User Ratio
prompt_category_id	Branche
workflow_runs

    id

    user_id

    created_at

    status

    original_image

    last_message

item_images

    id

    user_id

    run_id

    type (closeup/full_body/generated/product)

    image_url

    image_base64

status_logs

    id

    run_id

    message

    created_at

prompt_variants

Promptsets für jede Branche.
9. Installation & Setup
1. Repo klonen

git clone <repo>
cd ecommagent

2. Datenbank importieren

Erstelle DB und importiere die SQL-Datei.
3. config.php anpassen
4. Apache vorbereiten

a2enmod rewrite

5. System bereit

Aufruf über
https://<domain>/index.php
10. Konfiguration (config.php)

Die Konfiguration ist eine Rückgabe eines assoziativen Arrays:

<?php
$baseUrl = 'https://vielfalter.digital/api-monday/ecommagent';
$webhookBearerToken = 'changeme';

return [
    'base_url'         => $baseUrl,
    'asset_base_url'   => $baseUrl . '/assets',
    'upload_dir'       => __DIR__ . '/uploads',

    // n8n Workflow-URL
    'workflow_webhook' => 'https://tex305agency.app.n8n.cloud/webhook-test/9a217ab8-47fa-452c-9c65-fa7874a14fdd',

    // Authentifizierung der Callbacks aus n8n
    'receiver_api_token'      => $webhookBearerToken,
    'receiver_api_allowed_ips' => [],

    // MySQL Datenbank
    'db'               => [
        'dsn'      => 'mysql:host=localhost;dbname=ecommagent;charset=utf8mb4',
        'username' => 'root',
        'password' => '',
        'options'  => [],
    ],

    // SMTP Mailer
    'smtp'             => [
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'username'   => 'smtp-user',
        'password'   => 'smtp-password',
        'encryption' => 'tls',
        'auth'       => true,
    ],

    // Absender
    'mail'             => [
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'Artikelverwaltung',
    ],
];

11. Sicherheit

    Prepared Statements (PDO)

    SMTP Auth

    Passwort-Hashing mit password_hash()

    n8n Callback Token über Header

    Optional: IP-Whitelist für Callbacks

    Datei-Upload Validierung

