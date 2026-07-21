Ein kleines PHP/CSV-Tool, das eine beliebige Bierliste (Festival, Getränkemarkt, …)
gegen die eigene Untappd-Historie abgleicht: Welche Biere hattest du schon,
welche sind neu? Läuft komplett auf einem normalen Webspace, von unterwegs
per Handy-Browser bedienbar.

Just copy to your webserver and edit config.php with your Untappd API key. Define a secret token as well.
Then run sync_web.php?token=XXX with your specified token and check beers against your list with match_web.php?token=XXX

Note the Untappd API rate limit of 100calls/h.
