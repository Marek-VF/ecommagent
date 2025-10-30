# Ecommagent Receiver

Der Receiver erwartet einen gültigen API-Token. Standardmäßig sollte der Token über den Header `Authorization: Bearer <token>` gesendet werden.

Falls ein vorgelagerter Server den Authorization-Header entfernt, akzeptiert der Receiver zusätzlich die alternativen Header `X-Api-Token` oder `X-Authorization`. Diese Fallbacks werden nur über eine HTTPS-Verbindung akzeptiert.

Ungültige oder fehlende Tokens werden weiterhin mit HTTP 401 beantwortet.
