<?php
// ---------------------------------------------------------------
// matcher.php – unscharfes Matching von Bierlisten-Zeilen gegen
// den my_beers.csv-Cache.
//
// Ansatz: beide Seiten normalisieren (Kleinschreibung, Umlaute,
// Füllwörter wie "Brauerei"/"Brewing" raus), in Tokens zerlegen
// und tokenweise vergleichen. Teil-Treffer wie "Helles" ~ "Hell"
// oder "Hefeweizen" ~ "Hefeweissbier" zählen anteilig.
// ---------------------------------------------------------------

/** Zur Diagnose auf der Seite sichtbar – bei jeder inhaltlichen Änderung hochzählen. */
const MATCHER_VERSION = 4;

/** Wörter, die für die Wiedererkennung nichts beitragen. */
const MATCH_STOPWORDS = [
    'brauerei', 'braeu', 'brau', 'brauhaus',
    'brewing', 'brewery', 'brewers',
    'brasserie', 'birrificio', 'cerveceria', 'cervejaria', 'pivovar', 'browar',
    'gmbh', 'ag', 'kg', 'co', 'company', 'the', 'und', 'and', 'von', 'zu', 'der', 'die', 'das',
];

/**
 * Wörter, die zwar inhaltlich fast nichts hergeben ("Brew", "Bier"), aber
 * anders als MATCH_STOPWORDS als eigenes Token erhalten bleiben – sonst
 * würde z.B. "Blue Brew" auf ein Wort ("Blue") zusammenschrumpfen und
 * könnte fälschlich exakt auf ein komplett anderes Bier "Blue" matchen.
 * Ein Treffer auf so ein Wort zählt beim Scoring nur stark abgeschwächt.
 */
const MATCH_LOW_SIGNAL_WORDS = ['brew', 'bier', 'biere', 'beer', 'craft'];

/** Gewicht eines Tokens beim Scoring: normale Wörter zählen voll, Low-Signal-Wörter kaum. */
function tokenWeight(string $token): float
{
    return in_array($token, MATCH_LOW_SIGNAL_WORDS, true) ? 0.3 : 1.0;
}

/** Kleinschreibung, Umlaute transliterieren, alles außer a-z0-9 wird Trenner. */
function normalizeText(string $text): string
{
    // Erst Sonderzeichen ersetzen (inkl. Großbuchstaben-Varianten),
    // dann ASCII-Kleinschreibung – bewusst ohne mbstring-Abhängigkeit.
    $text = strtr($text, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ẞ' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e',
        'á' => 'a', 'à' => 'a', 'Á' => 'a', 'À' => 'a',
        'í' => 'i', 'Í' => 'i', 'ó' => 'o', 'Ó' => 'o', 'ú' => 'u', 'Ú' => 'u',
        'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
        'ø' => 'o', 'Ø' => 'o', 'å' => 'a', 'Å' => 'a', 'æ' => 'ae', 'Æ' => 'ae',
    ]);
    $text = strtolower($text);
    return preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
}

