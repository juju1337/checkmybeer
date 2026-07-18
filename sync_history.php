<?php
// ---------------------------------------------------------------
// sync_history.php – holt deine distinkten Biere von Untappd
// und cached sie in data/my_beers.csv
//
// Aufruf (CLI):
//   php sync_history.php           inkrementell (nur neue Biere)
//   php sync_history.php --full    kompletter Neuaufbau des Caches
//
// Aufruf (Web):
//   sync_history.php?token=...           inkrementell
//   sync_history.php?token=...&full=1    kompletter Neuaufbau
//   Der Token wird in config.php gesetzt und schützt davor, dass
//   Fremde per Aufruf dein API-Stundenlimit leerbrennen.
//
// Inkrementell heißt: /user/beers liefert die Biere nach "zuletzt
// getrunken" sortiert. Sobald eine komplette Seite nur noch bereits
// bekannte beer_ids enthält, wird abgebrochen. Das kostet bei einem
// aktuellen Cache meist nur 1 API-Call.
// ---------------------------------------------------------------

declare(strict_types=1);

require __DIR__ . '/UntappdClient.php';
require __DIR__ . '/history_store.php';

const PAGE_SIZE = 50;

function fail(string $message): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "FEHLER: $message\n");
    } else {
        http_response_code(500);
        echo "FEHLER: $message\n";
    }
    exit(1);
}

// ---------- Hauptprogramm ---------------------------------------------------

$config = require __DIR__ . '/config.php';
$isCli  = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    // Web-Zugriff nur mit gültigem Token aus config.php
    $expected = $config['sync_token'] ?? '';
    $given    = $_GET['token'] ?? '';
    if ($expected === '' || $expected === 'BITTE_AENDERN' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit("Zugriff verweigert: gültiger ?token=... erforderlich (siehe config.php).\n");
    }
}

if ($config['client_id'] === 'DEIN_CLIENT_ID') {
    fail('Bitte zuerst config.php ausfüllen (client_id, client_secret, username).');
}

$fullSync = $isCli
    ? in_array('--full', $argv ?? [], true)
    : !empty($_GET['full']);

$historyPath = $config['history_csv'];

try {
    $beers = $fullSync ? [] : loadHistory($historyPath);
} catch (RuntimeException $e) {
    fail($e->getMessage());
}
$knownBefore = count($beers);

echo $fullSync
    ? "Voller Sync – Cache wird neu aufgebaut.\n"
    : "Inkrementeller Sync – $knownBefore Biere bereits im Cache.\n";

$client = new UntappdClient(
    $config['client_id'],
    $config['client_secret'],
    $config['min_remaining_calls'],
    $config['app_name'] ?? 'BadgeFinder'
);

$offset   = 0;
$newBeers = 0;

try {
    while (true) {
        $response = $client->get('/user/beers/' . rawurlencode($config['username']), [
            'limit'  => PAGE_SIZE,
            'offset' => $offset,
        ]);

        $items = $response['beers']['items'] ?? [];
        if ($items === []) {
            break;   // Ende der Liste erreicht
        }

        $pageHadNewBeer = false;

        foreach ($items as $item) {
            $row = mapApiItem($item);
            if ($row['beer_id'] === 0) {
                continue;
            }
            if (!isset($beers[$row['beer_id']])) {
                $newBeers++;
                $pageHadNewBeer = true;
            }
            // Immer überschreiben: hält rating/count aktuell, kostet nichts extra
            $beers[$row['beer_id']] = $row;
        }

        echo "  Seite ab Offset $offset: " . count($items) . " Biere gelesen"
           . " (übrige API-Calls: " . ($client->getRemainingCalls() ?? '?') . ")\n";

        if (!$isCli) {
            @ob_flush();
            flush();   // Fortschritt live im Browser anzeigen
        }

        // Inkrementell: Seite komplett bekannt -> alles Ältere haben wir schon
        if (!$fullSync && !$pageHadNewBeer) {
            break;
        }

        if (count($items) < PAGE_SIZE) {
            break;   // letzte Seite war nicht voll -> fertig
        }

        $offset += PAGE_SIZE;
    }
} catch (RuntimeException $e) {
    // Cache trotzdem speichern – was wir haben, haben wir
    saveHistory($historyPath, $beers);
    fail($e->getMessage() . ' (Zwischenstand wurde gespeichert.)');
}

saveHistory($historyPath, $beers);

echo "Fertig: $newBeers neue Biere, " . count($beers) . " insgesamt im Cache.\n";
echo "Cache-Datei: $historyPath\n";
