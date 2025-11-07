# Ecomm Agent

**Ecomm Agent** ist eine webbasierte Multi-User-Anwendung zum automatisierten Upload, zur Bildverarbeitung und zur Artikeldaten-Erstellung über n8n-Workflows.  
Die Anwendung empfängt und verarbeitet in mehreren Stufen Daten von einem externen n8n-Server und stellt Ergebnisse (Texte + Bilder) im Frontend dar.

---

## 🚀 Funktionen

- 📸 **Bild-Upload:** Benutzer können Bilder hochladen, die automatisch an einen n8n-Workflow gesendet werden.
- ⚙️ **Workflow-Verarbeitung (n8n):**  
  n8n analysiert das Bild, erzeugt Artikeldaten (Name, Beschreibung) und sendet mehrere Rückmeldungen an die App.
- 🧠 **Run-Verwaltung:**  
  Jeder Workflow-Durchlauf erhält eine eindeutige `run_id`, die an n8n übergeben und in allen Rückmeldungen mitgeführt wird.
- 💬 **Live-Anzeige:**  
  Das Frontend pollt den Server und zeigt eingehende Bilder & Texte sofort an.
- 📂 **History-Sidebar:**  
  Alle bisherigen Runs (mit Datum + Artikelnamen) können erneut geladen werden.
- 🌄 **Bild-Animation:**  
  Bilder, die neu geladen oder aus dem Verlauf angezeigt werden, **faden sanft ein**.
- 🔒 **Multi-User-System:**  
  Registrierung, Login, individuelle Workflow-Sessions, isolierte Datensätze.
- 🧾 **Logging:**  
  Workflow-Status, Systemmeldungen und Laufzeiten werden in der Datenbank gespeichert.

---

## 🧱 Systemvoraussetzungen

- Apache Server mit PHP ≥ 8.2  
- MySQL oder MariaDB  
- Schreibrechte im `/uploads`-Verzeichnis  
- Zugriff auf einen n8n-Server mit Webhook-URLs  

---

## ⚙️ Installation

1. Repository klonen:
   ```bash
   git clone https://github.com/username/ecomm-agent.git
   cd ecomm-agent

    Datenbank importieren:

mysql -u USERNAME -p ecommagent < import.sql

Konfiguration anpassen (config.php):

    return [
      'db' => [
        'host' => 'localhost',
        'name' => 'ecommagent',
        'user' => 'root',
        'pass' => ''
      ],
      'upload_dir' => __DIR__ . '/uploads',
      'base_url'   => 'https://deinedomain.de',
      'n8n_webhook_url' => 'https://n8n-server.de/webhook/upload'
    ];

    Apache-Host konfigurieren und Projekt aufrufen.

🧩 Verzeichnisstruktur

ecomm-agent/
│
├── auth/                # Registrierung, Login, Session-Handling
├── api/                 # REST-Endpoints für Polling & History
├── uploads/             # user_id/run_id-Struktur mit gespeicherten Bildern
├── assets/              # Styles, pulse.svg, Icons
├── docs/                # Dokumentation (technical_spec.md)
├── index.php            # Hauptfrontend
├── script.js            # Frontend-Logik (Upload, Polling, History, Fade-In)
├── upload.php           # Bild-Upload, n8n-Webhook-Aufruf, Run-Erstellung
├── receiver.php         # Empfängt Artikeldaten, speichert item_notes etc.
├── webhook_image.php    # Empfängt Bilder von n8n, speichert unter /uploads
├── import.sql           # Datenbankschema
└── README.md

🧭 Ablaufdiagramm

User → upload.php → n8n-Webhook → n8n sendet mehrere Antworten →
receiver.php (Textdaten) / webhook_image.php (Bilder) →
DB: workflow_runs + item_notes + item_images →
Frontend (Polling) zeigt Daten + Fade-In-Animation

