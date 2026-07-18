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
                $status = $candidates[0]['score'] >= SCORE_CONFIDENT ? 'sicher' : 'unsicher';
            }

            $results[] = ['input' => $line, 'status' => $status, 'candidates' => $candidates];
        }

        exit(json_encode(['ok' => true, 'results' => $results]));
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
    $cacheCount = count(loadHistory($config['history_csv']));
} catch (RuntimeException $e) {
    $cacheCount = 0;
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
    .row     { border-bottom: 1px solid #e5e5e5; padding: .45em 0; }
    .row.neu-row  { background: #fafafa; }
    .line1   { display: flex; align-items: baseline; gap: .4em; }
    .dot     { flex: none; width: .7em; height: .7em; border-radius: 50%; }
    .dot.sicher   { background: #1a7a1a; }
    .dot.unsicher { background: #e5a50a; }
    .dot.neu      { background: #bbb; }
    .input   { font-weight: 600; }
    .arrow   { color: #999; }
    .hit     { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .score   { color: #999; font-size: .8em; flex: none; }
    details  { margin: .2em 0 0 1.1em; }
    summary  { cursor: pointer; color: #666; font-size: .85em; }
    details label { display: block; padding: .3em 0; font-size: .95em; }
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

document.getElementById('matchBtn').addEventListener('click', async () => {
    const lines = document.getElementById('beerlist').value.split('\n');
    const resultsEl = document.getElementById('results');
    const summaryEl = document.getElementById('summary');
    summaryEl.innerHTML = '';
    resultsEl.innerHTML = 'Gleiche ab …';

    try {
        const { results } = await post('match', { lines });
        resultsEl.innerHTML = '';

        results.forEach((r, i) => {
            const row = document.createElement('div');
            row.className = 'row' + (r.status === 'neu' ? ' neu-row' : '');
            row.dataset.input = r.input;

            const best = r.candidates[0];

            // Zeile 1: Status-Punkt, Eingabe, bester Treffer (bzw. Untappd-Suche)
            let html = '<div class="line1">'
                 + '<span class="dot ' + r.status + '"></span>'
                 + '<span class="input">' + esc(r.input) + '</span>';

            if (best) {
                html += '<span class="arrow">→</span>'
                     + '<span class="hit">' + esc(best.name) + ' <span class="meta">· '
                     + esc(best.brewery) + '</span></span>'
                     + '<span class="score">' + Math.round(best.score * 100) + '%</span>'
                     + '<a class="ulink" href="https://untappd.com/beer/' + best.beer_id
                     + '" target="_blank" rel="noopener noreferrer">Check-in&nbsp;↗</a>';
            } else {
                html += '<span class="hit meta">noch nicht getrunken</span>'
                     + '<a class="ulink" href="https://untappd.com/search?q=' + encodeURIComponent(r.input)
                     + '" target="_blank" rel="noopener noreferrer">Suche&nbsp;↗</a>';
            }
            html += '</div>';

            // Zeile 2: Auswahl – immer eingeklappt, auch bei unsicherem Treffer
            if (best) {
                html += '<details><summary>'
                     + (r.candidates.length > 1 ? r.candidates.length + ' Treffer – ändern' : 'ändern')
                     + '</summary>';

                r.candidates.forEach((c, j) => {
                    const checked = j === 0 ? 'checked' : '';
                    html += '<label><input type="radio" name="m' + i + '" value="' + c.beer_id + '" ' + checked + '> '
                         + esc(c.name) + ' <span class="meta">· ' + esc(c.brewery) + ' · ' + esc(c.style) + '</span>'
                         + ' <span class="score">' + Math.round(c.score * 100) + '%</span>'
                         + ' <a class="ulink" href="https://untappd.com/beer/' + c.beer_id
                         + '" target="_blank" rel="noopener noreferrer">↗</a></label>';
                });

                html += '<label><input type="radio" name="m' + i + '" value="0"> '
                     + '<em>Noch nicht getrunken / kein Treffer</em>'
                     + ' <a class="ulink" href="https://untappd.com/search?q=' + encodeURIComponent(r.input)
                     + '" target="_blank" rel="noopener noreferrer">Suche&nbsp;↗</a></label>';
                html += '</details>';
            } else {
                // Ohne Kandidaten: unsichtbare Vorauswahl "kein Treffer"
                html += '<input type="radio" name="m' + i + '" value="0" checked hidden>';
            }

            row.innerHTML = html;

            // Auswahl ändern -> Kopfzeile der Zeile mitziehen
            row.addEventListener('change', e => {
                if (e.target.type !== 'radio') return;
                const id   = parseInt(e.target.value, 10);
                const hit  = row.querySelector('.line1 .hit');
                const dot  = row.querySelector('.dot');
                const cand = r.candidates.find(c => c.beer_id === id);

                if (cand) {
                    hit.className = 'hit';
                    hit.innerHTML = esc(cand.name) + ' <span class="meta">· ' + esc(cand.brewery) + '</span>';
                    dot.className = 'dot sicher';
                    row.classList.remove('neu-row');
                } else {
                    hit.className = 'hit meta';
                    hit.textContent = 'noch nicht getrunken';
                    dot.className = 'dot neu';
                    row.classList.add('neu-row');
                }
            });

            resultsEl.appendChild(row);
        });

        const counts = results.reduce((acc, r) => { acc[r.status]++; return acc; },
            { sicher: 0, unsicher: 0, neu: 0 });
        document.getElementById('counts').textContent =
            counts.sicher + ' Treffer · ' + counts.unsicher + ' zu prüfen · ' + counts.neu + ' noch nicht getrunken';

        document.getElementById('saveBtn').style.display = results.length ? 'inline-block' : 'none';
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
</body>
</html>