/** Zerlegt einen Text in eindeutige, aussagekräftige Tokens. */
function matchTokens(string $text): array
{
    $tokens = [];
    foreach (explode(' ', normalizeText($text)) as $token) {
        if ($token === '' || strlen($token) < 2) {
            continue;
        }
        if (in_array($token, MATCH_STOPWORDS, true)) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

/**
 * Ähnlichkeit zweier einzelner Tokens: 1.0 exakt, sonst anteilig
 * für Präfix-Verwandtschaft ("hell"/"helles") oder Tippfehlernähe.
 */
function tokenSimilarity(string $a, string $b): float
{
    if ($a === $b) {
        return 1.0;
    }

    $lenA = strlen($a);
    $lenB = strlen($b);

    // Einer ist Präfix des anderen (z.B. "hell" in "helles")
    if ($lenA >= 4 && $lenB >= 4 && (str_starts_with($a, $b) || str_starts_with($b, $a))) {
        return 0.85;
    }

    // Langer gemeinsamer Präfix (z.B. "hefeweizen" / "hefeweissbier")
    $common = 0;
    $max    = min($lenA, $lenB);
    while ($common < $max && $a[$common] === $b[$common]) {
        $common++;
    }
    if ($common >= 6) {
        return 0.7;
    }

    // Tippfehlernähe bei längeren Wörtern
    if ($lenA >= 5 && $lenB >= 5 && abs($lenA - $lenB) <= 1 && levenshtein($a, $b) <= 1) {
        return 0.85;
    }

    return 0.0;
}

/**
 * Score einer Listen-Zeile (Query-Tokens) gegen ein Cache-Bier
 * (Target-Tokens aus Biername + Brauerei). Rückgabe 0..1.
 * Gewichtung: Abdeckung der Anfrage zählt stärker als die des Ziels,
 * damit kurze Eingaben wie "Guinness" lange Namen finden können.
 */
function scoreMatch(array $queryTokens, array $targetTokens): float
{
    if ($queryTokens === [] || $targetTokens === []) {
        return 0.0;
    }

    $matchedWeight = 0.0;
    $hasUnmatchedSignificantToken = false;

    foreach ($queryTokens as $q) {
        $best = 0.0;
        foreach ($targetTokens as $t) {
            $sim = tokenSimilarity($q, $t);
            if ($sim > $best) {
                $best = $sim;
                if ($best === 1.0) {
                    break;
                }
            }
        }

        $matchedWeight += $best * tokenWeight($q);

        // Ein inhaltlich normales (nicht Low-Signal-)Wort der Anfrage, das
        // im Ziel überhaupt keine Entsprechung hat, ist ein starkes
        // Warnsignal – unabhängig davon, wie gut der Rest passt.
        if ($best === 0.0 && tokenWeight($q) === 1.0) {
            $hasUnmatchedSignificantToken = true;
        }
    }

    $queryCoverage  = $matchedWeight / count($queryTokens);
    $targetCoverage = $matchedWeight / count($targetTokens);

    $score = 0.7 * $queryCoverage + 0.3 * min(1.0, $targetCoverage);

    // Ein einzelnes Wort gegen ein einzelnes Wort ist zu wenig Kontext für
    // einen automatischen "sicheren" Treffer (z.B. "Blue" gegen "Blue" bei
    // einer eigentlich längeren, nur durch Stopwörter verkürzten Anfrage).
    // Solche Fälle sollen immer zur manuellen Prüfung landen.
    if (count($queryTokens) === 1 && count($targetTokens) === 1) {
        $score = min($score, 0.6);
    }

    // z.B. "Yellow Pony" vs. "Green Pony": Brauerei + "Pony" passen exakt,
    // aber "Yellow" hat mit "Green" nichts gemein. So ein kompletter
    // Fehltreffer bei einem bedeutungstragenden Wort darf nie als
    // "sicher" durchgehen, egal wie gut der Rest übereinstimmt.
    if ($hasUnmatchedSignificantToken) {
        $score = min($score, 0.65);
    }

    return $score;
}

/**
 * Bereitet den Cache fürs Matching vor: pro Bier die Token-Menge
 * aus Name + Brauerei. Einmal aufrufen, dann für alle Zeilen nutzen.
 */
function buildMatchIndex(array $beers): array
{
    $index = [];
    foreach ($beers as $beerId => $beer) {
        $index[$beerId] = matchTokens($beer['beer_name'] . ' ' . $beer['brewery']);
    }
    return $index;
}

/**
 * Findet für eine Listen-Zeile die besten Kandidaten im Cache.
 * Rückgabe: Array von ['beer_id' =>, 'score' =>], absteigend sortiert.
 */
function findCandidates(string $line, array $beers, array $index, int $maxCandidates = 5, float $minScore = 0.35): array
{
    $queryTokens = matchTokens($line);
    if ($queryTokens === []) {
        return [];
    }

    $scored = [];
    foreach ($index as $beerId => $targetTokens) {
        $score = scoreMatch($queryTokens, $targetTokens);
        if ($score >= $minScore) {
            $scored[] = ['beer_id' => $beerId, 'score' => round($score, 3)];
        }
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($scored, 0, $maxCandidates);
}
