/*\
title: $:/plugins/storephp/chunked-upload/saver.js
type: application/javascript
module-type: saver

Speichert das Wiki komprimiert und in kleinen Stücken über store.php 2.1.
Ab dem zweiten Speichern werden nur die geänderten Tiddler geschickt.
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
	attempt.then(function(done) {
		return done ? null : upload(text,opts);
	}).then(function() {
		self.baseText = text; // Stand auf dem Server für das nächste Mal
		self.busy = false;
		callback(null);
	},function(err) {
		self.busy = false;
		callback("Speichern über store.php fehlgeschlagen: " + (err && err.message ? err.message : err));
	});
	return true;
};

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
				(response.status === 404 ? " (stimmt die Server-URL? store.php 2.1 nötig)" : ""));
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
