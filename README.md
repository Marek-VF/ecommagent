1.1 Zweck der Anwendung

Ecom Studio ist eine webbasierte PHP-Anwendung zur Unterstützung von E-Commerce-Teams bei der Erstellung von KI-basierten Produktbildern & Texten:

Upload von Produktbildern (z. B. Kleidungsstücke)

Übergabe der Bilder + Metadaten an einen externen n8n-Workflow

Empfang von:

generierten Produktbildern (verschiedene Slots/Varianten)

Produktname & Produktbeschreibung

Statusmeldungen zu den Workflow-Schritten

Speicherung aller Daten in MySQL

Darstellung der Ergebnisse in einer modernen Weboberfläche inkl.:

Statusbereich

History-Sidebar mit bisherigen Runs

Detail-Ansicht für einzelne Runs

Multi-User-fähig inkl. Registrierung, Login, E-Mail-Verifikation & Passwort-Reset

Integriertes Credit-System zur Abrechnung von Workflow-Schritten

Die eigentlichen KI-Schritte (Bildgenerierung, Garment-Analyse, Textgenerierung) laufen in einem n8n-Workflow außerhalb dieser Codebasis.

1.2 Technologie-Stack

Backend: PHP 8.x (mit declare(strict_types=1); in vielen Dateien)

Webserver: Apache oder kompatibler PHP-fähiger Server

Datenbank: MySQL 8.x

Frontend:

