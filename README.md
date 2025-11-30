# Ecomm Agent / Ecom Studio – AI-gestützte E-Commerce Automationsplattform

Backend: PHP 8.2 · MySQL 8.4 · Frontend: HTML/JS (Light Mode v2.0) · Automation: n8n

---

## 1. Überblick

Ecomm Agent (Frontend-Name: **Ecom Studio**) ist eine Multi-User Webanwendung zur automatisierten Erstellung und Verwaltung von:

- Produktbildern (AI-generiert)
- Produkttexten (Artikelname & -beschreibung)
- Analyse-Daten zu hochgeladenen Kleidungsstücken
- Prompt-basierten Bildvarianten

Die Anwendung sitzt zwischen:

- **User** (Upload, Steuerung, Auswertung)
- **Backend (PHP + MySQL)** (Auth, Orchestrierung, Credits, Logging)
- **n8n** (Bildanalyse, Bildgenerierung, Textgenerierung)

Frontend v2.0 bringt:

- Redesign in **Light Mode / Soft UI**
- kombiniertes **Status & Upload Panel**
- interaktive Action-Bar unter jedem generierten Bild
- integriertes **Credit-System** (verbrauchsabhängige Abrechnung)
- robustes Fehlerhandling mit **Error-Platzhalterbild bei Generierungsfehlern**

---

## 2. Kernfeatures

### 🧵 Produktbild-Upload

- Drag & Drop oder Dateiauswahl
- Bildvalidierung (Typ, Größe, Auflösung)
- Speicherung im Verzeichnis `uploads/<user_id>/<run_id>/...`
- Anlegen eines neuen Workflow-Runs in `workflow_runs`
- Anzeige des Originalbildes im rechten Panel

### ⚙️ Workflow-Steuerung

- Button **„Workflow starten“** im rechten Panel (`#workflow-output`)
- Start eines n8n-Workflows mit:
  - `run_id`
  - `user_id`
  - Bild-URLs der Originalbilder
  - `receiver_api_token`
  - User-Einstellungen (z. B. Image Ratio, Kategorie)

Vor dem Start wird geprüft, ob der User **ausreichend Credits** hat  
→ sonst kein Start, Fehlermeldung in der Statusleiste.

### 🧠 AI-Bild- & Textgenerierung (via n8n)

- Garment-Analyse der hochgeladenen Bilder
- Generierung von:
  - Closeups
  - Full-Body-Shots
  - Editorial / Lifestyle-Bildern
  - Produktfoto-Variante
- Textgenerierung:
  - Artikelname
  - Artikelbeschreibung

### 🧩 Prompt-Varianten-System

- User wählt eine **Branche / Kategorie**
- Promptsets pro Kategorie (Tabelle `prompt_variants`)
- n8n ruft `/api/get-prompt-variants.php` auf und erhält:
  - Label
  - LOCATION, LIGHTING, MOOD, SEASON, MODEL_TYPE, MODEL_POSE, VIEW_MODE

### 📡 Status & Laufzeit-Feedback

- **Statusleiste** links vom Workflow-Button:
  - zeigt die letzte Statusmeldung des aktuellen Runs
  - bei laufendem Workflow: animierte Punkte (`"" → "." → ".." → "..." → ""`) im Sekundentakt
- **Status-Widget** im linken Panel:
  - scrollbare Liste von Statusmeldungen (Info/Success/Error)

### 🖼 Bild-Grid & Error-Platzhalter

- 3-Slot Bildgrid (`.generated-grid` mit `.generated-slot`)
- Während die Bilder generiert werden:
  - Preload-Animation auf dem jeweils nächsten Slot
- Bei erfolgreicher Generierung:
  - Bild wird gespeichert und angezeigt
  - Credits werden für den Step belastet
- Bei Fehler (`executed_successfully: false` von n8n):
  - **kein** Credit-Abzug
  - ein Error-Platzhalterbild aus dem Asset-Ordner (z. B. `assets/default-image1.jpg`) wird gespeichert und angezeigt
  - Statusmeldung des Fehlers erscheint in der Statusleiste

### 📝 Textausgabe & Skeletons

- Panels:
  - „Artikelname“
  - „Artikelbeschreibung“
- Verhalten:
  - bevor der Workflow startet: Skeleton sichtbar, **ohne Animation**
  - während Workflow läuft: Skeleton mit `skeleton-shine`-Animation
  - nach erfolgreicher Generierung: Skeleton verschwindet, Text erscheint

### 🕒 Historie (Runs)

- Seitenleiste (History-Sidebar)
- `api/get-runs.php`: Liste vergangener Runs
- `api/get-run-details.php`: lädt Run in die Hauptoberfläche
- Klick auf einen Run:
  - lädt Text, Bilder, Originalbil(d/er) und Status in die UI

### ⚙️ Settings

- Bildformat / Seitenverhältnis (Image Ratio)
- Branche / Kategorie
- Prompt-Labels & Default-Varianten
- **Credits:**
  - Unterseite zeigt aktuellen Kreditkontostand (mit 2 Dezimalstellen)
- In allen Settings-Seiten:
  - **„Zurück“-Button** im Stil des Workflow-Buttons, verlinkt zurück zur Artikelverwaltung

### 💳 Credit-System (Kurzüberblick)

- Credits als Fließkommawerte (z. B. 0.25, 0.5, 1.0)
- Kosten pro Step-Typ in `config.php` konfigurierbar (`credits.steps`)
- Vor dem Workflow-Start:
  - Abgleich benötigter Credits vs. `users.credits_balance`
