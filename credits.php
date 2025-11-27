<?php

/**
 * Liefert den hinterlegten Preis für einen Step-Typ zurück.
 */
function get_credit_price(array $config, string $stepType): ?float
{
    $prices = $config['credits']['prices'] ?? [];
    return isset($prices[$stepType]) ? (float) $prices[$stepType] : null;
}

/**
 * Ermittelt, wie viele Credits ein vollständiger Workflow voraussichtlich benötigt.
 *
 * Aktuell: Summe aller positiven Preise aus $config['credits']['prices'].
 *
 * @param array $config Globale Konfiguration aus config.php
 *
 * @return float Benötigte Credits für einen vollständigen Run
 */
function get_required_credits_for_run(array $config): float
{
    $prices = $config['credits']['prices'] ?? [];

    $total = 0.0;
    foreach ($prices as $price) {
        if (is_numeric($price)) {
            $numericPrice = (float) $price;
            if ($numericPrice > 0) {
                $total += $numericPrice;
            }
        }
    }

    return (float) $total;
}

/**
 * Bucht Credits für einen bestimmten Step eines Users ab und protokolliert die Bewegung.
 *
 * @param PDO    $pdo      Geöffnete PDO-Datenbankverbindung
 * @param array  $config   Globale Konfiguration aus config.php
 * @param int    $userId   ID des Users
 * @param int|null $runId  ID des Runs (optional, kann NULL sein z.B. bei allgemeinen Gutschriften)
 * @param string $stepType Logischer Step-Typ (z. B. 'analysis', 'image_1', 'image_2', 'image_3', 'topup' usw.)
 * @param array  $meta     Optionale Zusatzinformationen (werden serialisiert in credit_transactions.meta abgelegt)
 *
 * @return void
 */
function charge_credits(PDO $pdo, array $config, int $userId, ?int $runId, string $stepType, array $meta = []): void
{
    $price = get_credit_price($config, $stepType);

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
            // Gesamtkosten dieses Runs (credits_spent) um den Preis des aktuellen Steps erhöhen
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

// Credits für einen Step-Typ (z. B. 'analysis', 'image_1') abbuchen und in credit_transactions protokollieren.