PHP-Templates (v. a. index.php, settings/*.php, auth/*.php)

Vanilla JavaScript (script.js)

CSS (style.css, settings/settings.css)

E-Mail: PHPMailer (liegt unter auth/phpmailer)

n8n-Integration: HTTP Webhooks (cURL aus PHP, eingehende Calls von n8n)

1.3 Projektstruktur (High-Level)

Wichtige Verzeichnisse und Dateien:

index.php
Haupt-UI der Anwendung (Dashboard): Upload, Statusanzeige, Galerie, History-Sidebar, Header mit Profil.

script.js
Frontend-Logik: Datei-Upload, Start Workflow, Polling, Rendering von Status & Resultaten, History-Liste.

style.css
Globales Styling der App (Layout, Karten, Sidebar, Buttons, Skeleton-Loader, etc.).

auth/
Vollständiges Usersystem:

bootstrap.php – Session, Hilfsfunktionen (auth_*), PDO-Zugriff

login.php, register.php, verify.php, forgot_password.php, reset_password.php

mail.php – E-Mail-Versand via PHPMailer

phpmailer/ – Bibliothek

settings/
Benutzerbezogene Einstellungen:

index.php – Profil-Einstellungen

profile.php – Profil (Name, E-Mail, Passwort etc.)

industry.php – Branche/Zielkategorie (z. B. Fashion, Interior, etc.)

image.php – Bild-Einstellungen (z. B. gewünschtes Seitenverhältnis)

image_variants.php – Konfiguration der Prompt-Varianten (Location, Lighting, Mood, View Mode …)

credits.php – Anzeige des Credit-Kontostands

default_variants.json – vordefinierte Prompt-Varianten je Kategorie

prompt_defaults.php / prompt_labels.php – Hilfsfunktionen für Default-Varianten & Beschriftungen

update_image_settings.php, update_prompt_variants.php – POST-Endpunkte zur Speicherung der Einstellungsformulare

api/

get-runs.php – Liste der bisherigen Workflow-Runs des eingeloggten Users (für History-Sidebar)

get-run-details.php – Detailinformationen zu einem Run (Texte + Bilder)

get-latest-item.php – “Aktueller Stand” (Status, aktuelles Bild, Texte) – wird regelmäßig gepollt

get-prompt-variants.php – API für n8n (liefert Prompt-Varianten und kann Credits abbuchen)

Workflow-Controller / n8n-Schnittstellen:

upload.php – Entgegennahme von Bilduploads aus dem Frontend vor Workflowstart

start-workflow.php – Start eines n8n-Workflows (Outgoing Request)

receiver.php – n8n-Callback für Status & Textdaten

webhook_image.php – n8n-Callback für generierte Bilder

status_logger.php – gemeinsamer Helper zum Loggen von Statusmeldungen

credits.php – Credit-Logik (Berechnung, Abbuchung, Logging)

Infrastruktur:

config.php – zentrale Konfiguration (Base-URL, Webhook-URL, API-Token, DB, Mail, Credits)

db.php – PDO-Verbindung + Datenbank-Helper

import.sql – vollständiges MySQL-Schema inkl. Tabellen & Indizes

assets/ – statische Assets (Default-Bilder, Platzhalter, Icons)

uploads/ – (beschreibbares) Verzeichnis für hochgeladene und generierte Bilder

1.4 Überblick: User Flow im Frontend

Registration / Login

User registriert sich über auth/register.php.

E-Mail-Verifikation via Link aus auth/mail.php.

Login über auth/login.php, Session wird in auth/bootstrap.php gemanagt.

Dashboard (index.php)

Nach Login: Redirect auf index.php.

UI-Bestandteile:

Header mit App-Titel, Profil/Settings, User-Initialen

Linke Spalte: Statuskarte und Upload-Funktion

Mittlere Spalte: “Originalbild”-Bereich (aktuell/vom Run)

Rechte Spalte: Slots für bis zu 4 generierte Bilder, inkl. Skeleton-/Lade-Animationen

History-Sidebar: Liste bisheriger Runs, öffnet per Button im Header.

Bild-Upload

Drag & Drop oder Datei auswählen über script.js → POST an upload.php.

upload.php:

prüft Session / Auth

validiert Datei (Größe, Typ)

speichert Datei im uploads/…-Verzeichnis (pro User / Run strukturierte Pfade)

legt / aktualisiert einen Eintrag in workflow_runs und run_images (bzw. item_images)

schreibt Status (status_logs_new) via status_logger.php

liefert JSON mit Infos (z. B. run_id, image_url, Statusmeldung)

script.js:

zeigt Vorschaubild(e) an

aktiviert Start-Button (#start-workflow-btn)

aktualisiert Statusbereich mit Meldung “Bereit für Workflow-Start”.

Workflow starten

Klick auf “Workflow starten” → script.js schickt POST (JSON) an start-workflow.php.

start-workflow.php:

liest run_id, optional weitere Parameter aus dem Request

validiert Credits:

berechnet erwartete Kosten (estimate_workflow_credits())

vergleicht mit aktuellem Kontostand (get_user_credits_balance() aus db.php / credits.php)

bei zu wenig Credits: Fehler-Response an Frontend (mit Detailinfos zu required/balance)

liest vorhandene Bilder (Original + evtl. Zuschnittbilder) und Bild-Ratio-Präferenz des Users

baut einen cURL-Request zu config['workflow_webhook']:

multipart/form-data mit:

file (Bild)

optional file_2 (zweites Bild)

image_ratio

run_id, user_id (IDs für spätere Zuordnung)

aktualisiert workflow_runs (status = running, last_message = 'Workflow gestartet')

schreibt user_state (aktiver Run, Status, Message)

Frontend:

setzt “Scan-Overlay” über die Bild-Slots

wechselt Statusanzeige auf “Verarbeitung läuft …”

startet Polling:

api/get-latest-item.php für aktuellen Status

api/get-runs.php für History-Liste

n8n-Callbacks

Status + Texte (receiver.php)

n8n sendet POST mit:

run_id, user_id

Felder wie status, statusmeldung

optional product_name/produktname, product_description/produktbeschreibung

executed_successfully (true/false)

step_type (z. B. analysis, image_1 …)

receiver.php:

prüft Bearer Token in Authorization mit config['receiver_api_token']

validiert run_id und zugehörigen User

extrahiert Statustext (via status_logger.php::extract_status_message)

schreibt:

Eintrag in status_logs_new

ggf. Produktname & Beschreibung in item_notes

workflow_runs.last_message & workflow_runs.status

user_state (last_status, last_message, current_run_id)

wenn executed_successfully === true und step_type gesetzt:

Abbuchung von Credits via charge_credits() (Tabelle credit_transactions)

Response: JSON mit ok, Status, Run-Infos.

Bilder (webhook_image.php)

n8n sendet multipart/form-data POST mit:

run_id, optional user_id

note_id (Bezug zu Item-Notiz)

position (Slot-Index im Frontend)

step_type (für Credits)

executed_successfully (true/false)

file (Bilddatei) oder im Fehlerfall nur Meta-Infos

webhook_image.php:

prüft Bearer-Token (Authorization: Bearer …) gegen config['receiver_api_token']

löst userId (GET/POST/Session)

validiert run_id + user_id Kombination

Fehlerfall (executed_successfully === false):

ermittelt/legt item_notes an

speichert Default-/Error-Platzhalterbild in run_images

loggt Statusmeldung

keine Credit-Abbuchung

Erfolgsfall:

speichert hochgeladenes Bild unter uploads/...

legt Eintrag in run_images an (inkl. position, note_id)

aktualisiert workflow_runs.last_message, workflow_runs.last_step_status = 'success'

aktualisiert user_state.last_image_url

Credit-Abbuchung via charge_credits() (wenn step_type gesetzt)

Prompt-Varianten / n8n-Pull (api/get-prompt-variants.php)

n8n ruft diesen Endpoint mit Header X-API-TOKEN: <receiver_api_token> auf.

Request-Body enthält u. a.:

run_id, user_id

executed_successfully, step_type

optional Status-Meldung

Endpoint:

validiert Token & run_id/user_id

aktualisiert ggf. workflow_runs.last_message + Status

lädt Prompt-Varianten aus prompt_variants (evtl. gefiltert nach prompt_categories des Users)

baut JSON-Liste mit:

variant_slot (1–3)

location, lighting, mood, season, model_type, model_pose, view_mode

category_key

bei executed_successfully === true + step_type:

Credits abbuchen (charge_credits)

Response: JSON mit Variantenliste

Anzeige der Resultate im Frontend

Statusbereich & Live-View:
script.js pollt api/get-latest-item.php und aktualisiert:

Status-Headline + Nachricht

Textfelder Produktname/Beschreibung

aktuelles Originalbild

Flags isrunning (steuert Loader)

Galerie / generierte Bilder:
script.js erhält über get-latest-item.php bzw. get-run-details.php eine Images-Liste und rendert diese in den 4 Slots (inkl. “scan overlay”/Ladeanimation).
Platzhalterbilder aus Fehlerfällen werden wie echte Bilder behandelt, damit der Slot “gefüllt” wirkt.

History-Sidebar (api/get-runs.php + api/get-run-details.php):

get-runs.php liefert Liste vergangener Runs:

id, title, status, dateLabel, isrunning, hasText, hasImages

script.js rendert daraus eine klickbare Liste im Sidebar.

Beim Klick:

loadRunDetails(runId) → get-run-details.php?id=<runId>

Response enthält ausführliche Daten:

Run-Metadaten (Status, Zeitpunkte, letzte Meldung)

ggf. product_name, product_description

original_image

Liste der images mit URLs

applyRunDataToUI() setzt UI auf Zustand dieses Runs (Texte, Bilder, Status).

1.5 Konfiguration und Installation

Code deployen

Projektverzeichnis auf den Webserver kopieren (z. B. /var/www/ecomstudio).

uploads/ und ggf. Unterordner beschreibbar machen (z. B. chmod -R 775).

Datenbank einrichten

Leere DB anlegen (z. B. ecommagent).

import.sql ausführen (z. B. über phpMyAdmin oder CLI mysql).

Dadurch werden Tabellen wie users, workflow_runs, run_images, status_logs_new, credit_transactions, prompt_variants, prompt_categories, user_state, item_images, item_notes angelegt.

config.php anpassen

base_url (z. B. https://example.com/ecomstudio)

asset_base_url (optional, Standard: base_url . '/assets')

upload_dir (Pfad zum uploads-Verzeichnis)

workflow_webhook (URL zum n8n-Webhook)

receiver_api_token (Shared Secret für n8n → receiver.php, webhook_image.php, api/get-prompt-variants.php)

receiver_api_allowed_ips (optional Whitelist)

db-Konfiguration: dsn, username, password

mail-Block (SMTP Host, User, Passwort, From-Adresse)

credits:

prices für Step-Typen (analysis, image_1, image_2, image_3, …)

Mail einrichten

auth/mail.php nutzt config['mail'] zur Initialisierung von PHPMailer.

SMTP-Zugang setzen.

n8n konfigurieren

In n8n:

Workflow so aufsetzen, dass:

Start-Webhook = workflow_webhook aus config.php

Rückruf-HTTP Requests an:

<base_url>/receiver.php (Status + Texte, mit Authorization: Bearer <receiver_api_token>)

<base_url>/webhook_image.php (Bilder, mit Authorization: Bearer <receiver_api_token>)

<base_url>/api/get-prompt-variants.php (Prompt-Varianten, mit X-API-TOKEN: <receiver_api_token>)

n8n muss alle IDs (run_id, user_id, optional note_id) durchreichen, damit die Zuordnung funktioniert.