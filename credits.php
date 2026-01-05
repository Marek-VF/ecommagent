<?php

/**
 * Liefert den hinterlegten Preis (Credits) fuer einen Step-Typ aus der Datenbank.
 */
function get_credit_price(PDO $pdo, string $stepType): ?float
{
    $stepType = trim($stepType);
    if ($stepType === '') {
        return null;
    }

    $prices = get_credit_step_prices($pdo);

    if (!array_key_exists($stepType, $prices)) {
        return null;
    }

    return (float) $prices[$stepType];
}

/**
 * Ermittelt, wie viele Credits ein vollstaendiger Workflow (Haupt-Run) benoetigt.
 * Summiert NUR Eintraege mit group_id = 1; falls keine group_id definiert ist,
 * wird auf alle aktiven Step-Preise zurueckgefallen.
 */
function get_required_credits_for_run(PDO $pdo, int $groupId = 1): float
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt, COALESCE(SUM(credits), 0) AS total
         FROM products
         WHERE type = :type AND active = 1 AND group_id = :group_id AND credits > 0'
    );
    $stmt->execute([
        ':type' => 'step',
        ':group_id' => $groupId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];

    $count = (int) ($row['cnt'] ?? 0);
    $total = (float) ($row['total'] ?? 0);

    if ($count === 0) {
        $fallback = $pdo->query(
            "SELECT COALESCE(SUM(credits), 0) AS total
             FROM products
             WHERE type = 'step' AND active = 1 AND credits > 0"
        );
        $fallbackRow = $fallback->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
        $total = (float) ($fallbackRow['total'] ?? 0);
    }

    return $total;
}

/**
 * Liefert alle aktiven Step-Preise (product_key => credits) aus der Datenbank.
 *
 * @return array<string, float>
 */
function get_credit_step_prices(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $stmt = $pdo->query(
        "SELECT product_key, credits
         FROM products
         WHERE type = 'step' AND active = 1"
    );

    $cache = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = isset($row['product_key']) ? trim((string) $row['product_key']) : '';
        if ($key === '') {
            continue;
        }
        $cache[$key] = (float) ($row['credits'] ?? 0);
    }

    return $cache;
}

/**
 * Liefert ein aktives Credit-Paket anhand des package_key.
 *
 * @return array<string, mixed>|null
 */
function get_credit_package(PDO $pdo, string $packageKey): ?array
{
    $packageKey = trim($packageKey);
    if ($packageKey === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT package_key, label, credits, price, currency
         FROM credit_packages
         WHERE active = 1 AND package_key = :key
         LIMIT 1"
    );
    $stmt->execute([':key' => $packageKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Liefert alle aktiven Credit-Pakete fuer die UI/Checkout.
 *
 * @return array<int, array<string, mixed>>
 */
function get_credit_packages(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT package_key, label, credits, price, currency
         FROM credit_packages
         WHERE active = 1
         ORDER BY sort_order ASC, id ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Bucht Credits fuer einen bestimmten Step eines Users ab und protokolliert die Bewegung.
 */
function charge_credits(PDO $pdo, int $userId, ?int $runId, string $stepType, array $meta = []): void
{
    $price = get_credit_price($pdo, $stepType);

    // Keine Abbuchung vornehmen, wenn kein Preis hinterlegt ist oder der Preis 0/negativ ist.
    if ($price === null || $price <= 0) {
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :user_id FOR UPDATE');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return;
        }

        $currentBalance = (float) $row['credits_balance'];
        $newBalance = $currentBalance - $price;

        $updateStmt = $pdo->prepare('UPDATE users SET credits_balance = :balance WHERE id = :user_id');
        $updateStmt->execute([
            ':balance' => $newBalance,
            ':user_id' => $userId,
        ]);

        if ($runId !== null) {
            $updateRunStmt = $pdo->prepare('
                UPDATE workflow_runs
                SET credits_spent = credits_spent + :price
                WHERE id = :run_id
            ');
            $updateRunStmt->execute([
                ':price' => $price,
                ':run_id' => $runId,
            ]);
        }

        $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $insertStmt = $pdo->prepare('
            INSERT INTO credit_transactions (user_id, run_id, amount, reason, meta)
            VALUES (:user_id, :run_id, :amount, :reason, :meta)
        ');
        $insertStmt->execute([
            ':user_id' => $userId,
            ':run_id' => $runId,
            ':amount' => -$price,
            ':reason' => $stepType,
            ':meta' => $metaJson,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Gutschrift von Credits fuer einen Benutzer (z. B. nach erfolgreicher Zahlung).
 * Gibt die ID des erzeugten credit_transactions-Eintrags zurueck oder 0, wenn nichts gebucht wurde.
 */
function add_credits(PDO $pdo, int $userId, float $amount, string $reason = 'manual', array $meta = []): int
{
    if ($amount <= 0) {
        return 0;
    }

    try {
        $manageTransaction = !$pdo->inTransaction();

        if ($manageTransaction) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :user_id FOR UPDATE');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return 0;
        }

        $currentBalance = (float) $row['credits_balance'];
        $newBalance = $currentBalance + $amount;

        $updateStmt = $pdo->prepare('UPDATE users SET credits_balance = :balance WHERE id = :user_id');
        $updateStmt->execute([
            ':balance' => $newBalance,
            ':user_id' => $userId,
        ]);

        $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $insertStmt = $pdo->prepare('
            INSERT INTO credit_transactions (user_id, run_id, amount, reason, meta)
            VALUES (:user_id, NULL, :amount, :reason, :meta)
        ');
        $insertStmt->execute([
            ':user_id' => $userId,
            ':amount'  => $amount,
            ':reason'  => $reason,
            ':meta'    => $metaJson,
        ]);

        $transactionId = (int) $pdo->lastInsertId();

        if ($manageTransaction) {
            $pdo->commit();
        }

        return $transactionId;
    } catch (Throwable $e) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
