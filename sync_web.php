<?php
// ---------------------------------------------------------------
// sync_web.php – Browser-Relay-Sync (abbruchsicher & fortsetzbar)
//
// Ruft die Untappd-API per JavaScript direkt aus DEINEM Browser auf
// (deine Handy-/Heim-IP kommt durch Cloudflare, die Hoster-IP nicht)
// und lädt die Ergebnisse alle paar Seiten an receive_sync.php hoch.
//
// Bricht der Sync ab (z.B. Untappd-Rate-Limit nach 100 Calls/Stunde),
// ist alles bis dahin Gelesene bereits gespeichert und der Server
// merkt sich den Fortsetzungspunkt (data/sync_state.json). Der
// nächste Aufruf dieser Seite macht automatisch dort weiter – so
// klappt auch ein Full-Sync mit >5000 Bieren über mehrere
// Stundenfenster hinweg.
//
// Aufruf: sync_web.php?token=...        inkrementell
//         sync_web.php?token=...&full=1 kompletter Neuaufbau
//         (liegt ein Fortsetzungspunkt vor, hat dieser Vorrang)
// ---------------------------------------------------------------

declare(strict_types=1);

require __DIR__ . '/history_store.php';

$config = require __DIR__ . '/config.php';

$expected = $config['sync_token'] ?? '';
$given    = $_GET['token'] ?? '';
if ($expected === '' || $expected === 'BITTE_AENDERN' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('Zugriff verweigert: gültiger ?token=... erforderlich (siehe config.php).');
}

if ($config['client_id'] === 'DEIN_CLIENT_ID') {
    http_response_code(500);
    exit('Bitte zuerst config.php ausfüllen (client_id, client_secret, username).');
}

$fullSync = !empty($_GET['full']);

// Unterbrochenen Sync erkennen: Fortsetzungspunkt hat Vorrang vor allem,
// sonst entstünden Lücken im Cache (inkrementell würde zu früh stoppen).
$stateFile    = dirname($config['history_csv']) . '/sync_state.json';
$resumeOffset = 0;
if (is_readable($stateFile)) {
    $state        = json_decode((string) file_get_contents($stateFile), true);
    $resumeOffset = max(0, (int) ($state['next_offset'] ?? 0));
}

try {
    $knownIds = array_keys(loadHistory($config['history_csv']));
} catch (RuntimeException $e) {
    $knownIds = [];
}

if ($resumeOffset > 0) {
    $mode      = 'resume';
    $modeLabel = "Fortsetzung des unterbrochenen Syncs ab Offset $resumeOffset";
} elseif ($fullSync) {
    $mode      = 'full';
    $modeLabel = 'Voller Sync (Cache wird neu aufgebaut)';
} else {
    $mode      = 'incremental';
    $modeLabel = 'Inkrementell (' . count($knownIds) . ' Biere im Cache)';
}

