<?php
// ---------------------------------------------------------------
// UntappdClient – dünner Wrapper um die Untappd v4 API
//
// - hängt client_id/client_secret automatisch an jeden Call
// - liest den X-Ratelimit-Remaining Header mit und bricht ab,
//   bevor das Stundenlimit (100 Calls) gerissen wird
// - einheitliche Fehlerbehandlung: wirft immer RuntimeException
// ---------------------------------------------------------------

class UntappdClient
{
    private const BASE_URL = 'https://api.untappd.com/v4';

    private string $clientId;
    private string $clientSecret;
    private int $minRemainingCalls;

    /** User-Agent im von Untappd geforderten Format: "AppName (CLIENT_ID)" */
    private string $userAgent;

    /** null = noch kein Call gemacht, sonst letzter bekannter Wert */
    private ?int $remainingCalls = null;

    /** HTTP-Statuscode der letzten Antwort (für Fehlermeldungen) */
    private int $lastHttpStatus = 0;

    public function __construct(
        string $clientId,
        string $clientSecret,
        int $minRemainingCalls = 5,
        string $appName = 'BadgeFinder'
    ) {
        $this->clientId          = $clientId;
        $this->clientSecret      = $clientSecret;
        $this->minRemainingCalls = $minRemainingCalls;
        $this->userAgent         = "$appName ($clientId)";
    }

    /**
     * GET-Request auf einen v4-Endpunkt, z.B. get('/user/beers/hans', ['offset' => 50]).
     * Gibt das dekodierte "response"-Objekt der API als Array zurück.
     */
    public function get(string $endpoint, array $params = []): array
    {
        if ($this->remainingCalls !== null && $this->remainingCalls <= $this->minRemainingCalls) {
            throw new RuntimeException(
                "Rate-Limit-Reserve erreicht (noch {$this->remainingCalls} Calls übrig). " .
                "Sync später fortsetzen – der Cache bleibt gültig."
            );
        }

        $params['client_id']     = $this->clientId;
        $params['client_secret'] = $this->clientSecret;

        $url = self::BASE_URL . $endpoint . '?' . http_build_query($params);

        $body = extension_loaded('curl')
            ? $this->requestViaCurl($url, $endpoint)
            : $this->requestViaStream($url, $endpoint);

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['meta']['code'])) {
            $snippet = trim(substr(strip_tags($body), 0, 200));
            throw new RuntimeException(
                "Unerwartete Antwort der Untappd-API ($endpoint), " .
                "HTTP-Status {$this->lastHttpStatus}, Anfang der Antwort: \"$snippet\""
            );
        }

        if ($json['meta']['code'] !== 200) {
            $detail = $json['meta']['error_detail'] ?? 'unbekannter Fehler';
            throw new RuntimeException("Untappd-API-Fehler {$json['meta']['code']}: $detail");
        }

        return $json['response'] ?? [];
    }

    /** Letzter bekannter Wert von X-Ratelimit-Remaining (null vor dem ersten Call). */
    public function getRemainingCalls(): ?int
    {
        return $this->remainingCalls;
    }

    private function updateRateLimit(array $responseHeaders): void
    {
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'X-Ratelimit-Remaining:') === 0) {
                $this->remainingCalls = (int) trim(substr($header, strlen('X-Ratelimit-Remaining:')));
                return;
            }
        }
    }

    /** HTTP-Request über die cURL-Extension (bevorzugt, läuft auf fast jedem Hoster). */
    private function requestViaCurl(string $url, string $endpoint): string
    {
        $headers = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',   // gzip/deflate automatisch dekodieren
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ]);

        $body  = curl_exec($ch);
        $error = curl_error($ch);
        $this->lastHttpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Keine Verbindung zur Untappd-API ($endpoint): $error");
        }

        $this->updateRateLimit($headers);
        return $body;
    }

    /** Fallback über file_get_contents – braucht allow_url_fopen=On. */
    private function requestViaStream(string $url, string $endpoint): string
    {
        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException(
                'Weder die cURL-Extension noch allow_url_fopen sind auf diesem Server ' .
                'verfügbar – bitte beim Hoster die cURL-Extension aktivieren.'
            );
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 20,
                'ignore_errors' => true,   // auch bei 4xx/5xx den Body lesen
                'header'        => "User-Agent: {$this->userAgent}\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $reason = error_get_last()['message'] ?? 'unbekannter Grund';
            throw new RuntimeException("Keine Verbindung zur Untappd-API ($endpoint): $reason");
        }

        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $this->lastHttpStatus = (int) $m[1];
            }
        }

        $this->updateRateLimit($responseHeaders);
        return $body;
    }
}
