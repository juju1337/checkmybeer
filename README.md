Untappd Finder
Ein kleines PHP/CSV-Tool, das eine beliebige Bierliste (Festival, Getränkemarkt, …)
gegen die eigene Untappd-Historie abgleicht: Welche Biere hattest du schon,
welche sind neu? Läuft komplett auf einem normalen Webspace, von unterwegs
per Handy-Browser bedienbar.
Geplanter nächster Ausbau: Abgleich der noch nicht getrunkenen Biere gegen
Badge-Kriterien (Stil, Land, ABV), um zu sehen, welches Bier dich einem
Badge-Level näherbringt. Aktuell zeigt das Tool "getrunken / neu", der
Badge-Teil ist noch nicht umgesetzt.
Warum ein Browser-Relay?
Die Untappd-API blockt Anfragen von vielen Shared-Hosting-IPs per
Cloudflare-Challenge – auch mit korrektem User-Agent und gültigem Key.
Von privaten IPs (Mobilfunk, Heimnetz) funktioniert der Zugriff dagegen
normal. Deshalb ruft ein Teil des Codes die Untappd-API nicht vom Server,
sondern per JavaScript direkt aus dem Browser der aufrufenden Person auf
(sync_web.php, die Live-Suche in match_web.php) und schickt nur das
Ergebnis an den eigenen Server. Der reine Server-seitige Weg
(sync_history.php + UntappdClient.php) ist als Fallback vorhanden, falls
der eigene Hoster doch nicht geblockt wird.
Dateien
Datei
Zweck
config.php
Zugangsdaten, Pfade, Sync-Token – vor Erstnutzung ausfüllen
history_store.php
Gemeinsame CSV-Logik (laden/speichern/mergen) für Cache und Empfang
matcher.php
Unscharfes Matching (Normalisierung, Tokens, Scoring)
sync_web.php
Haupt-Sync-Seite (Browser-Relay), abbruch- und fortsetzbar
receive_sync.php
Empfängt die Sync-Häppchen von sync_web.php, pflegt den Cache
match_web.php
Haupt-Matching-Seite: Liste einfügen, Treffer prüfen, Live-Suche für neue Biere
sync_history.php
Server-seitiger Sync (CLI oder Web), Fallback falls kein Cloudflare-Block
UntappdClient.php
HTTP-Client für sync_history.php (cURL, Stream-Fallback)
data/my_beers.csv
Cache deiner distinkten Biere (wird automatisch angelegt/aktualisiert)
data/sync_state.json
Fortsetzungspunkt eines unterbrochenen Syncs (existiert nur temporär)
data/matched_list.csv
Zuletzt gespeichertes Abgleichsergebnis aus match_web.php
Einrichtung
Alle Dateien auf den Webspace laden (data/ muss für PHP beschreibbar sein).
config.php ausfüllen:
client_id / client_secret – von deiner (Dummy-)Untappd-App aus dem
API-Dashboard.
app_name – möglichst exakt der Name, unter dem die App dort registriert ist.
username – dein Untappd-Benutzername.
sync_token – ein langer Zufallswert, schützt alle Web-Endpunkte.
Erzeugen z. B. mit:
Code
Ersten vollständigen Sync ausführen: sync_web.php?token=DEIN_TOKEN&full=1
im Browser öffnen (siehe unten).
sync_web.php?token=DEIN_TOKEN (ohne &full=1) als Lesezeichen/Homescreen-
Verknüpfung speichern – das ist der Weg für alle künftigen Syncs.
⚠️ client_id, client_secret und der Sync-Token stecken zwangsläufig im
Quelltext der ausgelieferten Seiten (das JavaScript braucht sie für die
Browser-seitigen API-Aufrufe). Für ein privates, token-geschütztes Tool ist
das ein akzeptables Risiko – die Seiten setzen zusätzlich eine strikte
Referrer-Policy, damit der Token nicht über Links an Untappd durchsickert.
Trotzdem: URLs mit Token nicht öffentlich teilen oder screenshotten.
Sync-Ablauf (sync_web.php)
Inkrementell (Standard): liest nur, bis eine Seite ausschließlich
bereits bekannte Biere enthält – meist 1–2 API-Calls.
Voller Sync (&full=1): baut den Cache komplett neu auf.
Alle 10 gelesenen Seiten (500 Biere) wird der Zwischenstand sofort an
receive_sync.php hochgeladen und gespeichert.
Bricht der Sync ab (Untappd-Stundenlimit von 100 Calls, Netzwerkfehler),
merkt sich der Server den Fortsetzungspunkt (data/sync_state.json).
Der nächste Aufruf von sync_web.php erkennt das automatisch und macht
dort weiter – auch ein Full-Sync mit mehreren Tausend Bieren funktioniert
so über mehrere Stundenfenster verteilt, ohne Datenverlust.
Matching-Ablauf (match_web.php)
Seite öffnen: match_web.php?token=DEIN_TOKEN. Ein Hinweis oben zeigt
Bierzahl und Alter des Caches, mit direktem Link zum Sync.
Bierliste einfügen (eine Zeile pro Bier, Zusatzinfos wie Brauerei/Stil
dürfen mit in der Zeile stehen) und "Abgleichen" antippen.
Jede Zeile bekommt einen Status:
grüner Punkt – sicherer Treffer in der Historie
gelber Punkt – unsicherer Treffer, bitte prüfen (Alternativen unter
"ändern" aufklappbar)
NEU-Badge (amber hervorgehoben) – kein Treffer, vermutlich noch
nicht getrunken
Für NEU-Zeilen sucht die Seite automatisch (nacheinander, mit Pause)
im Untappd-Katalog nach dem wahrscheinlichsten Bier und zeigt bis zu
drei Kandidaten mit Stil und einem Check-in-Link.
Bei jedem Treffer (lokal oder Live-Suche) gibt es einen Untappd-Deep-Link
zur Bierseite ("Check-in ↗") bzw. zur Suche ("Suche ↗") – öffnet auf dem
Handy direkt die Untappd-App.
"Ergebnis speichern" schreibt die bestätigte Zuordnung nach
data/matched_list.csv. Jeder Speichervorgang überschreibt die Datei
komplett – es gibt aktuell nur eine "aktuelle Liste", keine Historie
mehrerer Listen.
Bekannte Grenzen
Die Untappd-API liefert weder einen Badge-Katalog noch Fortschritts-
informationen ("3 von 5 IPAs") – Badge-Kriterien müssten selbst gepflegt
werden (geplant, siehe oben).
Rate-Limit: 100 API-Calls pro Stunde. Ein Full-Sync und viele Live-Suchen
im selben Stundenfenster können sich das Budget teilen.
Das Matching ist unscharf (Token-Vergleich mit Tippfehler- und
Präfix-Toleranz) – bei sehr generischen Namen ("Helles", "IPA") können
falsche Kandidaten vorne liegen. Deshalb die manuelle Bestätigung.
