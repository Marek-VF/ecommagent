# Ecomm Agent

**Ecomm Agent** ist eine PHP 8.2 / MySQL 8.4 Webanwendung zur halbautomatischen Produkt- und Bildverarbeitung über einen externen **n8n-Workflow**.  
Benutzer können Bilder hochladen, den Workflow starten und erhalten automatisch generierte Produktbeschreibungen und Bilder.  
Alle Durchläufe („Runs“) werden in einer Historie gespeichert und sind jederzeit erneut abrufbar.

---

## 🚀 Funktionen

- 📤 **Upload:** Benutzer lädt ein oder mehrere Bilder hoch. Dabei wird automatisch ein neuer Workflow-Run angelegt.
- 🔗 **Workflow:** Über `start-workflow.php` werden Run-Daten und Bild-URLs an den konfigurierten n8n-Webhook übergeben.
- 🔄 **Rückkanal:**  
  - `receiver.php` empfängt Artikeldaten, Statusmeldungen und Abschluss-Informationen.  
  - `webhook_image.php` empfängt von n8n generierte Bilder (multipart/form-data).
- 🧠 **Polling:** Das Frontend fragt regelmäßig `api/get-latest-item.php` ab, um Run-Daten aktuell zu halten.
- 🕓 **Verläufe:** Über `api/get-runs.php` und `api/get-run-details.php` können vergangene Runs geladen werden.
- 👥 **Multi-User-System:** Benutzerverwaltung mit Registrierung, Login und Passwort-Reset.
- ⚙️ **Benutzereinstellungen:** Profil- und Bild-Einstellungen (z. B. bevorzugtes Seitenverhältnis) im Bereich `/settings/`.

---

## 📁 Projektstruktur

```text
.
├── api/
│   ├── get-latest-item.php
│   ├── get-runs.php
│   └── get-run-details.php
├── auth/
│   ├── login.php
│   ├── register.php
│   ├── verify.php
│   ├── forgot.php
│   ├── reset.php
│   └── bootstrap.php
├── settings/
│   ├── index.php
│   ├── profile.php
│   ├── image.php
│   └── update_image_settings.php
├── docs/
│   ├── technical_spec.txt
│   └── codex_context.txt
├── index.php
├── upload.php
├── start-workflow.php
├── receiver.php
├── webhook_image.php
├── config.php
├── db.php
├── script.js
├── style.css
└── import.sql

⚙️ Installation

    Voraussetzungen

        PHP ≥ 8.2 (z. B. XAMPP)

        MySQL ≥ 8.4

        Apache mit mod_rewrite

    Datenbank importieren

        import.sql in eine leere Datenbank importieren.

    Konfiguration anpassen

        In config.php:

            base_url → Basis-URL der Installation

            upload_dir → Pfad für hochgeladene Dateien

            workflow_webhook → n8n-Webhook-URL

            receiver_api_token → Token für n8n-Callbacks

    Benutzer registrieren

        Registrierung über /auth/register.php oder direkt in der Datenbank.

    Login

        Nach Login öffnet sich die Hauptoberfläche mit Upload- und Verlaufs-Modul.

🔌 n8n-Integration (Ablauf)

    Upload → upload.php legt neuen Run und Upload-Datei an.

    Start-Button → start-workflow.php sendet Run- und Bilddaten an n8n.

    n8n ruft zurück:

        receiver.php überträgt Metadaten, Produktnamen, Beschreibung.

        webhook_image.php überträgt generierte Bilder.

    Frontend-Polling: ruft api/get-latest-item.php ab und aktualisiert Oberfläche.

    Runs werden gespeichert in workflow_runs und sind über die Verlaufs-Sidebar wieder abrufbar.

🧠 Datenbankstruktur
Tabelle	Zweck
users	Benutzerkonten, Verifizierung, Reset, Präferenzen
user_state	Letzter Status je Benutzer
workflow_runs	Alle Durchläufe eines Benutzers
item_notes	Von n8n gelieferte Produktnamen und Beschreibungen
item_images	Von n8n generierte Bilder
status_logs	Technische Statusmeldungen
🧩 Code-Übersicht

    index.php – Hauptoberfläche mit Upload-Zone, Formular und Historie

    upload.php – Empfängt Uploads und legt Runs an

    start-workflow.php – Startet externen n8n-Workflow

    receiver.php – Nimmt n8n-JSON-Daten entgegen

    webhook_image.php – Nimmt generierte Bilder von n8n entgegen

    script.js – Frontend-Logik: Upload, Polling, Sidebar, Fade-In-Animation

    style.css – Dark-Theme, responsive Layout

    settings/ – Benutzerprofile & Bildverhältnis-Einstellungen

    auth/ – Login, Registrierung, Passwort-Reset

🔒 Sicherheit

    Authentifizierung über Session-Tokens (Login erforderlich)

    n8n-Callbacks prüfen Bearer-Token aus config.php

    Passwörter mit password_hash()

    PDO-Prepared Statements gegen SQL-Injection

    Keine externen Abhängigkeiten außer PHPMailer im Auth-Modul

🧾 Changelog (Stand 10.11.2025)

    UI-Modernisierung (Dark Theme, Sidebar, Multi-Image-Layout)

    Erweiterung auf bis zu zwei Originalbilder je Run

    Überarbeitung der technischen Dokumentation

    Aufnahme der Settings-Funktion pro Benutzer