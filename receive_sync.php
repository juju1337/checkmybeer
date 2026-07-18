<?php
// ---------------------------------------------------------------
// receive_sync.php – Empfangs-Endpunkt für das Browser-Relay
//
// Bekommt per POST JSON-Pakete vom JavaScript in sync_web.php.
// Der Upload kommt in Häppchen WÄHREND des Syncs, damit bei einem
// Abbruch (z.B. Untappd-Rate-Limit) nichts verloren geht:
//   {
//     "reset": bool,          nur beim 1. Paket eines frischen Full-Syncs
//     "items": [ ...Zeilen ],
//     "state": { "next_offset": int, "complete": bool }
//   }
// Bei complete=false wird next_offset in data/sync_state.json
// gemerkt – der nächste Aufruf von sync_web.php setzt dort fort.
// Bei complete=true wird die Statusdatei gelöscht.
// Antwort: { "ok": true, "new": 3, "total": 251 }
//
// Zugriff nur mit gültigem ?token=... (siehe config.php).
// ---------------------------------------------------------------

declare(strict_types=1);

// Fatals (z.B. fehlende Funktion durch veraltete Include-Datei) als
// JSON melden statt mit leerer 500er-Antwort zu sterben
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'PHP-Fatal: ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line'],
        ]);
    }
});

require __DIR__ . '/history_store.php';

header('Content-Type: application/json; charset=utf-8');

function jsonFail(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$config = require __DIR__ . '/config.php';

// --- Token-Prüfung -----------------------------------------------------
$expected = $config['sync_token'] ?? '';
$given    = $_GET['token'] ?? '';
if ($expected === '' || $expected === 'BITTE_AENDERN' || !hash_equals($expected, $given)) {
    jsonFail(403, 'Zugriff verweigert: gültiger ?token=... erforderlich.');
}

// --- Ping-Modus: Selbsttest per Browser-Aufruf ---------------------------
// receive_sync.php?token=...&ping=1 prüft alles außer dem Merge selbst.
if (!empty($_GET['ping'])) {
    $dataDir = dirname($config['history_csv']);
    echo json_encode([
        'ok'                 => true,
        'php_version'        => PHP_VERSION,
        'merge_rows_defined' => function_exists('mergeRows'),
        'data_dir'           => $dataDir,
        'data_dir_writable'  => is_dir($dataDir) ? is_writable($dataDir) : is_writable(dirname($dataDir)),
        'csv_exists'         => is_file($config['history_csv']),
        'csv_beers'          => is_file($config['history_csv']) ? max(0, count(file($config['history_csv'])) - 1) : 0,
    ]);
    exit;
}

// --- Payload einlesen --------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonFail(405, 'Nur POST erlaubt.');
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    jsonFail(400, 'Ungültiges JSON – erwartet: {"reset": bool, "items": [...], "state": {...}}.');
}

// --- Mergen und speichern ----------------------------------------------
try {
    $reset = !empty($payload['reset']);

    $beers    = $reset ? [] : loadHistory($config['history_csv']);
    $newBeers = mergeRows($beers, $payload['items']);

    saveHistory($config['history_csv'], $beers);

    // Fortsetzungspunkt pflegen
    $stateFile = dirname($config['history_csv']) . '/sync_state.json';
    $state     = $payload['state'] ?? null;

    if (is_array($state)) {
        if (!empty($state['complete'])) {
            if (is_file($stateFile)) {
                @unlink($stateFile);   // Sync fertig -> kein Fortsetzungspunkt mehr
            }
        } elseif (isset($state['next_offset'])) {
            file_put_contents($stateFile, json_encode([
                'next_offset' => max(0, (int) $state['next_offset']),
                'saved_at'    => date('c'),
            ]));
        }
    }
} catch (Throwable $e) {
    jsonFail(500, get_class($e) . ': ' . $e->getMessage()
        . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo json_encode([
    'ok'    => true,
    'new'   => $newBeers,
    'total' => count($beers),
]);