$jsConfig = json_encode([
    'clientId'     => $config['client_id'],
    'clientSecret' => $config['client_secret'],
    'username'     => $config['username'],
    'token'        => $expected,
    'mode'         => $mode,
    'resumeOffset' => $resumeOffset,
    'knownIds'     => $mode === 'incremental' ? $knownIds : [],
]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Untappd-Sync</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 40em; margin: 1em auto; padding: 0 1em; }
    h1   { font-size: 1.3em; }
    pre  { background: #f4f4f4; padding: .8em; border-radius: 6px; white-space: pre-wrap; min-height: 8em; }
    button { font-size: 1.1em; padding: .6em 1.4em; border-radius: 6px; border: 1px solid #888; cursor: pointer; }
    .ok    { color: #1a7a1a; font-weight: bold; }
    .warn  { color: #9a6700; font-weight: bold; }
    .error { color: #b00020; font-weight: bold; }
</style>
</head>
<body>
<h1>Untappd-Sync (Browser-Relay)</h1>
<p>Modus: <strong><?= htmlspecialchars($modeLabel) ?></strong></p>
<button id="start">Sync starten</button>
<pre id="log">Bereit.</pre>

<script>
const CFG = <?= $jsConfig ?>;
const PAGE_SIZE          = 50;
const UPLOAD_EVERY_PAGES = 10;   // alle 10 Seiten (500 Biere) zwischenspeichern

const log = (msg, cls) => {
    const el = document.getElementById('log');
    el.innerHTML += (cls ? `<span class="${cls}">${msg}</span>` : msg) + "\n";
    el.scrollTop = el.scrollHeight;
};

// --- Untappd-API: fetch mit JSONP-Fallback (falls CORS blockt) ----------
function apiUrl(offset) {
    return 'https://api.untappd.com/v4/user/beers/' + encodeURIComponent(CFG.username)
         + '?client_id=' + encodeURIComponent(CFG.clientId)
         + '&client_secret=' + encodeURIComponent(CFG.clientSecret)
         + '&limit=' + PAGE_SIZE + '&offset=' + offset;
}

function jsonp(url) {
    return new Promise((resolve, reject) => {
        const cb = 'untappd_cb_' + Math.random().toString(36).slice(2);
        const script = document.createElement('script');
        const cleanup = () => { delete window[cb]; script.remove(); };
        window[cb] = data => { cleanup(); resolve(data); };
        script.onerror = () => { cleanup(); reject(new Error('JSONP-Aufruf fehlgeschlagen')); };
        script.src = url + '&callback=' + cb;
        document.head.appendChild(script);
    });
}

let useJsonp = false;

async function fetchPage(offset) {
    const url = apiUrl(offset);

    if (!useJsonp) {
        try {
            const response = await fetch(url, { referrerPolicy: 'no-referrer' });
            return await response.json();
        } catch (e) {
            log('fetch blockiert (vermutlich CORS) – wechsle auf JSONP …');
            useJsonp = true;
        }
    }
    return jsonp(url);
}

// --- Upload an unseren Server --------------------------------------------
// API-Item auf unsere 8 CSV-Felder eindampfen (spart >95% Upload-Volumen)
function toRow(item) {
    const beer = item.beer || {}, brewery = item.brewery || {};
    return {
        beer_id:       beer.bid || 0,
        beer_name:     beer.beer_name || '',
        brewery:       brewery.brewery_name || '',
        style:         beer.beer_style || '',
        abv:           beer.beer_abv != null ? String(beer.beer_abv) : '',
        my_rating:     item.rating_score != null ? String(item.rating_score) : '',
        first_had:     item.first_had || '',
        checkin_count: item.count || 1,
    };
}

async function upload(rows, reset, nextOffset, complete) {
    const response = await fetch('receive_sync.php?token=' + encodeURIComponent(CFG.token), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            reset: reset,
            items: rows,
            state: { next_offset: nextOffset, complete: complete },
        }),
    });

    const text = await response.text();
    let result;
    try {
        result = JSON.parse(text);
    } catch (e) {
        throw new Error('Server-Antwort kein JSON (HTTP ' + response.status + '): "'
            + text.slice(0, 150) + '"');
    }
    if (!result.ok) {
        throw new Error('Server: ' + (result.error || 'unbekannter Fehler'));
    }
    return result;
}

// --- Hauptablauf ---------------------------------------------------------
async function runSync() {
    document.getElementById('start').disabled = true;

    const knownIds  = new Set(CFG.knownIds);
    let offset      = CFG.mode === 'resume' ? CFG.resumeOffset : 0;
    let firstUpload = CFG.mode === 'full';   // nur frischer Full-Sync leert den Cache
    let pending     = [];                    // seit letztem Upload gesammelte Zeilen
    let pagesSinceUpload = 0;
    let totalNew    = 0;
    let lastTotal   = CFG.knownIds.length;

    // Zwischenstand sichern; complete=false schreibt den Fortsetzungspunkt
    async function flush(nextOffset, complete) {
        if (pending.length === 0 && !complete && !firstUpload) {
            return;
        }
        const result = await upload(pending, firstUpload, nextOffset, complete);
        totalNew   += result.new;
        lastTotal   = result.total;
        firstUpload = false;
        pending     = [];
        pagesSinceUpload = 0;
        log('  → gespeichert (' + result.total + ' Biere im Cache)');
    }

    try {
        while (true) {
            let data;
            try {
                data = await fetchPage(offset);
            } catch (netErr) {
                // Netz weg o.ä.: Gesammeltes sichern, Fortsetzungspunkt setzen
                await flush(offset, false);
                throw new Error(netErr.message + ' – Zwischenstand gespeichert, '
                    + 'nächster Aufruf setzt bei Offset ' + offset + ' fort.');
            }

            const meta = data && data.meta ? data.meta : {};
            if (meta.code !== 200) {
                await flush(offset, false);
                const hint = (meta.code === 429 || /limit/i.test(meta.error_detail || ''))
                    ? ' Stundenlimit erreicht – in ca. 1 Stunde erneut aufrufen, es geht bei Offset ' + offset + ' weiter.'
                    : ' Zwischenstand gespeichert, Fortsetzung bei Offset ' + offset + '.';
                throw new Error('Untappd-API-Fehler ' + (meta.code ?? '?') + ': '
                    + (meta.error_detail || 'unbekannt') + hint);
            }

            const items = (((data.response || {}).beers || {}).items) || [];
            if (items.length === 0) break;

            let pageHadNewBeer = false;
            for (const item of items) {
                const row = toRow(item);
                if (!row.beer_id) continue;
                if (!knownIds.has(row.beer_id)) pageHadNewBeer = true;
                pending.push(row);
            }

            log('Seite ab Offset ' + offset + ': ' + items.length + ' Biere gelesen');
            offset += items.length;
            pagesSinceUpload++;

            if (pagesSinceUpload >= UPLOAD_EVERY_PAGES) {
                await flush(offset, false);
            }

            // Nur im inkrementellen Modus früh stoppen; bei full/resume
            // muss immer bis zum Ende gelesen werden
            if (CFG.mode === 'incremental' && !pageHadNewBeer) break;
            if (items.length < PAGE_SIZE) break;   // letzte Seite erreicht
        }

        await flush(offset, true);   // fertig: Fortsetzungspunkt löschen
        log('Fertig: ' + totalNew + ' neue Biere, ' + lastTotal + ' insgesamt im Cache.', 'ok');
    } catch (e) {
        log('FEHLER: ' + e.message, 'error');
        if (totalNew > 0) {
            log('Bereits übernommen: ' + totalNew + ' neue Biere (' + lastTotal + ' im Cache).', 'warn');
        }
    } finally {
        document.getElementById('start').disabled = false;
    }
}

document.getElementById('start').addEventListener('click', runSync);
</script>
</body>
</html>
