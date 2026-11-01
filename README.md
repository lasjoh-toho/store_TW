# store.php 2.1 – Installation guide

Saves single-file TiddlyWikis on web hosting that only offers PHP (no Node.js).
Runs on PHP 8.0 to 8.4. Tested with PHP 8.3 and TiddlyWiki 5.4.1.

Note: the messages and comments inside `store.php` are in German.

## Why the old version stopped working

Since PHP 8, reading a missing array key raises a *Warning* where it used to raise a *Notice*.
The old store.php reads such keys on every save (`$options['debug']`, the empty piece after
`;;`, and so on). When the host has `display_errors` switched on, the warning ends up in front
of the success message. TiddlyWiki expects the reply to start with `0 - `, so it reports an
error, even if the file may actually have been written.

## Files

| File | Purpose |
|---|---|
| `store.php` | the script |
| `auth.example.php` | template for `auth.php` (users and password hashes) |
| `htaccess.txt` | template for `.htaccess` (Apache) |
| `user.ini.txt` | template for `.user.ini` (raises the PHP upload limits) |
| `chunked-upload-plugin.json` | TiddlyWiki plugin: saves in pieces and sends only changes |
| `plugin-src/` | plugin source code (`python3 plugin-src/build.py` rebuilds the JSON) |

## Installation

1. Upload `store.php` to the folder that holds the wiki (e.g. `/wiki/`).
   It replaces the old `store.php`.
2. Open `https://your-domain/wiki/store.php?hash` in a browser, enter a user name and
   password, and copy the line it shows.
3. Save `auth.example.php` as `auth.php`, paste the line into it and upload it.
   An old `auth.php` with plain-text passwords is rejected on purpose, and store.php
   tells you so.
4. Save `htaccess.txt` as `.htaccess` in the same folder (on Apache hosts).
   If you then get a "500 Internal Server Error", remove the lines your host does not allow.
5. PHP must be able to write to the folder. This is usually already the case; otherwise
   set it to 755 via FTP.
6. Optional, once set up: set `'hash_tool' => false` in `store.php`.

## TiddlyWiki settings ("upload" saver)

In the wiki, go to *Control Panel → Saving → TiddlySpot/Upload* (called "Upload saver"
in some versions):

- **Server URL**: `https://your-domain/wiki/store.php`
- **User name / password**: as in `auth.php`
- **Filename**: the name of the wiki file, e.g. `index.html`
- **Upload directory**: `.` (or a subfolder)
- **Backup directory**: e.g. `backups`

Then open the wiki at `https://your-domain/wiki/index.html` and save.

## The "Chunked Upload" plugin (recommended for large wikis)

Many hosts reject large uploads ("413 Request Entity Too Large"), often from 8 or 10 MB
on, and `.htaccess` cannot change that. The plugin works around it:

- It compresses the wiki and sends it in pieces of at most 1 MB. If the server rejects
  a piece, it automatically uses smaller ones.
- From the second save on, it sends **only the changed tiddlers** (usually a few KB).
  store.php merges them into the file and only saves if the result matches the wiki in
  the browser byte for byte (checksum). If the page frame changed (e.g. a plugin update)
  or the file on the server was changed elsewhere in the meantime, it automatically sends
  the whole wiki. If the page was loaded straight from the server, this already works on
  the first save.
- It uses the upload settings from the Control Panel (see above). Without them it stays
  inactive.

**One-time installation** (the plugin only takes effect after a reload, and until then
saving still runs into the 413 limit):

1. Download the wiki from your server via FTP and open it locally in the browser.
2. Drag `chunked-upload-plugin.json` into the wiki and click "Import".
3. Save locally (TiddlyWiki downloads a new file).
4. Upload that file to the server via FTP (same name).
5. Open the wiki online, enter the password in the upload settings (it is only stored
   in that browser) and save.

Under *Control Panel → Saving → store.php (Stücke)* you can switch off the plugin, or
just the sending of changes.

## Alternative: the "put" saver (WebDAV-style)

