# Ecommagent Receiver

Der Receiver erwartet einen gültigen API-Token. Standardmäßig sollte der Token über den Header `Authorization: Bearer <token>` gesendet werden.

Falls ein vorgelagerter Server den Authorization-Header entfernt, akzeptiert der Receiver zusätzlich die alternativen Header `X-Api-Token` oder `X-Authorization`. Diese Fallbacks werden nur über eine HTTPS-Verbindung akzeptiert.

Ungültige oder fehlende Tokens werden weiterhin mit HTTP 401 beantwortet.

## Webhook-Endpunkte

- **JSON-Workflow**: `POST /receiver.php` mit `Content-Type: application/json`
- **Bild-Upload**: `POST /webhook_image.php` mit `multipart/form-data` (Feldname `file`)

Beide Endpunkte erwarten den Header `Authorization: Bearer <token>` (alternativ `X-Api-Token`).

## Datenbereinigung (manuell)

Die folgenden SQL-Beispiele können genutzt werden, um bereits angelegte leere Datensätze zu entfernen. Bitte vor dem Ausführen ein Backup erstellen und die Statements manuell in MySQL/phpMyAdmin prüfen:

```sql
-- Leere item_notes entfernen
DELETE FROM item_notes
WHERE (product_name IS NULL OR product_name = '')
  AND (product_description IS NULL OR product_description = '');

-- user_state auf mögliche Platzhalter prüfen
SELECT * FROM user_state
WHERE (last_message IS NULL OR last_message = '')
  AND (last_status IS NULL OR last_status = '')
  AND (last_payload_summary IS NULL OR last_payload_summary = '');
```

```sql
-- Optional: unbrauchbare user_state-Einträge löschen (vorher prüfen!)
DELETE FROM user_state
WHERE (last_message IS NULL OR last_message = '')
  AND (last_status IS NULL OR last_status = '')
  AND (last_payload_summary IS NULL OR last_payload_summary = '');
```
