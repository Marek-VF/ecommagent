<?php
header('Content-Type: application/json; charset=utf-8');

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'originale' . DIRECTORY_SEPARATOR;

$respond = static function (array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $respond([
            'success' => false,
            'error' => 'Upload-Verzeichnis konnte nicht erstellt werden.'
        ], 500);
    }
}

if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    $respond([
        'success' => false,
        'error' => 'Keine Datei empfangen.'
    ], 400);
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $respond([
        'success' => false,
        'error' => 'Fehler beim Upload: ' . $file['error']
    ], 400);
}

$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMime, true)) {
    $respond([
        'success' => false,
        'error' => 'Nur Bilddateien sind erlaubt.'
    ], 400);
}

$originalName = $file['name'] ?? 'upload';
$sanitized = preg_replace('/[\\\/\x00-\x1F\x7F]+/u', '_', $originalName);
$sanitized = trim($sanitized);
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
    $respond([
        'success' => false,
        'error' => 'Datei konnte nicht gespeichert werden.'
    ], 500);
}

$forwardUrl = 'https://tex305agency.app.n8n.cloud/webhook-test/a73c04f0-5a11-40e5-956a-b0aa2c4d34c5';

$curlFile = curl_file_create($destination, $mimeType, $storedName);
$ch = curl_init($forwardUrl);
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

if ($curlError !== null) {
    $respond([
        'success' => false,
        'error' => 'Weiterleitung fehlgeschlagen: ' . $curlError,
        'url' => $urlPath,
        'name' => $storedName,
        'forward_status' => $forwardStatus,
    ], 502);
}

$respond([
    'success' => true,
    'message' => 'Upload erfolgreich gespeichert und weitergeleitet.',
    'url' => $urlPath,
    'name' => $storedName,
    'forward_status' => $forwardStatus,
    'forward_response' => $parsedForwardResponse,
]);
