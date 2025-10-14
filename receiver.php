<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$timestamp = static fn (): string => gmdate('c');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Methode nicht erlaubt.',
            'timestamp' => $timestamp(),
        ], 405);
    }

    $config = require __DIR__ . '/config.php';

    $uploadDir = rtrim($config['upload_dir'] ?? (__DIR__ . '/uploads/'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $dataFile  = $config['data_file'] ?? (__DIR__ . '/data.json');
    $baseUrl   = rtrim((string) ($config['base_url'] ?? ''), '/');

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
    }

    $existingData = [];
    if (is_file($dataFile)) {
        $contents = file_get_contents($dataFile);
        if ($contents !== false && $contents !== '') {
            $decoded = json_decode($contents, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingData = $decoded;
            }
        }
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $newData = [];

    $storeFile = static function (array $file, string $uploadDir): string {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen: ' . ($file['error'] ?? 'unbekannt'));
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Ungültige Dateiübertragung.');
        }

        $originalName = $file['name'] ?? ('upload_' . date('Ymd_His'));
        $sanitized = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '_', (string) $originalName);
        $sanitized = trim($sanitized) === '' ? 'upload_' . date('Ymd_His') : trim($sanitized);
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
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        return $storedName;
    };

    $normalizeValue = static function (string $key, mixed $value): mixed {
        if ($key === 'isrunning') {
            if (is_bool($value)) {
                return $value;
            }

            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        return $value;
    };

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            throw new RuntimeException('JSON-Daten fehlen.');
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('JSON konnte nicht verarbeitet werden.');
        }

        foreach ($decoded as $key => $value) {
            $newData[$key] = $normalizeValue((string) $key, $value);
        }
    } else {
        foreach ($_POST as $key => $value) {
            $newData[$key] = $normalizeValue((string) $key, $value);
        }

        $normalizeFiles = static function (array $files): array {
            $normalized = [];
            foreach ($files as $field => $data) {
                if (is_array($data['name'])) {
                    $fileCount = count($data['name']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        $normalized[$field . ($fileCount > 1 ? '_' . ($i + 1) : '')] = [
                            'name'     => $data['name'][$i],
                            'type'     => $data['type'][$i] ?? null,
                            'tmp_name' => $data['tmp_name'][$i] ?? null,
                            'error'    => $data['error'][$i] ?? null,
                            'size'     => $data['size'][$i] ?? null,
                        ];
                    }
                } else {
                    $normalized[$field] = $data;
                }
            }

            return $normalized;
        };

        foreach ($normalizeFiles($_FILES) as $field => $fileData) {
            if (!is_array($fileData)) {
                continue;
            }

            $storedName = $storeFile($fileData, $uploadDir);
            $origin = $baseUrl;
            if ($origin === '') {
                $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
                $scheme = $isHttps ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST']
                    ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
                $origin = $scheme . '://' . $host;

                $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
                $scriptDir = ($scriptDir === '.' ? '' : $scriptDir);
                if ($scriptDir !== '') {
                    $origin .= ($scriptDir[0] === '/' ? '' : '/') . trim($scriptDir, '/');
                }
            }

            $fileUrl = rtrim($origin, '/') . '/uploads/' . $storedName;
            $newData[$field] = $fileUrl;
        }
    }

    $merged = array_merge($existingData, $newData);
    $merged['updated_at'] = $timestamp();

    file_put_contents($dataFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $respond([
        'success'   => true,
        'status'    => 'ok',
        'message'   => 'Daten aktualisiert.',
        'timestamp' => $timestamp(),
        'data'      => $merged,
    ]);
} catch (Throwable $exception) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => $exception->getMessage(),
        'timestamp' => $timestamp(),
    ], $exception instanceof RuntimeException ? 400 : 500);
} finally {
    restore_error_handler();
}
