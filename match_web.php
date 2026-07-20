<?php
// ---------------------------------------------------------------
// match_web.php – Bierliste gegen die Historie abgleichen
//
// Ablauf: Bierliste einfügen (eine Zeile pro Bier, Zusatzinfos wie
// Brauerei/Stil dürfen einfach mit in der Zeile stehen), abgleichen
// lassen, dann pro Bier den wahrscheinlichsten Treffer bestätigen
// oder eine Alternative bzw. "noch nicht getrunken" wählen.
// Das bestätigte Ergebnis wird als data/matched_list.csv gespeichert
// und ist die Grundlage für den Badge-Abgleich.
//
// Aufruf: match_web.php?token=...
// ---------------------------------------------------------------

declare(strict_types=1);

require __DIR__ . '/history_store.php';
require __DIR__ . '/matcher.php';

$config = require __DIR__ . '/config.php';

$expected = $config['sync_token'] ?? '';
$given    = $_GET['token'] ?? '';
if ($expected === '' || $expected === 'BITTE_AENDERN' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('Zugriff verweigert: gültiger ?token=... erforderlich (siehe config.php).');
}

/** Ab diesem Score gilt ein bester Treffer als "sicher". */
const SCORE_CONFIDENT = 0.72;

/**
 * Mindestabstand zum zweitbesten Kandidaten, damit ein Treffer trotz hohen
 * Scores als "sicher" gilt. Liegen zwei Kandidaten nah beieinander (z.B.
 * mehrere "Hell"-Biere), ist das echte Mehrdeutigkeit, kein sicherer Treffer.
 */
const SCORE_MARGIN = 0.08;

// ---------------------------------------------------------------
// POST-Aktionen (werden vom JavaScript der Seite aufgerufen)
// ---------------------------------------------------------------
$action = $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Ungültiges JSON.']));
    }

    try {
        $beers = loadHistory($config['history_csv']);
    } catch (RuntimeException $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
    }

    if ($action === 'match') {
        $lines = $payload['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            http_response_code(400);
            exit(json_encode(['ok' => false, 'error' => 'Keine Zeilen übergeben.']));
        }

        $index   = buildMatchIndex($beers);
        $results = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $candidates = [];
            foreach (findCandidates($line, $beers, $index) as $cand) {
                $beer         = $beers[$cand['beer_id']];
                $candidates[] = [
                    'beer_id' => $cand['beer_id'],
                    'score'   => $cand['score'],
                    'name'    => $beer['beer_name'],
                    'brewery' => $beer['brewery'],
                    'style'   => $beer['style'],
                ];
            }

            $status = 'neu';
            if ($candidates !== []) {
                $topScore    = $candidates[0]['score'];
                $secondScore = $candidates[1]['score'] ?? 0.0;
                $confident   = $topScore >= SCORE_CONFIDENT && ($topScore - $secondScore) >= SCORE_MARGIN;
                $status      = $confident ? 'sicher' : 'unsicher';
            }

            $results[] = ['input' => $line, 'status' => $status, 'candidates' => $candidates];
        }

        exit(json_encode(['ok' => true, 'results' => $results]));
    }

    if ($action === 'rerank') {
        // Sortiert einen Pool von Katalog-Kandidaten (von Untappds eigener
        // Suche geliefert) nach Textähnlichkeit zur ursprünglichen Zeile neu.
        // Untappds Suche ist gut darin, überhaupt passende Kandidaten zu
        // FINDEN, aber ihre Rangfolge stimmt nicht immer mit der reinen
        // Textähnlichkeit überein – dafür nutzen wir denselben, bereits
        // getesteten Scorer wie beim lokalen Historienabgleich.
        $query = trim((string) ($payload['query'] ?? ''));
        $items = $payload['items'] ?? [];
        if ($query === '' || !is_array($items)) {
            http_response_code(400);
            exit(json_encode(['ok' => false, 'error' => 'query und items erforderlich.']));
        }

        $queryTokens = matchTokens($query);
        $scored      = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['bid'])) {
                continue;
            }
            $targetTokens = matchTokens(($item['name'] ?? '') . ' ' . ($item['brewery'] ?? ''));
            $scored[] = $item + ['score' => round(scoreMatch($queryTokens, $targetTokens), 3)];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        exit(json_encode(['ok' => true, 'items' => $scored]));
    }

    if ($action === 'save') {
        $rows = $payload['rows'] ?? [];
        if (!is_array($rows)) {
            http_response_code(400);
            exit(json_encode(['ok' => false, 'error' => 'Keine Zeilen übergeben.']));
        }

        $path = dirname($config['history_csv']) . '/matched_list.csv';
        $tmp  = $path . '.tmp';

        $handle = fopen($tmp, 'w');
        if ($handle === false) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => "Kann $tmp nicht schreiben."]));
        }

        fputcsv($handle, ['input', 'status', 'beer_id', 'beer_name', 'brewery', 'style']);

        $drunk = 0;
        $new   = 0;

        foreach ($rows as $row) {
            $input  = trim((string) ($row['input'] ?? ''));
            $beerId = (int) ($row['beer_id'] ?? 0);
            if ($input === '') {
                continue;
            }

            if ($beerId !== 0 && isset($beers[$beerId])) {
                $beer = $beers[$beerId];
                fputcsv($handle, [$input, 'getrunken', $beerId, $beer['beer_name'], $beer['brewery'], $beer['style']]);
                $drunk++;
            } else {
                fputcsv($handle, [$input, 'neu', '', '', '', '']);
                $new++;
            }
        }

        fclose($handle);

        if (!rename($tmp, $path)) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => "Kann $tmp nicht nach $path verschieben."]));
        }

        exit(json_encode(['ok' => true, 'drunk' => $drunk, 'new' => $new]));
    }

    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => "Unbekannte Aktion: $action"]));
}

