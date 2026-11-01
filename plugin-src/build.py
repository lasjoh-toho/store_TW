# Baut chunked-upload-plugin.json aus saver.js, readme.tid und settings.tid
import json, os
here = os.path.dirname(os.path.abspath(__file__))
def tid(fn):
    head, body = open(os.path.join(here, fn), encoding='utf8').read().split('\n\n', 1)
    f = dict(l.split(': ', 1) for l in head.splitlines())
    f['text'] = body
    return f
tiddlers = {'$:/plugins/storephp/chunked-upload/saver.js': {
    'title': '$:/plugins/storephp/chunked-upload/saver.js', 'type': 'application/javascript',
    'module-type': 'saver', 'text': open(os.path.join(here, 'saver.js'), encoding='utf8').read()}}
for fn in ['readme.tid', 'settings.tid']:
    d = tid(fn); tiddlers[d['title']] = d
plugin = {'title': '$:/plugins/storephp/chunked-upload', 'name': 'Chunked Upload',
          'description': 'Speichern über store.php in Stücken und nur mit Änderungen',
          'version': '1.1.0', 'core-version': '>=5.2.0', 'plugin-type': 'plugin',
          'type': 'application/json', 'list': 'readme', 'text': json.dumps({'tiddlers': tiddlers})}
json.dump([plugin], open(os.path.join(here, '..', 'chunked-upload-plugin.json'), 'w', encoding='utf8'), ensure_ascii=False)
