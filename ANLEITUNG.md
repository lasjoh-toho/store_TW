# store.php 2.1 – Installationsanleitung

Speichert Single-File-TiddlyWikis auf Webspace, der nur PHP kann (kein Node.js).
Läuft mit PHP 8.0 oder neuer (bitte eine gegen php2shell abgesicherte Version, siehe unten),
getestet mit PHP 8.3 und TiddlyWiki 5.4.1.

## Warum TiddlyWiki nicht mehr speichert

Seit Herbst 2026 scheitert das Speichern mit der alten store.php bei vielen Providern,
meist ganz ohne Fehlermeldung. Dafür gibt es drei Gründe, und sie kommen zusammen.

**1. php2shell und die Reaktion der Provider.** Am 21. Oktober 2026 hat PHP Korrekturen für
zwei kritische Lücken veröffentlicht, genannt *php2shell* (CVE-2026-109618,
CVE-2026-109619). Eine präparierte Anfrage konnte PHP beim Einlesen überlasten und danach
eigenen Code auf dem Server ausführen, ganz ohne Passwort. Abgesichert sind PHP 8.5.12,
8.4.27, 8.3.36 und 8.2.35; PHP 8.1 und älter bekommt keine Korrektur mehr. Die Provider
haben binnen eines Tages reagiert: PHP aktualisiert und Firewall-Regeln eingebaut (meist
ModSecurity), die verdächtige, große oder ungewöhnlich aufgebaute Uploads abweisen. Manche
haben auch die Upload-Grenzen gesenkt.

**2. Der Upload von TiddlyWiki ist genau so eine Anfrage.** Der eingebaute Saver „upload“
schickt das ganze Wiki (oft 10 MB und mehr) in einem einzigen POST, von Hand
zusammengebaut, mit einer ungewöhnlichen zusätzlichen `Content-Length`-Zeile, die Zeichen
statt Bytes zählt. Der Provider antwortet mit „413 Request Entity Too Large“ oder blockt die
Anfrage, bevor PHP sie überhaupt sieht. TiddlyWiki reagiert nur auf Antworten mit Status 200,
deshalb sieht man nur „Wiki zum Speichern vorbereiten“ und dann nichts mehr.

**3. Die alte store.php selbst.** Seit PHP 8 erzeugt ein Zugriff auf ein fehlendes
Array-Feld eine *Warning* statt einer *Notice*. Die alte store.php tut das bei jedem
Speichern (`$options['debug']`, das leere Stück hinter `;;` usw.). Zeigt der Provider
PHP-Fehler an, steht die Warnung vor der Erfolgsmeldung. TiddlyWiki erwartet, dass die
Antwort mit `0 - ` beginnt, und meldet einen Fehler, obwohl die Datei evtl. geschrieben wurde.

**Was dieses Projekt dagegen tut:** store.php 2.x läuft sauber unter aktuellem PHP, und das
Plugin „Chunked Upload“ schickt das Wiki komprimiert, in normalen, vom Browser gebauten
Uploads von höchstens 1 MB, und nach dem ersten Speichern nur noch die geänderten Tiddler.
Das bleibt weit unter den neuen Grenzen. Achte darauf, dass dein Provider eine der
abgesicherten PHP-Versionen verwendet.

