<?php

/**
 * Liefert den hinterlegten Preis für einen Step-Typ zurück.
 * Unterstützt das neue Array-Format ['price' => 1.00, 'group_id' => 1]
 * sowie das alte Float-Format.
 */
function get_credit_price(array $config, string $stepType): ?float
{
    $prices = $config['credits']['prices'] ?? [];
    
    if (!isset($prices[$stepType])) {
        return null;
    }

    $entry = $prices[$stepType];

    // Neu: Array-Struktur supporten
    if (is_array($entry)) {
        return isset($entry['price']) ? (float)$entry['price'] : null;
    }

    // Fallback: Direkter Float (alte Config)
    return (float)$entry;
}

/**
 * Ermittelt, wie viele Credits ein vollständiger Workflow (Haupt-Run) benötigt.
 * Summiert NUR Einträge mit 'group_id' => 1.
 *
 * @param array $config Globale Konfiguration aus config.php
 * @return float Benötigte Credits
 */
function get_required_credits_for_run(array $config): float
{
    $prices = $config['credits']['prices'] ?? [];
    $total = 0.0;

    foreach ($prices as $entry) {
        // Wir verarbeiten primär das neue Array-Format
        if (is_array($entry)) {
            $price = (float)($entry['price'] ?? 0.0);
            $groupId = (int)($entry['group_id'] ?? 0);

            // Nur Gruppe 1 (Haupt-Run) wird für den Start-Button berechnet
            if ($groupId === 1 && $price > 0) {
                $total += $price;
            }
        } 
        // Optionaler Fallback: Wenn in der Config noch einfache Floats stehen (z.B. 'analysis' => 0.5),
        // zählen wir diese sicherheitshalber dazu, damit der Workflow nicht "kostenlos" wird.
        elseif (is_numeric($entry)) {
             $val = (float)$entry;
             if ($val > 0) {
                 $total += $val;
             }
        }
    }

    return (float) $total;
}

/**
 * Bucht Credits für einen bestimmten Step eines Users ab und protokolliert die Bewegung.
 * (Diese Funktion bleibt in ihrer Logik unverändert, nutzt aber jetzt das neue get_credit_price)
 */
function charge_credits(PDO $pdo, array $config, int $userId, ?int $runId, string $stepType, array $meta = []): void
{
    // Hier wird automatisch die neue Logik von oben verwendet
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

        // User-Feedback über Abbuchung
        if ($runId !== null) {
            $formattedPrice = number_format($price, 2, ',', '.');
            
            $displayReason = match ($stepType) {
                '2K', '2k', 'upscale_2x' => 'Upscale 2K',
                '4K', '4k', 'upscale_4x' => 'Upscale 4K',
                'edit', 'inpainting'     => 'Bearbeitung',
                'image_1', 'image_2', 'image_3' => 'Bildgenerierung',
                'analysis' => 'Analyse',
                default => ucfirst($stepType),
            };
            
            $logMsg = sprintf('Guthaben: -%s Credits (%s)', $formattedPrice, $displayReason);
            
            if (function_exists('log_status_message')) {
                log_status_message($pdo, $runId, $userId, $logMsg, 'CREDITS_SPENT');
            }
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
