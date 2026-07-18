<?php
// ---------------------------------------------------------------
// history_store.php – gemeinsame CSV-Logik für den Bier-Cache
// Wird von sync_history.php (CLI/Server-Sync) und
// receive_sync.php (Browser-Relay) genutzt.
// ---------------------------------------------------------------

const CSV_HEADER = ['beer_id', 'beer_name', 'brewery', 'style', 'abv', 'my_rating', 'first_had', 'checkin_count'];

/** Liest den Cache ein; Rückgabe: Map beer_id => Zeilen-Array. */
function loadHistory(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("Kann $path nicht öffnen.");
    }

    $beers  = [];
    $header = fgetcsv($handle);   // Kopfzeile überspringen

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count(CSV_HEADER)) {
            continue;   // kaputte Zeile ignorieren statt Abbruch
        }
        $beers[(int) $row[0]] = array_combine(CSV_HEADER, $row);
    }

    fclose($handle);
    return $beers;
}

/** Schreibt den Cache atomar (Temp-Datei + rename). */
function saveHistory(string $path, array $beers): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException("Kann Verzeichnis $dir nicht anlegen.");
    }

    $tmp    = $path . '.tmp';
    $handle = fopen($tmp, 'w');
    if ($handle === false) {
        throw new RuntimeException("Kann $tmp nicht schreiben.");
    }

    fputcsv($handle, CSV_HEADER);

    foreach ($beers as $beer) {
        fputcsv($handle, array_values($beer));
    }

    fclose($handle);

    if (!rename($tmp, $path)) {
        throw new RuntimeException("Kann $tmp nicht nach $path verschieben.");
    }
}

/** Baut aus einem API-Item (Element aus response.beers.items) eine Cache-Zeile. */
function mapApiItem(array $item): array
{
    $beer    = $item['beer']    ?? [];
    $brewery = $item['brewery'] ?? [];

    return [
        'beer_id'       => (int)   ($beer['bid'] ?? 0),
        'beer_name'     => (string)($beer['beer_name'] ?? ''),
        'brewery'       => (string)($brewery['brewery_name'] ?? ''),
        'style'         => (string)($beer['beer_style'] ?? ''),
        'abv'           => (string)($beer['beer_abv'] ?? ''),
        'my_rating'     => (string)($item['rating_score'] ?? ''),
        'first_had'     => (string)($item['first_had'] ?? ''),
        'checkin_count' => (int)   ($item['count'] ?? 1),
    ];
}

/** Merged API-Items in einen bestehenden Cache. Rückgabe: Anzahl neuer Biere. */
function mergeApiItems(array &$beers, array $items): int
{
    $newBeers = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = mapApiItem($item);
        if ($row['beer_id'] === 0) {
            continue;
        }
        if (!isset($beers[$row['beer_id']])) {
            $newBeers++;
        }
        $beers[$row['beer_id']] = $row;
    }

    return $newBeers;
}

/**
 * Merged bereits kompakte Zeilen (Format wie CSV_HEADER) in den Cache.
 * Wird vom Browser-Relay genutzt, das die API-Items clientseitig
 * eindampft, um die Upload-Größe klein zu halten.
 * Rückgabe: Anzahl neuer Biere.
 */
function mergeRows(array &$beers, array $rows): int
{
    $newBeers = 0;

    foreach ($rows as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $beerId = (int) ($raw['beer_id'] ?? 0);
        if ($beerId === 0) {
            continue;
        }

        // Nur bekannte Spalten übernehmen, Typen normalisieren
        $row = [
            'beer_id'       => $beerId,
            'beer_name'     => (string)($raw['beer_name'] ?? ''),
            'brewery'       => (string)($raw['brewery'] ?? ''),
            'style'         => (string)($raw['style'] ?? ''),
            'abv'           => (string)($raw['abv'] ?? ''),
            'my_rating'     => (string)($raw['my_rating'] ?? ''),
            'first_had'     => (string)($raw['first_had'] ?? ''),
            'checkin_count' => (int)   ($raw['checkin_count'] ?? 1),
        ];

        if (!isset($beers[$beerId])) {
            $newBeers++;
        }
        $beers[$beerId] = $row;
    }

    return $newBeers;
}
