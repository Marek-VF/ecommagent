# Ecomm Agent (v6)

**Ecomm Agent** ist eine webbasierte PHP-Anwendung zur automatisierten Artikel- und Bildverarbeitung über einen externen **n8n-Workflow**.
Version **v6** setzt vollständig auf MySQL 8.4 als Persistenzschicht, nutzt PHP 8.2 und Vanilla JavaScript im Frontend und verwaltet alle Benutzerläufe inklusive Assets in einem zentralen Upload-Verzeichnis.

---

## 🚀 Funktionsübersicht

- 📤 **Upload an n8n:** Benutzer laden ein Bild hoch; das System erstellt einen neuen Workflow-Run und sendet Bild + Metadaten an den konfigurierten n8n-Webhook.
- 🔄 **Rückkanal:** n8n liefert Artikeldaten (JSON) und generierte Bilder über zwei abgesicherte Webhooks zurück.
- 💾 **Persistenz:** Alle Daten (Runs, Logs, Bilder, Artikeldaten, Status) werden ausschließlich in **MySQL 8.4** gespeichert.
- 👥 **Multi-User:** Jeder Benutzer besitzt eigene Runs, History und Statusinformationen.
- 🧩 **API-Endpunkte:** Eingeloggte Benutzer können History, Details und den aktuellen Status über `/api/*.php` abrufen.
- 🖼️ **Uploads:** Sämtliche Dateien liegen in `/uploads/{user_id}/{run_id}/` – unabhängig davon, ob sie vom Benutzer oder von n8n stammen.

---

## 📁 Projektstruktur (v6)

```text
.
├── api/                   # Geschützte JSON-API für eingeloggte Benutzer
├── auth/                  # Registrierung, Login, Passwort-Reset, Mailversand
├── config.php             # Konfiguration (DB, Uploads, Webhooks, SMTP)
├── db.php                 # PDO-Wrapper
├── docs/                  # Dokumentation (z. B. technische Spezifikation)
├── import.sql             # MySQL-8.4-Schema
├── index.php              # Hauptfrontend (Upload & History)
├── receiver.php           # JSON-Webhook für n8n (Artikeldaten)
├── script.js              # Frontend-Logik (Upload, Polling, Sidebar)
├── style.css              # Oberflächen-Styling
├── upload.php             # Upload-Endpunkt für den Browser
├── uploads/               # Zielverzeichnis für Benutzer- und n8n-Dateien
└── webhook_image.php      # Bild-Webhook für n8n
```

---

## ⚙️ Installation

### 1. Voraussetzungen

- Apache Webserver (z. B. XAMPP unter Windows) mit aktivem `mod_rewrite`
- PHP **≥ 8.2** (mit PDO, OpenSSL, Fileinfo, GD/Imagick)
- MySQL **8.4**
- n8n-Instanz mit passendem Workflow
- SMTP-Zugangsdaten für Registrierung & Passwort-Reset

### 2. Dateien bereitstellen

Projekt in das Zielverzeichnis (z. B. `C:\xampp\htdocs\ecommagent`) kopieren.

### 3. Datenbank importieren

```bash
mysql -u USERNAME -p ecommagent < import.sql
```

### 4. Konfiguration anpassen

`config.php` bearbeiten und eigene Werte hinterlegen:

```php
return [
    'base_url'         => 'https://example.com/ecommagent',
    'asset_base_url'   => 'https://example.com/ecommagent/assets',
    'upload_dir'       => __DIR__ . '/uploads',

    'workflow_webhook' => 'https://n8n.example.com/webhook/abcd1234',
    'receiver_api_token' => 'supersecrettoken',

    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=ecommagent;charset=utf8mb4',
        'username' => 'root',
        'password' => '',
    ],

    'smtp' => [
        'host'       => 'mail.example.com',
        'port'       => 587,
        'auth'       => true,
        'username'   => 'noreply@example.com',
        'password'   => '********',
        'encryption' => 'tls',
    ],

    'mail' => [
        'from_address' => 'noreply@example.com',
        'from_name'    => 'Ecomm Agent',
    ],
];
```

