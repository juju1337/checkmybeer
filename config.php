<?php
// ---------------------------------------------------------------
// Untappd Badge-Finder – Konfiguration
// ---------------------------------------------------------------

return [
    // API-Zugangsdaten deiner (Dummy-)App von https://untappd.com/api/dashboard
    'client_id'     => 'A35782ABB1B2D03E052378F98904E0666098D85C',
    'client_secret' => '64A27E06C9A08B7F8C8EF48997C360937B8FF4E0',

    // App-Name für den User-Agent – am besten exakt der Name,
    // unter dem deine App im Untappd-Dashboard registriert ist
    'app_name'      => 'Check My Beer',

    // Dein Untappd-Benutzername (wie in der Profil-URL)
    'username'      => 'juju1337',

    // Pfade der CSV-Dateien
    'history_csv'   => __DIR__ . '/my_beers.csv',

    // Sicherheitsreserve: Sync bricht ab, wenn weniger API-Calls übrig sind
    'min_remaining_calls' => 5,

    // Geheimer Token für den Web-Aufruf (sync_history.php?token=...).
    // Beliebige lange Zufallszeichenkette eintragen, z.B. aus:
    //   php -r "echo bin2hex(random_bytes(16));"
    'sync_token' => 'b33rm3',
];
