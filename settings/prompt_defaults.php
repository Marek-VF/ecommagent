<?php

/**
 * Lädt die Default-Prompt-Varianten aus settings/default_variants.json.
 *
 * Rückgabe:
 * - Assoziatives Array im Format:
 *   [
 *     'fashion' => [
 *       '1' => [...],
 *       '2' => [...],
 *       '3' => [...],
 *     ],
 *     'dekoration' => [...],
 *     'schmuck' => [...],
 *   ]
 * - Leeres Array [] im Fehlerfall (Datei nicht lesbar, JSON ungültig, etc.).
 */
function load_default_prompt_variants(): array
{
    // Pfad relativ zu diesem settings-Verzeichnis
    $path = __DIR__ . '/default_variants.json';

    if (!is_readable($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    return $data;
}