### 5. Rechte setzen

```
chmod -R 775 uploads
chmod 644 config.php
```

### 6. Zugriff im Browser

Anwendung unter `https://example.com/ecommagent/` aufrufen, registrieren/anmelden und über die UI hochladen.

---

## 🔄 Ablaufübersicht

1. Benutzer lädt ein Bild über `upload.php` hoch.
2. Das System erzeugt einen DB-Eintrag (`workflow_runs`), legt das Bild unter `/uploads/{user_id}/{run_id}/` ab und ruft den konfigurierten n8n-Workflow auf.
3. n8n antwortet mit Artikeldaten an `receiver.php` (**JSON**, Pflichtfelder: `user_id`, `run_id`).
4. n8n lädt generierte Bilder über `webhook_image.php` hoch (multipart/form-data mit `user_id`, `run_id`).
5. Frontend pollt `api/get-latest-item.php` und `api/get-run-details.php`, um den aktuellen Status und die History darzustellen.
6. Alle Ergebnisse bleiben in der Datenbank und den zugehörigen Upload-Verzeichnissen gespeichert.

---

## 🧠 Datenbank-Überblick

| Tabelle          | Zweck                                                |
| ---------------- | ---------------------------------------------------- |
| `users`          | Benutzerkonten & Authentifizierung                   |
| `workflow_runs`  | Jeder Workflow-Durchlauf eines Benutzers             |
| `user_state`     | Letzter Status pro Benutzer (für Polling)            |
| `item_notes`     | Artikeldaten (Titel, Beschreibung, Quelle)           |
| `item_images`    | Zugehörige Bilder (Pfad, Reihenfolge)                |
| `status_logs`    | Ereignis- und Fehlerprotokoll                        |

Persistenz erfolgt ausschließlich über MySQL 8.4 – frühere JSON-Dateien (`data.json`) werden nicht mehr genutzt.

---

## 🌐 Webhook-Integration (n8n)

### Artikeldaten

```
POST https://example.com/ecommagent/receiver.php
Authorization: Bearer supersecrettoken
Content-Type: application/json

{
  "user_id": 5,
  "run_id": 123,
  "product_name": "Beispielartikel",
  "product_description": "Beschreibung automatisch generiert",
  "isrunning": true
}
```

### Bilder

```
POST https://example.com/ecommagent/webhook_image.php
Authorization: Bearer supersecrettoken
Content-Type: multipart/form-data

user_id=5
run_id=123
file=@/data/output/image1.png
```

Beide Endpunkte validieren `run_id` strikt und antworten mit HTTP 400, falls die Angabe fehlt.

---

## 🔐 Sicherheit

- Die n8n-Webhooks (`receiver.php`, `webhook_image.php`) akzeptieren ausschließlich `Authorization: Bearer <token>` – es gibt keine IP-Whitelist mehr.
- Alle Benutzer- und API-Endpunkte erfordern eine aktive Session nach Login/Registrierung.
- Passwörter werden über `password_hash()` gespeichert; SMTP-Einstellungen ermöglichen optionale Mail-Verifizierung.

---

## 📂 Uploads & Assets

- Jeder Run besitzt einen eigenen Ordner: `/uploads/{user_id}/{run_id}/`.
- `upload.php` speichert Benutzer-Uploads direkt dort und leitet den vollständigen Pfad an n8n weiter.
- `webhook_image.php` legt n8n-generierte Dateien im gleichen Ordner ab, wodurch alle Assets eines Runs gebündelt bleiben.

---

## 📝 Versionshinweis

Version v6 ersetzt die zuvor genutzte JSON-Datei (`data.json`) vollständig durch Datenbankpersistenz, entfernt die IP-Whitelist und führt den einheitlichen Upload-Pfad pro Benutzer/Run ein. Dokumentation und Frontend wurden entsprechend angepasst.
