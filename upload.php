<?php
header('Content-Type: application/json; charset=utf-8');

class HttpException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$respond = static function (array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'originale' . DIRECTORY_SEPARATOR;

try {
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new HttpException('Upload-Verzeichnis konnte nicht erstellt werden.', 500);
        }
    }

    if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        throw new HttpException('Keine Datei empfangen.', 400);
    }

    $file = $_FILES['image'];

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new HttpException('Fehler beim Upload: ' . ($file['error'] ?? 'unbekannt'), 400);
    }

    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new HttpException('Datei-Info konnte nicht gelesen werden.', 500);
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMime, true)) {
        throw new HttpException('Nur Bilddateien sind erlaubt.', 400);
    }

    $originalName = $file['name'] ?? 'upload';
    $sanitized = preg_replace('/[\\\/\x00-\x1F\x7F]+/u', '_', $originalName);
    $sanitized = trim((string) $sanitized);
    $sanitized = $sanitized === '' ? 'upload_' . date('Ymd_His') : $sanitized;
    $storedName = basename($sanitized);

    $destination = $uploadDir . $storedName;

    $nameWithoutExtension = pathinfo($storedName, PATHINFO_FILENAME);
    $extension = pathinfo($storedName, PATHINFO_EXTENSION);
    $extensionWithDot = $extension !== '' ? '.' . $extension : '';
    $counter = 1;
    while (file_exists($destination)) {
        $storedName = sprintf('%s_%d%s', $nameWithoutExtension, $counter, $extensionWithDot);
        $destination = $uploadDir . $storedName;
        $counter++;
    }

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new HttpException('Datei konnte nicht gespeichert werden.', 500);
    }

    $forwardUrl = 'https://tex305agency.app.n8n.cloud/webhook-test/a73c04f0-5a11-40e5-956a-b0aa2c4d34c5';

    $curlFile = curl_file_create($destination, $mimeType, $storedName);
    $ch = curl_init($forwardUrl);
    if ($ch === false) {
        throw new HttpException('Weiterleitungs-Client konnte nicht initialisiert werden.', 500);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['image' => $curlFile],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json, */*;q=0.8'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $forwardResponse = curl_exec($ch);
    $forwardStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
    $curlError = null;

    if ($forwardResponse === false) {
        $curlError = curl_error($ch) ?: 'Unbekannter Fehler bei der Weiterleitung.';
    }

    curl_close($ch);

    if ($curlError !== null) {
        throw new HttpException('Weiterleitung fehlgeschlagen: ' . $curlError, 502);
    }

    $parsedForwardResponse = null;
    if ($forwardResponse !== false) {
        $decoded = json_decode($forwardResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $parsedForwardResponse = $decoded;
        } else {
            $parsedForwardResponse = $forwardResponse;
        }
    }

    $urlPath = 'originale/' . $storedName;

    $respond([
        'success' => true,
        'message' => 'Upload erfolgreich gespeichert und weitergeleitet.',
        'url' => $urlPath,
        'name' => $storedName,
        'forward_status' => $forwardStatus,
        'forward_response' => $parsedForwardResponse,
    ]);
} catch (Throwable $exception) {
    $statusCode = $exception instanceof HttpException ? $exception->getStatusCode() : 500;

    $respond([
        'success' => false,
        'message' => $exception->getMessage(),
    ], $statusCode);
} finally {
    restore_error_handler();
}
