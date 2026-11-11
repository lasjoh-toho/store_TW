/*\
title: $:/plugins/storephp/chunked-upload/saver.js
type: application/javascript
module-type: saver

Speichert das Wiki komprimiert und in kleinen Stücken über store.php.
Mit store.php 2.2 werden nur die eigenen Änderungen pro Tiddler geschickt und
auf dem Server in den aktuellen Stand eingebaut: Was andere inzwischen
gespeichert haben, bleibt erhalten und wird danach in dieses Wiki übernommen.
Mit store.php 2.1 wie bisher (nur Änderungen gegen den zuletzt gesehenen Stand).
Nutzt dieselben Einstellungen wie der eingebaute Upload-Saver
($:/UploadURL, $:/UploadName, Passwort, $:/UploadDir, $:/UploadFilename,
$:/UploadBackupDir).

\*/
(function(){

"use strict";

var CONFIG_ENABLED = "$:/config/storephp/chunked-upload/enabled",
	CONFIG_DELTA = "$:/config/storephp/chunked-upload/delta";

var ChunkedUploadSaver = function(wiki) {
	this.wiki = wiki;
	this.busy = false;
};

ChunkedUploadSaver.prototype.save = function(text,method,callback) {
	var wiki = this.wiki,
		url = (wiki.getTextReference("$:/UploadURL") || "").trim(),
		user = (wiki.getTextReference("$:/UploadName") || "").trim(),
		password = $tw.utils.getPassword("upload") || "";
	if(wiki.getTiddlerText(CONFIG_ENABLED,"yes").trim() !== "yes" || !url || !user || !password) {
		return false;
	}
	if(this.busy) {
		callback("Es läuft bereits ein Speichervorgang – bitte gleich noch einmal speichern");
		return true;
	}
	var self = this,
		opts = {
			url: url,
			auth: btoa(unescape(encodeURIComponent(user + ":" + password))),
			file: wiki.getTextReference("$:/UploadFilename") || "index.html",
			dir: wiki.getTextReference("$:/UploadDir") || ".",
			backupDir: wiki.getTextReference("$:/UploadBackupDir") || "."
		},
		useDelta = wiki.getTiddlerText(CONFIG_DELTA,"yes").trim() === "yes";
	this.busy = true;
	$tw.notifier.display("$:/language/Notifications/Save/Starting");
	var attempt;
	if(useDelta && !this.noMerge && splitStore(text) && window.crypto && window.crypto.subtle) {
		attempt = this.saveMerge(text,opts).catch(function(err) {
			if(err && err.status === 400 && /Modus|mode|Aktion|Upload-ID/i.test(err.message || "")) {
				// store.php vor 2.2: kennt das Speichern pro Tiddler nicht.
				self.noMerge = true;
				self.known = null;
				return self.saveOld(text,opts);
			}
			if(err && err.status === 409 && /noch kein Wiki/.test(err.message || "")) {
				// Erstes Speichern an diese Stelle: einmal das ganze Wiki.
				self.known = null;
				return upload(text,opts);
			}
			throw err;
		});
	} else {
		attempt = this.saveOld(text,opts);
	}
	attempt.then(function() {
		self.busy = false;
		callback(null);
	},function(err) {
		self.busy = false;
		callback("Speichern über store.php fehlgeschlagen: " + (err && err.message ? err.message : err));
	});
	return true;
};

/*
Bisheriger Weg (store.php 2.1): nur Änderungen gegen den zuletzt gesehenen
Stand, sonst das ganze Wiki.
*/
ChunkedUploadSaver.prototype.saveOld = function(text,opts) {
	var self = this,
		useDelta = this.wiki.getTiddlerText(CONFIG_DELTA,"yes").trim() === "yes";
	var attempt = useDelta ? this.getBase(opts).then(function(base) {
		return base ? uploadPatch(base,text,opts) : false;
	}).catch(function(err) {
		if(err && err.status === 403) {
			throw err;
		}
		// Server-Stand passt nicht o. Ä.: dann eben das ganze Wiki.
		if(window.console) { console.log("store.php: nur Änderungen nicht möglich, sende alles –",err && err.message); }
		return false;
	}) : Promise.resolve(false);
	return attempt.then(function(done) {
		return done ? null : upload(text,opts);
	}).then(function() {
		self.baseText = text; // Stand auf dem Server für das nächste Mal
	});
};

/*
Speichern pro Tiddler (store.php 2.2). this.known: Titel -> {line, hash} des
Stands auf dem Server, wie dieses Wiki ihn kennt (beim ersten Mal aus der
geladenen Seite). Geschickt wird nur, was sich hier gegenüber diesem Stand
geändert hat, jeweils mit der Prüfsumme der vorher bekannten Fassung.
*/
ChunkedUploadSaver.prototype.saveMerge = function(text,opts) {
	var self = this,
		parts = splitStore(text),
		local = linesByTitle(parts.lines);
	return this.getKnown(opts).then(function(known) {
		var set = [], del = [], prevs = [];
		local.forEach(function(line,title) {
			var k = known.map.get(title);
			if(k && (k.line === line)) {
				return;
			}
			if(k && !k.line && k.hash) {
				prevs.push(sha16(line).then(function(h) {
					if(h !== k.hash) {
						set.push({title: title, line: line, prev: k.hash});
					}
				}));
				return;
			}
			prevs.push((k ? sha16(k.line) : Promise.resolve("")).then(function(h) {
				set.push({title: title, line: line, prev: h});
			}));
		});
		if(!known.loose) {
			known.map.forEach(function(k,title) {
				if(!local.has(title)) {
					prevs.push((k.line ? sha16(k.line) : Promise.resolve(k.hash)).then(function(h) {
						del.push({title: title, prev: h});
					}));
				}
			});
		}
		return Promise.all(prevs).then(function() {
			return Promise.all([sha16(parts.head),sha16(parts.tail)]);
		}).then(function(ht) {
			// Kopf und Rest der Datei (Programmcode, Titel) nur, wenn sie sich von
			// dem unterscheiden, was zuletzt auf dem Server lag.
			var payload = {set: set, del: del};
			if(known.head !== ht[0]) {
				payload.head = parts.head;
			}
			if(known.tail !== ht[1]) {
				payload.tail = parts.tail;
			}
			if(!set.length && !del.length && !payload.head && !payload.tail && self.version) {
				return null; // nichts geändert
			}
			return upload(JSON.stringify(payload),opts,"merge").then(function(res) {
				known.head = res.head || ht[0];
				known.tail = res.tail || ht[1];
				set.forEach(function(s) { known.map.set(s.title,{line: s.line}); });
				del.forEach(function(d) { known.map.delete(d.title); });
				// Kannte dieses Wiki den Stand auf dem Server nur ungefähr, holt es
				// danach alles, was ihm fehlt (sonst würde es das später löschen).
				var others = known.loose || !self.version || res.before !== self.version || (res.conflicts || []).length || (res.kept || []).length;
				self.version = res.version;
				known.loose = false;
				if(others) {
					return self.pullOthers(opts,known,local,res);
				}
			});
		});
	});
};

/*
Holt, was andere inzwischen auf dem Server gespeichert haben, in dieses Wiki:
Prüfsummen aller Tiddler-Zeilen vergleichen, nur die abweichenden laden.
*/
ChunkedUploadSaver.prototype.pullOthers = function(opts,known,local,res) {
	var self = this, wiki = this.wiki;
	return post(opts,"index",{file: opts.file, dir: opts.dir}).then(function(index) {
		var server = index.tiddlers || {}, checks = [], fetch = [], gone = [];
		Object.keys(server).forEach(function(title) {
			var k = known.map.get(title);
			if(!k) {
				fetch.push(title);
			} else if(k.line) {
				checks.push(sha16(k.line).then(function(h) {
					if(h !== server[title]) { fetch.push(title); }
				}));
			} else if(k.hash !== server[title]) {
				fetch.push(title);
			}
		});
		known.map.forEach(function(k,title) {
			if(!Object.prototype.hasOwnProperty.call(server,title)) {
				gone.push(title);
			}
		});
		return Promise.all(checks).then(function() {
			var drops = gone.map(function(title) {
				var k = known.map.get(title), mine = local.get(title);
				known.map.delete(title);
				// Nur löschen, was hier seit dem letzten Stand niemand angefasst hat;
				// hier Bearbeitetes kommt beim nächsten Speichern wieder auf den Server.
				if(mine === undefined || !wiki.tiddlerExists(title)) {
					return 0;
				}
				return (k.line ? Promise.resolve(k.line === mine) : sha16(mine).then(function(h) { return h === k.hash; })).then(function(same) {
					if(same) {
						wiki.deleteTiddler(title);
						return 1;
					}
					return 0;
				});
			});
			return Promise.all(drops);
		}).then(function(drops) {
			var dropped = drops.reduce(function(a,b) { return a + b; },0);
			known.head = index.head || known.head;
			known.tail = index.tail || known.tail;
			if(!fetch.length) {
				self.version = index.version || self.version;
				return dropped;
			}
			return post(opts,"fetch",{file: opts.file, dir: opts.dir, titles: JSON.stringify(fetch)}).then(function(data) {
				var lines = data.lines || {}, n = dropped;
				Object.keys(lines).forEach(function(title) {
					var fields;
					try { fields = JSON.parse(lines[title]); } catch(e) { return; }
					known.map.set(title,{line: lines[title]});
					wiki.addTiddler(new $tw.Tiddler(fields));
					n++;
				});
				self.version = data.version || self.version;
				return n;
			});
		}).then(function(n) {
			var c = (res.conflicts || []).map(function(x) { return x.copy; });
			if(n || c.length) {
				notify(wiki,(n ? n + " Tiddler vom Server übernommen (inzwischen von anderer Stelle gespeichert)." : "") +
					(c.length ? " Gleichzeitig geändert, deine Fassung liegt daneben: " + c.join(", ") : ""));
			}
		});
	});
};

/*
Der Stand auf dem Server, wie dieses Wiki ihn kennt. Beim ersten Speichern: die
Tiddler, mit denen die Seite geladen wurde (wenn sie von dieser Datei kommt),
sonst die Prüfsummen vom Server ("loose": dann wird nichts gelöscht).
*/
ChunkedUploadSaver.prototype.getKnown = function(opts) {
	var self = this;
	if(this.known) {
		return Promise.resolve(this.known);
	}
	var here = document.location.href.split("#")[0].split("?")[0],
		name = decodeURIComponent(here.substr(here.lastIndexOf("/") + 1)),
		stores = document.querySelectorAll("script.tiddlywiki-tiddler-store");
	if(/^https?:/.test(here) && name === opts.file && stores.length === 1) {
		var body = stores[0].textContent;
		if(body.charAt(0) === "[" && body.charAt(body.length - 1) === "]") {
			var lines = body.length > 3 ? body.slice(2,-2).split(",\n") : [];
			var map = new Map();
			linesByTitle(lines).forEach(function(line,title) { map.set(title,{line: line}); });
			this.known = {map: map, loose: false};
			return Promise.resolve(this.known);
		}
	}
	return post(opts,"index",{file: opts.file, dir: opts.dir}).then(function(index) {
		var map = new Map(), server = index.tiddlers || {};
		Object.keys(server).forEach(function(title) { map.set(title,{hash: server[title]}); });
		self.known = {map: map, loose: true, head: index.head, tail: index.tail};
		self.version = index.version || null;
		return self.known;
	});
};

function linesByTitle(lines) {
	var map = new Map();
	lines.forEach(function(line) {
		try {
			var t = JSON.parse(line).title;
			if(typeof t === "string") { map.set(t,line); }
		} catch(e) {}
	});
	return map;
}

function sha16(str) {
	return sha256(new TextEncoder().encode(str)).then(function(h) { return h.slice(0,16); });
}

function notify(wiki,text) {
	var title = "$:/temp/storephp/notice";
	wiki.addTiddler(new $tw.Tiddler({title: title, text: text}));
	$tw.notifier.display(title);
}

/*
Liefert den Stand, der vermutlich auf dem Server liegt: den zuletzt
gespeicherten Text, oder beim ersten Speichern die Datei, aus der diese Seite
geladen wurde (nur wenn sie denselben Namen hat). Der Server prüft das per
Prüfsumme, ein falscher Stand führt nur zum Senden des ganzen Wikis.
*/
ChunkedUploadSaver.prototype.getBase = function(opts) {
	if(this.baseText) {
		return Promise.resolve(this.baseText);
	}
	var here = document.location.href.split("#")[0].split("?")[0],
		name = decodeURIComponent(here.substr(here.lastIndexOf("/") + 1));
	if(!/^https?:/.test(here) || name !== opts.file) {
		return Promise.resolve(null);
	}
	return fetch(here,{cache: "no-store", credentials: "same-origin"}).then(function(response) {
		return response.ok ? response.text() : null;
	},function() {
		return null;
	});
};

var STORE_START = '<script class="tiddlywiki-tiddler-store" type="application/json">[';

// Zerlegt das Wiki in Kopf, eine Zeile pro Tiddler, und Rest (wie store.php).
function splitStore(text) {
	var i = text.indexOf(STORE_START);
	if(i === -1 || text.indexOf(STORE_START,i + 1) !== -1) {
		return null;
	}
	var b = i + STORE_START.length,
		j = text.indexOf("]</script>",b);
	if(j === -1) {
		return null;
	}
	var body = text.slice(b,j);
	if(body.length < 2 || body.charAt(0) !== "\n" || body.charAt(body.length - 1) !== "\n") {
		return null;
	}
	var parts = {head: text.slice(0,b), lines: body.slice(1,-1).split(",\n"), tail: text.slice(j)};
	if(parts.head + "\n" + parts.lines.join(",\n") + "\n" + parts.tail !== text) {
		return null;
	}
	return parts;
}

// Schickt nur die geänderten Tiddler. Liefert false, wenn das nicht lohnt.
function uploadPatch(baseText,text,opts) {
	var oldParts = splitStore(baseText),
		newParts = splitStore(text);
	if(!oldParts || !newParts || !window.crypto || !window.crypto.subtle) {
		return Promise.resolve(false);
	}
	var index = new Map(), ops = [], run = null, size = 0;
	oldParts.lines.forEach(function(line,i) {
		index.set(line,i);
	});
	newParts.lines.forEach(function(line) {
		var k = index.get(line);
		if(k !== undefined) {
			if(run && run[1] + run[2] === k) {
				run[2]++;
			} else {
				run = ["k",k,1];
				ops.push(run);
			}
		} else {
			ops.push(["n",line]);
			size += line.length;
			run = null;
		}
	});
	var patch = {ops: ops};
	if(newParts.head !== oldParts.head) {
		patch.head = newParts.head;
		size += newParts.head.length;
	}
	if(newParts.tail !== oldParts.tail) {
		patch.tail = newParts.tail;
		size += newParts.tail.length;
	}
	if(size > text.length / 2) {
		return Promise.resolve(false); // fast alles neu: ganzes Wiki schicken
	}
	var enc = new TextEncoder();
	return Promise.all([sha256(enc.encode(baseText)),sha256(enc.encode(text))]).then(function(hashes) {
		if(!hashes[0] || !hashes[1]) {
			return false;
		}
		patch.base = hashes[0];
		patch.result = hashes[1];
		return upload(JSON.stringify(patch),opts,"patch").then(function() {
			return true;
		});
	});
}

function chunkUrl(base,action) {
	return base + (base.indexOf("?") === -1 ? "?" : "&") + "chunk=" + action;
}

function post(opts,action,fields,blob) {
	var form = new FormData();
	Object.keys(fields).forEach(function(k) {
		form.append(k,String(fields[k]));
	});
	if(blob) {
		form.append("data",blob,"chunk.bin");
	}
	return fetch(chunkUrl(opts.url,action),{
		method: "POST",
		headers: {"X-Store-Auth": opts.auth},
		body: form,
		credentials: "same-origin",
		cache: "no-store"
	}).then(function(response) {
		return response.text().then(function(body) {
			var data = null;
			try { data = JSON.parse(body); } catch(e) {}
			if(data && data.ok) {
				return data;
			}
			var err = new Error(data && data.error ? data.error :
				"Server antwortet mit " + response.status + " " + response.statusText +
				(response.status === 404 ? " (stimmt die Server-URL? store.php 2.1 oder neuer nötig)" : ""));
			err.status = response.status;
			throw err;
		});
	});
}

function gzip(bytes) {
	if(typeof CompressionStream === "undefined") {
		return Promise.resolve(null);
	}
	var stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream("gzip"));
	return new Response(stream).arrayBuffer().then(function(buf) {
		return new Uint8Array(buf);
	},function() {
		return null;
	});
}

function sha256(bytes) {
	if(!window.crypto || !window.crypto.subtle) {
		return Promise.resolve(""); // nur über https oder localhost verfügbar
	}
	return window.crypto.subtle.digest("SHA-256",bytes).then(function(buf) {
		return Array.prototype.map.call(new Uint8Array(buf),function(b) {
			return ("0" + b.toString(16)).slice(-2);
		}).join("");
	},function() {
		return "";
	});
}

function upload(text,opts,mode) {
	var raw = new TextEncoder().encode(text),
		payload, encoding, digest, id, chunkSize, offset = 0, index = 0;
	return gzip(raw).then(function(zipped) {
		payload = zipped || raw;
		encoding = zipped ? "gzip" : "identity";
		return sha256(payload);
	}).then(function(hash) {
		digest = hash;
		return post(opts,"begin",{
			file: opts.file,
			dir: opts.dir,
			backupDir: opts.backupDir,
			total: payload.length,
			encoding: encoding,
			mode: mode || "full"
		});
	}).then(function(data) {
		id = data.id;
		chunkSize = Math.max(16 * 1024,Math.min(data.chunkSize || 1048576,1048576));
		return sendNext(0);
	}).then(function() {
		return post(opts,"finish",{id: id, sha256: digest});
	});

	// Schickt die Stücke nacheinander. Lehnt der Server ein Stück als zu groß
	// ab (413), wird es halbiert und erneut gesendet.
	function sendNext(attempt) {
		if(offset >= payload.length) {
			return Promise.resolve();
		}
		var end = Math.min(offset + chunkSize,payload.length),
			blob = new Blob([payload.subarray(offset,end)],{type: "application/octet-stream"});
		return post(opts,"part",{id: id, index: index},blob).then(function() {
			offset = end;
			index++;
			return sendNext(0);
		},function(err) {
			// 413 = zu groß; ohne Status = Verbindung abgebrochen (manche
			// Schutzfilter trennen einfach die Verbindung): kleiner probieren.
			if((err.status === 413 || (!err.status && attempt > 0)) && chunkSize > 16 * 1024) {
				chunkSize = Math.floor(chunkSize / 2);
				return sendNext(attempt);
			}
			if(!err.status && attempt < 3) { // Netzwerkfehler: kurz warten, nochmal
				return new Promise(function(resolve) {
					setTimeout(resolve,1000 * (attempt + 1));
				}).then(function() {
					return sendNext(attempt + 1);
				});
			}
			throw err;
		});
	}
}

ChunkedUploadSaver.prototype.info = {
	name: "chunked-upload",
	priority: 2100,
	capabilities: ["save","autosave"]
};

exports.canSave = function(wiki) {
	return typeof window !== "undefined" && !!window.fetch && !!window.FormData && typeof TextEncoder !== "undefined";
};

exports.create = function(wiki) {
	return new ChunkedUploadSaver(wiki);
};

})();