Quellen: [Hostinger: php2shell – what it is](https://www.hostinger.com/blog/what-is-php2shell/),
[BigCloudy: php2shell security fixes](https://www.bigcloudy.com/blog/php2shell-security-fixes/)

## Dateien

| Datei | Zweck |
|---|---|
| `store.php` | das Skript |
| `auth.example.php` | Vorlage für `auth.php` (Benutzer + Passwort-Hashes) |
| `htaccess.txt` | Vorlage für `.htaccess` (Apache) |
| `user.ini.txt` | Vorlage für `.user.ini` (PHP-Upload-Grenzen anheben) |
| `chunked-upload-plugin.json` | TiddlyWiki-Plugin: Speichern in Stücken und nur mit Änderungen |
| `plugin-src/` | Quelltext des Plugins (`python3 plugin-src/build.py` baut die JSON neu) |

## Installation

1. `store.php` in den Ordner hochladen, in dem das Wiki liegt (z. B. `/wiki/`).
   Die alte `store.php` einfach ersetzen.
2. Im Browser `https://deine-domain/wiki/store.php?hash` öffnen, Benutzername und
   Passwort eingeben, die angezeigte Zeile kopieren.
3. `auth.example.php` als `auth.php` speichern, die Zeile dort eintragen und hochladen.
   Die alte `auth.php` mit Klartext-Passwörtern funktioniert absichtlich nicht mehr;
   store.php meldet dann einen entsprechenden Hinweis.
4. `htaccess.txt` als `.htaccess` in denselben Ordner legen (bei Apache-Hostern).
   Falls danach ein „500 Internal Server Error“ kommt, die Zeilen entfernen, die der
   Provider nicht erlaubt.
5. Der Ordner muss für PHP beschreibbar sein (meist schon so, sonst per FTP 755).
6. Optional, nach der Einrichtung: in `store.php` `'hash_tool' => false` setzen.

## TiddlyWiki einstellen (Saver „upload“)

Im Wiki unter *Kontrollpanel → Speichern → TiddlySpot/Upload* (je nach Version
„Upload saver“):

- **Server-URL**: `https://deine-domain/wiki/store.php`
- **Benutzername / Passwort**: wie in `auth.php`
- **Dateiname**: der Name der Wiki-Datei, z. B. `index.html`
- **Upload-Verzeichnis**: `.` (oder ein Unterordner)
- **Backup-Verzeichnis**: z. B. `backups`

Dann das Wiki über `https://deine-domain/wiki/index.html` öffnen und speichern.

## Plugin „Chunked Upload“ (empfohlen bei großen Wikis)

Viele Provider lehnen große Uploads ab („413 Request Entity Too Large“), oft schon
ab 8 oder 10 MB, und das lässt sich per `.htaccess` nicht ändern. Das Plugin umgeht das:

- Es komprimiert das Wiki und schickt es in Stücken von höchstens 1 MB. Lehnt der
  Server ein Stück ab, nimmt es automatisch kleinere.
- Ab dem zweiten Speichern schickt es **nur die geänderten Tiddler** (meist wenige KB).
  store.php baut sie in die Datei ein und speichert nur, wenn das Ergebnis per Prüfsumme
  Byte für Byte dem Wiki im Browser entspricht. Hat sich der Rahmen der Seite geändert
  (z. B. Plugin-Update) oder wurde die Datei auf dem Server inzwischen anders geändert,
  schickt es automatisch das ganze Wiki. Wurde die Seite direkt vom Server geladen,
  klappt das schon beim ersten Speichern.
- Es nutzt die Upload-Einstellungen aus dem Kontrollpanel (siehe oben). Ohne diese ist
  es inaktiv.

**Einmalige Installation** (weil das Plugin erst nach einem Neuladen wirkt und das
Speichern bis dahin noch an der 413-Grenze scheitert):

1. Das Wiki von deinem Server per FTP herunterladen und lokal im Browser öffnen.
2. `chunked-upload-plugin.json` in das Wiki ziehen und „Importieren“ klicken.
3. Lokal speichern (TiddlyWiki lädt dann eine neue Datei herunter).
4. Diese Datei per FTP wieder auf den Server laden (gleicher Name).
5. Das Wiki online öffnen, in den Upload-Einstellungen das Passwort eintragen
   (es wird nur im jeweiligen Browser gespeichert) und speichern.

Unter *Kontrollpanel → Speichern → store.php (Stücke)* lässt sich das Plugin oder nur
das Senden der Änderungen abschalten.

## Alternative: Saver „put“ (WebDAV-artig)

Wird das Wiki über `https://deine-domain/wiki/store.php?wiki=index.html` geöffnet,
erkennt TiddlyWiki das automatisch und speichert per PUT. Der Browser fragt beim ersten
Speichern nach Benutzername und Passwort (die aus `auth.php`). Vorteile: keine
Upload-Einstellungen im Wiki nötig, und wenn das Wiki inzwischen an anderer Stelle
geändert wurde, verweigert der Server das Überschreiben. Backups landen in `backups/`.

## Backups

Vor jedem Speichern wird die vorherige Fassung gesichert. Der Name enthält den
Zeitpunkt, zu dem **diese Fassung** gespeichert wurde, in deutscher Zeit
(`'timezone'` in store.php):

    index.2026-10-31_20-44-12.html   → gespeichert am 31.10.2026 um 20:44:12

Ausgedünnt wird wie früher: alle Backups der aktuellen Stunde, eines pro Stunde von
heute, eines pro Tag des laufenden Monats, eines pro Monat davor; höchstens 100 je
Wiki (`'max_backups'`). Alte Backups im früheren Format `index.20261031.204412.html`
werden mit ausgedünnt.

## Speichert nicht, aber keine Fehlermeldung?

TiddlyWiki zeigt beim Saver „upload“ nur dann eine Meldung, wenn der Server mit
Status 200 antwortet. Blockiert der Provider die Anfrage (z. B. mod_security mit
„403 Forbidden“), stimmt die Server-URL nicht (404) oder ist die URL `http://`,
während das Wiki über `https://` läuft, passiert scheinbar gar nichts.

1. `https://deine-domain/wiki/store.php?check` öffnen. Die Seite zeigt rot, was am
   Server nicht passt (PHP-Version, Upload-Grenzen, Schreibrechte, auth.php, https).
2. Darunter mit dem **Test-Upload** die Wiki-Datei hochladen. Im Feld UploadPlugin
   Benutzer und Passwort eintragen. Die Antwort des Servers erscheint direkt im Browser.
3. Lädt nicht einmal `store.php?check`, ist die PHP-Version zu alt (unter 8.0) oder
   der Pfad falsch.
4. Im Wiki prüfen: Server-URL mit `https://`, Benutzername und Passwort gesetzt. Das
   Passwort speichert TiddlyWiki nur im jeweiligen Browser.

## Häufige Fehlermeldungen

- **„Wiki ist größer als upload_max_filesize=2M“** bzw. **„post_max_size“**: Ein leeres
  TiddlyWiki 5 hat schon ca. 2,5 MB. Beim Provider (Kundenmenü oder `.user.ini` mit
  `upload_max_filesize = 64M` und `post_max_size = 64M`) erhöhen.
- **„413 Request Entity Too Large“** (englische Seite des Webservers, nicht von
  store.php): Der Webserver lässt die Datei gar nicht bis zu PHP durch. Die Zeile
  `LimitRequestBody` aus `htaccess.txt` hilft, wenn der Provider sie in `.htaccess`
  erlaubt. Sonst ist es meist eine Grenze von mod_security, die nur der Provider anheben
  kann. Oder einfach das Plugin „Chunked Upload“ verwenden (siehe oben).
- **„UserName or Password do not match“**: Benutzer/Hash in `auth.php` prüfen. Hashes
  in 'einfachen' Anführungszeichen eintragen.
- **„Anmeldung erforderlich“ beim PUT trotz richtigem Passwort**: Der Provider reicht
  die Anmeldung nicht an PHP weiter → in `.htaccess` `CGIPassAuth On` aktivieren.
- **„Ordner kann nicht angelegt werden“ / „Schreibrechte prüfen“**: Ordnerrechte per FTP.

## Was sich gegenüber 1.6.1 geändert hat (Sicherheit)

- Passwörter nur noch als Hash (bcrypt/argon), Bremse nach Fehlversuchen.
- Nur `.html`/`.htm` und nur Dateien, die wie ein TiddlyWiki aussehen. Die alte
  Version hätte auch eine `.php`-Datei angenommen und damit Code auf dem Server
  ausführen lassen.
- Upload- und Backup-Ordner bleiben zwingend unterhalb des Skript-Ordners
  (auch gegen `..`, absolute Pfade und Symlinks).
- Speichern ist atomar (erst temporäre Datei, dann Austausch) und gegen
  gleichzeitiges Speichern gesperrt; das Backup wird vor dem Überschreiben geschrieben.
- Kein Debug-Modus mehr, den jeder Client einschalten konnte.
- Passwörter dürfen jetzt auch `;` und `=` enthalten.
- Das „FOLD_JS“ für das klassische TiddlyWiki (download.php) ist entfallen.

## Herkunft und Lizenz

store.php 2.x ist eine Neufassung der store.php 1.6.1 von BidiX (BidiX@BidiX.info,
2005–2007) für das TiddlyWiki-UploadPlugin. Das Upload-Protokoll und die Idee der
Backup-Ausdünnung stammen von dort.

Veröffentlicht unter der BSD-3-Clause-Lizenz, wie TiddlyWiki selbst, siehe
[LICENSE](LICENSE). Die englische Anleitung steht in [README.md](README.md).
