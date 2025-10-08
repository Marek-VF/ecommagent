<?php
header('Content-Type: application/json; charset=utf-8');

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'originale' . DIRECTORY_SEPARATOR;

if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode([
            'success' => false,
            'error' => 'Upload-Verzeichnis konnte nicht erstellt werden.'
        ]);
        exit;
    }
}

if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Keine Datei empfangen.'
    ]);
    exit;
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Upload: ' . $file['error']
    ]);
    exit;
}

$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMime, true)) {
    echo json_encode([
        'success' => false,
        'error' => 'Nur Bilddateien sind erlaubt.'
    ]);
    exit;
}

$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$extension = $extension ? '.' . strtolower($extension) : '';
$uniqueName = bin2hex(random_bytes(8)) . $extension;
$destination = $uploadDir . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode([
        'success' => false,
        'error' => 'Datei konnte nicht gespeichert werden.'
    ]);
    exit;
}

$urlPath = 'originale/' . $uniqueName;

echo json_encode([
    'success' => true,
    'url' => $urlPath,
    'name' => $file['name']
]);
