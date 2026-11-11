# store.php 2.2 – Installation guide

Saves single-file TiddlyWikis on web hosting that only offers PHP (no Node.js).
Runs on PHP 8.0 or newer (use a version patched against php2shell, see below).
Tested with PHP 8.3 and TiddlyWiki 5.4.1.

Note: the messages and comments inside `store.php` are in German.

## Why TiddlyWiki stopped saving

Since autumn 2026, saving a TiddlyWiki with the old store.php fails on many hosts, usually
without any error message. There are three causes, and they add up.

**1. php2shell and the hosts' reaction.** On 21 October 2026, PHP published fixes for two
critical holes nicknamed *php2shell* (CVE-2026-109618, CVE-2026-109619). A crafted request
could overwhelm PHP while it parses the request and then run the attacker's own code on the
server, without any password. Patched versions are PHP 8.5.12, 8.4.27, 8.3.36 and 8.2.35;
PHP 8.1 and older get no fix. Hosts reacted within a day: they updated PHP and added
firewall rules (mostly ModSecurity) that block suspicious, large or malformed upload
requests, and some lowered their upload limits.

**2. TiddlyWiki's upload is exactly the kind of request these rules hit.** The built-in
"upload" saver sends the whole wiki (often 10 MB or more) in one POST request, built by
hand, with an unusual extra `Content-Length` line inside the form data that counts
characters rather than bytes. The host answers with "413 Request Entity Too Large" or
blocks the request before PHP ever sees it. TiddlyWiki only reacts to replies with status
200, so all you see is "Starting to save" and then nothing.

**3. The old store.php itself.** Since PHP 8, reading a missing array key raises a
*Warning* where it used to raise a *Notice*. The old store.php does that on every save
(`$options['debug']`, the empty piece after `;;`, and so on). If the host shows PHP errors,
the warning ends up in front of the success message. TiddlyWiki expects the reply to start
with `0 - `, so it reports an error, even if the file may have been written.

**What this project does about it:** store.php 2.x runs cleanly on current PHP, and the
"Chunked Upload" plugin sends the wiki compressed, in ordinary browser-built uploads of at
most 1 MB, and only the changed tiddlers. That stays well below the
new limits. Make sure your host runs one of the patched PHP versions.

Sources: [Hostinger: php2shell – what it is](https://www.hostinger.com/blog/what-is-php2shell/),
[BigCloudy: php2shell security fixes](https://www.bigcloudy.com/blog/php2shell-security-fixes/)

## Files

| File | Purpose |
|---|---|
| `store.php` | the script |
| `auth.example.php` | template for `auth.php` (users and password hashes) |
| `htaccess.txt` | template for `.htaccess` (Apache) |
| `user.ini.txt` | template for `.user.ini` (raises the PHP upload limits) |
| `chunked-upload-plugin.json` | TiddlyWiki plugin: saves in pieces and per tiddler, without overwriting other people's changes |
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
- It sends **only the tiddlers you changed, created or deleted** (usually a few KB).
  store.php merges them into the *current* file on the server. Whatever others (a second
  browser, another device, brausa) saved in the meantime is kept, and is then pulled into
  your wiki automatically.
- If two people changed the same tiddler at the same time, the server's version stays and
  yours is added next to it as "Title (Konflikt date time)", with a notice. For system
  tiddlers (`$:/…`, settings, plugins) the last save simply wins.
- A tiddler is only deleted on the server if nobody has changed it there since.
- If there is no file on the server yet, it sends the whole wiki once.
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

### Upgrading from store.php 2.1 / plugin 1.1

Saving per tiddler needs store.php 2.2 **and** plugin 1.2. Every mix works, but only the
new pair protects against overwriting. Order:

1. **Replace `store.php` first.** Wikis with the old plugin keep saving exactly as before.
2. **Then update the plugin in every web wiki**: drag `chunked-upload-plugin.json` into the
   wiki opened online, import, save, reload. This works online because the old version
   already saves in pieces.
3. **Update brausa last** (from 0.24 on it saves per tiddler too).

A new plugin talking to an old store.php notices and saves like plugin 1.1.

### How saving per tiddler works

The wiki file holds one tiddler per line. For every tiddler the plugin remembers a
checksum of the line it last knew from the server, and sends changed tiddlers together
with that checksum. Under a lock, store.php reads the current file, replaces a tiddler only
if its line on the server still has the reported checksum (otherwise it writes a conflict
copy), and reports whether someone else saved before. Only then does the plugin fetch the
checksums of all tiddlers (about 30 bytes each) and load the ones that differ. Concurrent
saves are handled one after the other.

Protocol (all `POST store.php?chunk=…` with header `X-Store-Auth`):
`state` / `index` / `fetch` (fields `file`, `dir`, `titles`) return the file version
(sha256), the checksum of each tiddler line (first 16 hex digits of its sha256) and single
tiddler lines; `begin` with `mode=merge` takes the JSON
`{"set":[{"title","line","prev"}],"del":[{"title","prev"}],"head"?,"tail"?}`, and `finish`
returns `before`, `version`, `conflicts` and `kept`.

## Alternative: the "put" saver (WebDAV-style)

If you open the wiki at `https://your-domain/wiki/store.php?wiki=index.html`,
TiddlyWiki detects this on its own and saves with PUT. The browser asks for a user name
and password the first time you save (the ones from `auth.php`). Advantages: no upload
settings are needed in the wiki, and if the wiki was changed somewhere else in the
meantime, the server refuses to overwrite it (reload then; there is no merging here). Backups go to `backups/`.

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
