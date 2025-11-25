<?php
declare(strict_types=1);

if (!function_exists('getPDO')) {
function getPDO(): PDO
{
        static $connection = null;

        if ($connection instanceof PDO) {
            return $connection;
        }

        $config = require __DIR__ . '/config.php';
        if (!isset($config['db']) || !is_array($config['db'])) {
            throw new RuntimeException('Datenbankkonfiguration fehlt.');
        }

        $dbConfig = $config['db'];
        $dsn = (string)($dbConfig['dsn'] ?? '');
        if ($dsn === '') {
            throw new RuntimeException('Datenbank-DSN ist nicht konfiguriert.');
        }

        $username = (string)($dbConfig['username'] ?? '');
        $password = (string)($dbConfig['password'] ?? '');
        $options  = $dbConfig['options'] ?? [];

        if (!is_array($options)) {
            $options = [];
        }

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $connection = new PDO($dsn, $username, $password, $options + $defaultOptions);
        } catch (PDOException $exception) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen: ' . $exception->getMessage(), 0, $exception);
        }

        return $connection;
    }
}

/**
 * Holt alle Prompt-Kategorien aus der Datenbank.
 *
 * @return array<int, array{id:int, category_key:string, label:string}>
 */
function db_get_prompt_categories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, category_key, label FROM prompt_categories ORDER BY id ASC');

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Liefert die ID einer Kategorie anhand des category_key oder NULL, wenn nicht vorhanden.
 */
function db_get_prompt_category_id_by_key(PDO $pdo, string $key): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM prompt_categories WHERE category_key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}