- Während der n8n-Ausführung:
  - bei `executed_successfully: true` & bekanntem `step_type`:
    - Abbuchung via `charge_credits(...)`
  - bei `executed_successfully: false`:
    - **kein** Credit-Abzug
    - im Bild-Workflow: Platzhalterbild wird gespeichert

Details siehe `docs/TECHNICAL_SPEC.md`, Abschnitt **5. Credit-System**.

---

## 3. Systemarchitektur (High-Level)

```text
Frontend (index.php, style.css, script.js)
    ↓ Upload
upload.php
    ↓
workflow_runs (DB)
    ↓
start-workflow.php  →  n8n Webhook (workflow_webhook)
    ↓
n8n: Analyse & Bild-/Textgenerierung
    ↓
Callbacks:
  - receiver.php       (Status, Texte, Analyse, isrunning)
  - webhook_image.php  (generierte Bilder & Fehlerfälle)
  - api/get-prompt-variants.php (Prompt-Varianten für n8n)
    ↓
DB-Updates:
  - workflow_runs
  - item_notes
  - item_images
  - status_logs
  - user_state
  - credit_transactions
    ↓
Frontend Polling:
  - api/get-latest-item.php (aktueller Run)
  - api/get-runs.php
  - api/get-run-details.php

4. Frontend v2.0 – Kurzüberblick

    Layout: 12-Spalten Grid (.app)

        links: Status + Upload Card

        rechts: Workflow-Output (Originalbilder, Grid, Texte)

    Design:

        Light Mode, Soft UI (Schatten, abgerundete Karten, viel Weißraum)

        Akzentfarbe: Deep Orange für CTAs und Highlights

    Komponenten:

        Combined Status & Upload Panel

        Image Grid + Action Bars (2K/4K/Edit/Play)

        Text-Panels mit Kopieren-Buttons

        History-Sidebar (Runs)

    Animationen:

        Skeleton-Shine für Text während Job läuft

        Preload-Pulse für Bildslots

        Statusbar mit Punkt-Animation

Detaillierte Frontend-Spezifikation in docs/TECHNICAL_SPEC.md, Abschnitt 2.1 und 6.
5. Verzeichnisstruktur (vereinfacht)

/api
    get-runs.php
    get-run-details.php
    get-latest-item.php
    get-prompt-variants.php

/auth
    bootstrap.php
    login.php
    register.php
    verify.php
    reset_password.php
    logout.php
    phpmailer/...

/settings
    image.php
    category.php
    prompt_labels.php
    credits.php
    default_variants.json

/assets
    ...
    default-image1.jpg        (Error-Platzhalterbild)
    placeholder.png           (generische Platzhalter)

/uploads
    <user_id>/<run_id>/...

index.php
script.js
style.css
upload.php
start-workflow.php
receiver.php
webhook_image.php
db.php
config.php
status_logger.php
credits.php
.htaccess

6. API-Endpunkte (Überblick)

    POST /upload.php
    → Bildupload, Run-Erzeugung

    POST /start-workflow.php
    → Workflowstart (inkl. Credit-Check, n8n-Webhook)

    POST /receiver.php
    → n8n-Callbacks für Status, Texte, Analyse

    POST /webhook_image.php
    → n8n-Callbacks für generierte Bilder & Fehlerbilder

    GET /api/get-latest-item.php
    → aktueller Run für Polling

    GET /api/get-runs.php
    → History-Liste

    GET /api/get-run-details.php
    → Details eines Runs

    GET /api/get-prompt-variants.php
    → Prompt-Varianten für n8n

Details zu Payloads & Feldern siehe docs/TECHNICAL_SPEC.md.
7. Datenbankschema (Überblick)

    users

    workflow_runs

    item_notes

    item_images

    status_logs

    prompt_variants

    user_state

    credit_transactions

Vollständiges Schema inkl. Feldbeschreibungen in docs/TECHNICAL_SPEC.md, Abschnitt 4.
8. Installation & Setup

    Repository klonen

    MySQL-DB erstellen und import.sql einspielen

    config.php anpassen:

        base_url, upload_dir, workflow_webhook

        DB-Zugangsdaten

        SMTP-Konfiguration (optional)

        Credit-Konfiguration (credits.enabled, credits.steps)

    Apache mit mod_rewrite konfigurieren

    Anwendung über index.php aufrufen

9. Konfiguration (config.php)

Wichtige Keys (Auszug):

    base_url, asset_base_url, upload_dir

    workflow_webhook

    receiver_api_token, receiver_api_allowed_ips

    db (PDO DSN + Credentials)

    smtp, mail

    credits:

        enabled: bool

        steps: array (Kosten pro step_type wie analysis, image_1, …)

10. Sicherheit

    Vorbereitung für receiver_api_allowed_ips (IP-Whitelist für n8n)

    Bearer-Token für n8n-Callbacks

    Prepared Statements (PDO) für alle DB-Operationen

    Validierung von Uploads (MIME, Größe, Auflösung)

    Credit-Logik schützt vor Workflows ohne ausreichende Credits

11. Entwicklung & Erweiterung

    neue AI-Funktionen:

        als zusätzliche Steps in config.php['credits']['steps']

        mit step_type in n8n-Callbacks

        Credits via charge_credits() abbuchen

    weitere Bildslots:

        Anpassung von gallerySlots in script.js + CSS-Grid

    zusätzliche Frontend-Panels:

        am bestehenden Soft-UI-System orientieren