If you open the wiki at `https://your-domain/wiki/store.php?wiki=index.html`,
TiddlyWiki detects this on its own and saves with PUT. The browser asks for a user name
and password the first time you save (the ones from `auth.php`). Advantages: no upload
settings are needed in the wiki, and if the wiki was changed somewhere else in the
meantime, the server refuses to overwrite it. Backups go to `backups/`.

## Backups

Before each save, the previous version is backed up. Its name holds the moment
**that version** was saved, in German time (`'timezone'` in store.php):

    index.2026-10-31_20-44-12.html   → saved on 31 Oct 2026 at 20:44:12

Old backups are thinned out as before: all backups from the current hour, one per hour
for today, one per day for the current month, and one per month before that. At most 100
are kept per wiki (`'max_backups'`). Old backups in the earlier format
`index.20261031.204412.html` are thinned out too.

## Not saving, but no error message?

With the "upload" saver, TiddlyWiki only shows a message when the server answers with
status 200. If the host blocks the request (e.g. mod_security with "403 Forbidden"),
the server URL is wrong (404), or the URL is `http://` while the wiki runs on
`https://`, nothing seems to happen.

1. Open `https://your-domain/wiki/store.php?check`. It marks in red what is wrong on
   the server (PHP version, upload limits, write permissions, auth.php, https).
2. Below that, upload the wiki file with the **test upload**. Put the user name and
   password into the UploadPlugin field. The server's answer appears in the browser.
3. If `store.php?check` does not load at all, the PHP version is too old (below 8.0)
   or the path is wrong.
4. In the wiki, check: server URL starts with `https://`, user name and password are
   set. TiddlyWiki stores the password only in that one browser.

## Common error messages

- **"Wiki ist größer als upload_max_filesize=2M"** or **"post_max_size"** (wiki too large):
  an empty TiddlyWiki 5 is already about 2.5 MB. Raise the limits with your host
  (customer panel, or a `.user.ini` with `upload_max_filesize = 64M` and
  `post_max_size = 64M`).
- **"413 Request Entity Too Large"** (the web server's own page, not from store.php):
  the web server does not even pass the file on to PHP. The `LimitRequestBody` line in
  `htaccess.txt` helps if your host allows it in `.htaccess`. Otherwise it is usually a
  mod_security limit that only the host can raise. Or simply use the "Chunked Upload"
  plugin (see above).
- **"UserName or Password do not match"**: check the user and hash in `auth.php`. Put
  hashes in 'single' quotes.
- **"Anmeldung erforderlich"** (login required) on PUT even with the right password:
  the host does not pass the login on to PHP. Enable `CGIPassAuth On` in `.htaccess`.
- **"Ordner kann nicht angelegt werden" / "Schreibrechte prüfen"** (folder cannot be
  created / check write permissions): fix the folder permissions via FTP.

## What changed since 1.6.1 (security)

- Passwords are stored as hashes only (bcrypt/argon), with a delay after failed attempts.
- Only `.html`/`.htm` files that look like a TiddlyWiki are accepted. The old version
  would also have accepted a `.php` file, which lets anyone with the password run code
  on the server.
- Upload and backup folders must stay below the script's folder (`..`, absolute paths
  and symlinks cannot escape it).
- Saving is atomic (temporary file first, then swapped in) and locked against concurrent
  saves. The backup is written before the file is overwritten.
- The debug mode that any client could switch on is gone.
- Passwords may now contain `;` and `=`.
- "FOLD_JS" for classic TiddlyWiki (download.php) has been dropped.

## Credits and license

store.php 2.x is a rewrite of the store.php 1.6.1 script by BidiX (BidiX@BidiX.info,
2005–2007) for the TiddlyWiki UploadPlugin. It keeps its upload protocol and the idea of
its backup thinning.

Released under the BSD 3-Clause License, the same license TiddlyWiki uses. See
[LICENSE](LICENSE). A German guide is in [ANLEITUNG.md](ANLEITUNG.md).
