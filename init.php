<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';

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

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
};

$timestamp = static fn (): string => gmdate('c');

if (!auth_is_logged_in()) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Anmeldung erforderlich.',
        'timestamp' => $timestamp(),
    ], 401);
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Methode nicht erlaubt.',
            'timestamp' => $timestamp(),
        ], 405);
    }

    $config = require __DIR__ . '/config.php';

    $dataFile = $config['data_file'] ?? (__DIR__ . '/data.json');
    $initialData = [
        'isrunning' => false,
    ];

    $encodedData = json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encodedData === false) {
        throw new RuntimeException('Statusdaten konnten nicht kodiert werden.');
    }

    $directory = dirname($dataFile);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Verzeichnis für Statusdatei konnte nicht erstellt werden.');
    }

    if (file_put_contents($dataFile, $encodedData) === false) {
        throw new RuntimeException('Statusdatei konnte nicht zurückgesetzt werden.');
    }

    $respond([
        'success'   => true,
        'status'    => 'ok',
        'message'   => 'Statusdatei initialisiert.',
        'timestamp' => $timestamp(),
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