// ---------------------------------------------------------------
// Seite ausliefern
// ---------------------------------------------------------------
try {
    $historyBeers = loadHistory($config['history_csv']);
    $cacheCount   = count($historyBeers);
    $knownIds     = array_keys($historyBeers);
} catch (RuntimeException $e) {
    $cacheCount = 0;
    $knownIds   = [];
}

// Alter des Caches für den Sync-Hinweis
$cacheAge = '';
if (is_file($config['history_csv'])) {
    $ageSeconds = time() - (int) filemtime($config['history_csv']);
    $ageDays    = (int) floor($ageSeconds / 86400);
    $ageHours   = (int) floor($ageSeconds / 3600);
    $cacheAge   = $ageDays >= 1
        ? ($ageDays === 1 ? 'vor 1 Tag' : "vor $ageDays Tagen")
        : ($ageHours >= 1 ? ($ageHours === 1 ? 'vor 1 Stunde' : "vor $ageHours Stunden") : 'vor wenigen Minuten');
}

// Liegt ein unterbrochener Sync vor? Dann Hinweis deutlicher machen.
$stateFile   = dirname($config['history_csv']) . '/sync_state.json';
$syncPending = is_readable($stateFile);

$syncUrl = 'sync_web.php?token=' . rawurlencode($expected);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Bierliste abgleichen</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 44em; margin: 1em auto; padding: 0 1em; }
    h1   { font-size: 1.3em; }
    textarea { width: 100%; min-height: 10em; font-size: 1em; box-sizing: border-box; }
    button   { font-size: 1.1em; padding: .6em 1.4em; border-radius: 6px; border: 1px solid #888;
               cursor: pointer; margin: .5em 0; }
    /* Kompakte Ergebnisliste: eine Zeile pro Bier, Details nur auf Wunsch */
    .row     { border-bottom: 1px solid #e5e5e5; border-left: 4px solid transparent;
               padding: .5em 0 .5em .6em; }
    .row.neu-row  { background: #fff6e0; border-left-color: #e8a33d; }
    .line1   { display: flex; align-items: baseline; gap: .45em; }
    .status-mark { flex: none; }
    .status-mark.sicher, .status-mark.unsicher { width: .7em; height: .7em; border-radius: 50%; }
    .status-mark.sicher   { background: #1a7a1a; }
    .status-mark.unsicher { background: #e5a50a; }
    .status-mark.neu      { background: #e8a33d; color: #fff; font-size: .68em; font-weight: 700;
                             letter-spacing: .03em; text-transform: uppercase; border-radius: 4px;
                             padding: .2em .45em; }
    .input   { font-weight: 600; }
    .arrow   { color: #999; }
    .hit     { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .hit.new-hit { color: #a5691c; font-weight: 700; white-space: normal; }
    .score   { color: #999; font-size: .8em; flex: none; }
    details  { margin: .2em 0 0 1.1em; }
    summary  { cursor: pointer; color: #666; font-size: .85em; }
    details label { display: block; padding: .3em 0; font-size: .95em; }
    .live-search { margin: .35em 0 0 1.1em; }
    .live-hit { display: flex; align-items: baseline; gap: .4em; padding: .2em 0; }
    .live-hit .hit { font-size: .95em; }
    .live-hit-drunk { background: #eaf7ea; border-radius: 4px; padding-left: .3em; }
    .drunk-check { flex: none; color: #1a7a1a; font-weight: 700; }
    .ulink    { font-size: .8em; color: #b8791a; text-decoration: none; white-space: nowrap;
                border: 1px solid #e0b070; border-radius: 999px; padding: .05em .45em; margin-left: .3em;
                flex: none; }
    .meta     { color: #666; font-size: .9em; }
    .hint     { background: #f4f4f4; border-radius: 6px; padding: .6em .8em; margin: .6em 0; font-size: .92em; }
    .hint.pending { background: #fdf0d5; }
    .ok    { color: #1a7a1a; font-weight: bold; }
    .error { color: #b00020; font-weight: bold; }
    #summary { margin-top: 1em; }
    #counts  { font-size: .9em; color: #555; margin: .6em 0 .2em; }
    #counts .new-count { color: #a5691c; font-weight: 700; }
</style>
</head>
<body>
<h1>Bierliste abgleichen</h1>

<?php if ($syncPending): ?>
<div class="hint pending">
    <strong>Unterbrochener Sync:</strong> deine Historie ist unvollständig –
    Ergebnisse können daher Biere als „neu“ zeigen, die du längst hattest.
    <a href="<?= htmlspecialchars($syncUrl) ?>">Sync fortsetzen&nbsp;→</a>
</div>
<?php else: ?>
<div class="hint">
    <strong><?= $cacheCount ?> Biere</strong> in deiner Historie<?= $cacheAge ? ', zuletzt aktualisiert ' . htmlspecialchars($cacheAge) : '' ?>.
    <a href="<?= htmlspecialchars($syncUrl) ?>">Vorher synchronisieren&nbsp;→</a>
</div>
<?php endif; ?>

<div id="inputArea">
<textarea id="beerlist" placeholder="Augustiner Helles
Tegernseer Hell
Dogfish Head 90 Minute IPA"></textarea>
<button id="matchBtn">Abgleichen</button>
</div>

<div id="counts"></div>
<div id="results"></div>
<button id="saveBtn" style="display:none">Ergebnis speichern</button>
<div id="summary"></div>

<script>
const TOKEN = <?= json_encode($expected) ?>;
const CFG   = <?= json_encode([
    'clientId'     => $config['client_id'],
    'clientSecret' => $config['client_secret'],
    'knownIds'     => $knownIds,
]) ?>;
const KNOWN_IDS = new Set(CFG.knownIds);

async function post(action, body) {
    const response = await fetch('match_web.php?token=' + encodeURIComponent(TOKEN) + '&action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); }
    catch (e) {
        throw new Error('Server-Antwort kein JSON (HTTP ' + response.status + '): "' + text.slice(0, 150) + '"');
    }
    if (!result.ok) throw new Error(result.error || 'unbekannter Fehler');
    return result;
}

const esc = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

// --- Live-Suche im Untappd-Katalog für "noch nicht getrunken"-Zeilen -----
// Läuft aus dem Browser (nicht vom Server), damit Cloudflare die Anfrage
// nicht blockt – gleiches Prinzip wie beim Sync.
function untappdSearchUrl(query) {
    return 'https://api.untappd.com/v4/search/beer?q=' + encodeURIComponent(query)
         + '&client_id=' + encodeURIComponent(CFG.clientId)
         + '&client_secret=' + encodeURIComponent(CFG.clientSecret)
         + '&limit=5';
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

let useJsonpSearch = false;

async function untappdSearch(query) {
    const url = untappdSearchUrl(query);
    if (!useJsonpSearch) {
        try {
            const response = await fetch(url, { referrerPolicy: 'no-referrer' });
            return await response.json();
        } catch (e) {
            useJsonpSearch = true;
        }
    }
    return jsonp(url);
}

// Holt Katalogkandidaten von Untappd und sortiert sie nach Textähnlichkeit
// zur ursprünglichen Zeile neu. Untappds eigene Suche ist gut darin,
// überhaupt passende Kandidaten zu FINDEN, aber ihre interne Rangfolge
// stimmt nicht immer mit reiner Textähnlichkeit überein (z.B. "DDF M*rs"
// vor "Double M*rs" bei der Anfrage "Double Mars") – dafür nutzt der
// Server denselben, bereits getesteten Scorer wie beim lokalen Abgleich.
async function searchAndRerank(query) {
    const data = await untappdSearch(query);
    const meta = data && data.meta ? data.meta : {};
    if (meta.code !== 200) {
        const rateLimited = meta.code === 429 || /limit/i.test(meta.error_detail || '');
        return { status: rateLimited ? 'rate_limited' : 'error', items: [] };
    }

    const rawItems = (((data.response || {}).beers || {}).items) || [];
    if (rawItems.length === 0) {
        return { status: 'ok', items: [] };
    }

    const simplified = rawItems.slice(0, 10).map(item => ({
        bid: (item.beer || {}).bid,
        name: (item.beer || {}).beer_name || '?',
        brewery: (item.brewery || {}).brewery_name || '',
        style: (item.beer || {}).beer_style || '',
    })).filter(it => it.bid);

    try {
        const result = await post('rerank', { query, items: simplified });
        return { status: 'ok', items: result.items };
    } catch (e) {
        // Neusortierung fehlgeschlagen (z.B. Serverproblem) -> Untappds
        // eigene Reihenfolge als Fallback, besser als gar nichts.
        return { status: 'ok', items: simplified.map(it => ({ ...it, score: null })) };
    }
}

// Rendert die Katalog-Kandidaten (bereits nach Textähnlichkeit sortiert)
// und markiert jeden, der laut KNOWN_IDS bereits in deiner Historie steckt
// (rein informativ, Häkchen). Für die automatische Übernahme als "sicher"
// zählt dagegen NUR, ob der nach unserer eigenen Sortierung beste Treffer
// (Position 0) bekannt ist – ein zufällig weiter unten stehender bekannter
// Treffer soll nicht automatisch bestätigen, dass er zu DIESER Zeile gehört.
// Rückgabe: { status, topRankedDrunkItem, hasResults }.
function renderCatalogResults(container, result) {
    if (result.status === 'rate_limited' || result.status === 'error') {
        container.innerHTML = '<span class="meta">'
            + (result.status === 'rate_limited' ? 'Untappd-Rate-Limit erreicht – bitte später erneut versuchen.'
                                                 : 'Untappd-Suche fehlgeschlagen.')
            + '</span>';
        return { status: result.status, topRankedDrunkItem: null, hasResults: false };
    }

    const items = result.items;
    if (items.length === 0) {
        container.innerHTML = '<span class="meta">Kein Untappd-Treffer.</span>';
        return { status: 'ok', topRankedDrunkItem: null, hasResults: false };
    }

    const topRankedDrunkItem = KNOWN_IDS.has(items[0].bid) ? items[0] : null;

    let html = '';
    items.slice(0, 3).forEach(it => {
        const isKnown = KNOWN_IDS.has(it.bid);
        html += '<div class="live-hit' + (isKnown ? ' live-hit-drunk' : '') + '">'
             + (isKnown ? '<span class="drunk-check" title="Bereits in deiner Historie">✓</span>' : '')
             + '<span class="hit">' + esc(it.name) + ' <span class="meta">· '
             + esc(it.brewery) + ' · ' + esc(it.style) + '</span></span>'
             + (it.score != null ? '<span class="score">' + Math.round(it.score * 100) + '%</span>' : '')
             + '<a class="ulink" href="https://untappd.com/beer/' + it.bid
             + '" target="_blank" rel="noopener noreferrer">Check-in&nbsp;↗</a>'
             + '</div>';
    });
    container.innerHTML = html;
    return { status: 'ok', topRankedDrunkItem, hasResults: true };
}

// Baut den kompletten Zeileninhalt aus dem aktuellen (ggf. durch die
// Katalogprüfung aktualisierten) Zustand von r. Wird sowohl beim ersten
// Rendern als auch nach der Katalogverifikation erneut aufgerufen.
function buildRowHtml(r, i) {
    const selected = r.candidates.find(c => c.beer_id === r.selectedId);

    let html = '<div class="line1">'
         + '<span class="status-mark ' + r.status + '">' + (r.status === 'neu' ? 'NEU' : '') + '</span>'
         + '<span class="input">' + esc(r.input) + '</span>';

    if (selected) {
        html += '<span class="arrow">→</span>'
             + '<span class="hit">' + esc(selected.name) + ' <span class="meta">· '
             + esc(selected.brewery) + '</span></span>'
             + (selected.score != null ? '<span class="score">' + Math.round(selected.score * 100) + '%</span>' : '')
             + '<a class="ulink" href="https://untappd.com/beer/' + selected.beer_id
             + '" target="_blank" rel="noopener noreferrer">Check-in&nbsp;↗</a>';
    } else {
        html += '<span class="hit new-hit">noch nicht getrunken</span>'
             + '<a class="ulink" href="https://untappd.com/search?q=' + encodeURIComponent(r.input)
             + '" target="_blank" rel="noopener noreferrer">Suche&nbsp;↗</a>';
    }
    html += '</div>';

    html += '<div class="live-search"><div class="live-results"' + (r._liveHtml ? '' : ' data-pending="1"') + '>'
         + (r._liveHtml || '<span class="meta">Untappd-Katalog wird geprüft …</span>')
         + '</div></div>';

    if (r.candidates.length > 0) {
        html += '<details><summary>'
             + (r.candidates.length > 1 ? r.candidates.length + ' Treffer – ändern' : 'ändern')
             + '</summary>';

        r.candidates.forEach(c => {
            const checked = c.beer_id === r.selectedId ? 'checked' : '';
            html += '<label><input type="radio" name="m' + i + '" value="' + c.beer_id + '" ' + checked + '> '
                 + esc(c.name) + ' <span class="meta">· ' + esc(c.brewery) + (c.style ? ' · ' + esc(c.style) : '') + '</span>'
                 + (c.score != null ? ' <span class="score">' + Math.round(c.score * 100) + '%</span>' : ' <span class="meta">(Katalog)</span>')
                 + ' <a class="ulink" href="https://untappd.com/beer/' + c.beer_id
                 + '" target="_blank" rel="noopener noreferrer">↗</a></label>';
        });

        html += '<label><input type="radio" name="m' + i + '" value="0" ' + (r.selectedId === 0 ? 'checked' : '') + '> '
             + '<em>Noch nicht getrunken / kein Treffer</em>'
             + ' <a class="ulink" href="https://untappd.com/search?q=' + encodeURIComponent(r.input)
             + '" target="_blank" rel="noopener noreferrer">Suche&nbsp;↗</a></label>';
        html += '</details>';
    } else {
        html += '<input type="radio" name="m' + i + '" value="0" checked hidden>';
    }

    return html;
}

// Prüft jede Zeile gegen den echten Katalog und gleicht die gefundene(n)
// Bier-ID(s) exakt gegen deine Historie ab (KNOWN_IDS) – das ersetzt die
// unscharfe lokale Vermutung durch einen echten Ja/Nein-Abgleich, sobald
// das Ergebnis da ist. Läuft nacheinander (Rate-Limit-Schonung) und bricht
// bei einem Limit sauber ab, ohne bereits Geprüftes zu verwerfen.
async function verifyRowsAgainstCatalog(rows) {
    for (const { row, r, i } of rows) {
        const container = row.querySelector('.live-results');
        const result = await searchAndRerank(r.input);
        const { status, topRankedDrunkItem, hasResults } = renderCatalogResults(container, result);
        r._liveHtml = container.innerHTML;
        container.removeAttribute('data-pending');   // fertig – dieser Container zählt nicht mehr als "offen"

        if (status === 'rate_limited') {
            rows.forEach(({ row: pendingRow }) => {
                const c = pendingRow.querySelector('.live-results[data-pending]');
                if (c) {
                    c.innerHTML = '<span class="meta">Nicht mehr geprüft (Rate-Limit) – '
                        + 'manuell über die Suche prüfen.</span>';
                }
            });
            break;
        }

        // Nur automatisch übernehmen, wenn die Person diese Zeile noch nicht
        // selbst verändert hat (ein Klick soll nie überschrieben werden) und
        // die Suche selbst geklappt hat (bei einem Fehler bleibt der lokale
        // Stand einfach unverändert stehen).
        if (status === 'ok' && !row.dataset.userTouched) {
            if (topRankedDrunkItem) {
                // Bester Katalogtreffer (nach unserer eigenen Sortierung!) ist
                // in deiner Historie -> sicher bestätigt.
                const it = topRankedDrunkItem;
                if (!r.candidates.some(c => c.beer_id === it.bid)) {
                    r.candidates.unshift({
                        beer_id: it.bid, name: it.name, brewery: it.brewery,
                        style: it.style, score: it.score,
                    });
                }
                r.status = 'sicher';
                r.selectedId = it.bid;
            } else if (hasResults) {
                // Katalog kennt Kandidaten für diese Zeile, aber keiner davon
                // ist in deiner Historie -> zuverlässig "noch nicht getrunken",
                // auch wenn der lokale Abgleich vorher unsicher (oder fälschlich
                // sicher) war. Das ist der eigentliche Zweck der Katalogprüfung.
                r.status = 'neu';
                r.selectedId = 0;
            }
            // Sonst (Katalog fand überhaupt nichts): keine zusätzliche
            // Information -> lokalen Stand unverändert lassen.

            row.className = 'row' + (r.status === 'neu' ? ' neu-row' : '');
            row.innerHTML = buildRowHtml(r, i);
        }

        updateCounts();
        await new Promise(res => setTimeout(res, 250));   // kleine Pause zwischen Anfragen
    }
}

let currentResults = [];

function updateCounts() {
    const counts = currentResults.reduce((acc, r) => { acc[r.status]++; return acc; },
        { sicher: 0, unsicher: 0, neu: 0 });
    document.getElementById('counts').innerHTML =
        counts.sicher + ' Treffer · ' + counts.unsicher + ' zu prüfen · '
        + '<span class="new-count">' + counts.neu + ' noch nicht getrunken</span>';
}


document.getElementById('matchBtn').addEventListener('click', async () => {
    const lines = document.getElementById('beerlist').value.split('\n');
    const resultsEl = document.getElementById('results');
    const summaryEl = document.getElementById('summary');
    summaryEl.innerHTML = '';
    resultsEl.innerHTML = 'Gleiche ab …';

    try {
        const { results } = await post('match', { lines });
        resultsEl.innerHTML = '';
        currentResults = results;
        const verifyQueue = [];

        results.forEach((r, i) => {
            const row = document.createElement('div');
            row.className = 'row' + (r.status === 'neu' ? ' neu-row' : '');
            row.dataset.input = r.input;

            // "sicher" startet vorausgewählt mit dem Top-Kandidaten, sonst
            // ist "noch nicht getrunken" der sichere Default – siehe unten
            // beim Katalogabgleich, der das ggf. überschreibt.
            r.selectedId = (r.status === 'sicher' && r.candidates[0]) ? r.candidates[0].beer_id : 0;

            row.innerHTML = buildRowHtml(r, i);

            // Auswahl ändern -> Kopfzeile der Zeile mitziehen, und als von
            // der Person bearbeitet markieren (Katalogabgleich überschreibt
            // dann keine manuelle Entscheidung mehr).
            row.addEventListener('change', e => {
                if (e.target.type !== 'radio') return;
                row.dataset.userTouched = '1';

                const id   = parseInt(e.target.value, 10);
                const hit  = row.querySelector('.line1 .hit');
                const mark = row.querySelector('.status-mark');
                const cand = r.candidates.find(c => c.beer_id === id);

                if (cand) {
                    hit.className = 'hit';
                    hit.innerHTML = esc(cand.name) + ' <span class="meta">· ' + esc(cand.brewery) + '</span>';
                    mark.className = 'status-mark sicher';
                    mark.textContent = '';
                    row.classList.remove('neu-row');
                    r.status = 'sicher';
                } else {
                    hit.className = 'hit new-hit';
                    hit.textContent = 'noch nicht getrunken';
                    mark.className = 'status-mark neu';
                    mark.textContent = 'NEU';
                    row.classList.add('neu-row');
                    r.status = 'neu';
                }
                updateCounts();
            });

            resultsEl.appendChild(row);
            verifyQueue.push({ row, r, i });
        });

        updateCounts();
        document.getElementById('saveBtn').style.display = results.length ? 'inline-block' : 'none';

        if (verifyQueue.length > 0) {
            verifyRowsAgainstCatalog(verifyQueue);   // läuft im Hintergrund weiter
        }
    } catch (e) {
        resultsEl.innerHTML = '<span class="error">FEHLER: ' + esc(e.message) + '</span>';
    }
});

document.getElementById('saveBtn').addEventListener('click', async () => {
    const rows = [...document.querySelectorAll('#results .row')].map(row => {
        const chosen = row.querySelector('input[type=radio]:checked');
        return { input: row.dataset.input, beer_id: chosen ? parseInt(chosen.value, 10) : 0 };
    });

    const summaryEl = document.getElementById('summary');
    try {
        const result = await post('save', { rows });
        summaryEl.innerHTML = '<span class="ok">Gespeichert: ' + result.drunk + ' schon getrunken, '
            + result.new + ' noch nicht getrunken.</span>';
    } catch (e) {
        summaryEl.innerHTML = '<span class="error">FEHLER: ' + esc(e.message) + '</span>';
    }
});
</script>
<p class="meta" style="margin-top:2em;font-size:.75em;">Matching-Engine v<?= MATCHER_VERSION ?></p>
</body>
</html>
