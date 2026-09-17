"""Local HTTP + database + CRM integration checks. Uses only synthetic contacts."""
from copy import deepcopy
from html.parser import HTMLParser
import json
from pathlib import Path
import subprocess
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen
import uuid

ROOT = Path(__file__).resolve().parent.parent
ENV = dict(line.split('=', 1) for line in (ROOT / '.env').read_text().splitlines() if line and not line.startswith('#'))
BASE = ENV['WP_URL']
if not BASE.startswith(('http://localhost:', 'http://127.0.0.1:')):
    raise SystemExit('These checks are only allowed against the local demo.')


def docker(*args):
    return subprocess.check_output(['docker', 'compose', *args], cwd=ROOT, text=True).strip()


def php(code):
    return docker('exec', '-T', 'wordpress', 'php', '-r', 'require "/var/www/html/wp-load.php"; ' + code)


class Form(HTMLParser):
    def __init__(self):
        super().__init__()
        self.context = None
        self.answers = {}

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'section' and a.get('data-wp-interactive') == 'wordpress-quiz':
            self.context = json.loads(a['data-wp-context'])
        if tag == 'input' and a.get('data-question'):
            self.answers.setdefault(a['data-question'], a['value'])


form = Form()
form.feed(urlopen(BASE, timeout=15).read().decode())
assert form.context and form.answers, 'Rendered quiz missing'
template = {
    'quizId': form.context['quizId'], 'revision': form.context['revision'],
    'answers': form.answers, 'name': 'Интеграционный тест', 'email': 'integration@example.test',
    'phone': '', 'consent': True, 'website': '',
}


def submit(data, expected, origin=None):
    headers = {'Content-Type': 'application/json'}
    if origin:
        headers['Origin'] = origin
    request = Request(BASE + '/wp-json/wordpress-quiz/v1/leads', data=json.dumps(data).encode(), headers=headers)
    try:
        response = urlopen(request, timeout=25)
    except HTTPError as error:
        response = error
    body = json.loads(response.read())
    assert response.code == expected, (response.code, expected, body)
    return body


def fresh():
    return {**deepcopy(template), 'requestId': str(uuid.uuid4())}


def lead(request_id):
    # The key comes from uuid.uuid4(), never external input.
    return json.loads(php(f'global $wpdb; echo json_encode($wpdb->get_row("SELECT * FROM {{$wpdb->prefix}}wpq_leads WHERE request_id=\'{request_id}\'"));'))


def crm(request_id):
    code = f'import sqlite3,json; db=sqlite3.connect("/data/leads.sqlite3"); rows=db.execute("SELECT payload FROM leads WHERE request_id=?", ("{request_id}",)).fetchall(); print(json.dumps([json.loads(r[0]) for r in rows]))'
    return json.loads(docker('exec', '-T', 'mock-crm', 'python', '-c', code))


valid = fresh()
first = submit(valid, 201)
assert submit(valid, 200)['receipt'] == first['receipt']
row = lead(valid['requestId'])
assert row['status'] == 'delivered' and int(row['attempts']) == 1
received = crm(valid['requestId'])
assert len(received) == 1 and received[0] == json.loads(row['payload'])
assert len(received[0]['answers']) == len(form.answers)
assert all(a['question'] and a['answer'] for a in received[0]['answers'])
print('PASS: HTTP delivery, exact answer snapshot and idempotent retry')

changed = deepcopy(valid)
changed['name'] = 'Другой тест'
submit(changed, 409)
for field, value in [('email', 'broken'), ('consent', False), ('name', ['invalid']), ('answers', {}), ('website', 'spam')]:
    bad = fresh()
    bad[field] = value
    submit(bad, 400)
    assert lead(bad['requestId']) is None
bad = fresh()
bad['answers'][next(iter(form.answers))] = 'forged-answer'
submit(bad, 400)
bad = fresh()
bad['revision'] = 'outdated'
submit(bad, 409)
submit(fresh(), 403, 'https://external.example.test')
print('PASS: invalid contacts, consent, forged answers, stale quiz, cross-origin requests')

# Simulate an actual CRM outage, then restore the service even if an assertion fails.
failed = fresh()
docker('stop', 'mock-crm')
try:
    submit(failed, 201)
    row = lead(failed['requestId'])
    assert row['status'] == 'failed' and int(row['attempts']) == 1
    assert json.loads(row['payload'])['contact']['email'] == template['email']
finally:
    docker('start', 'mock-crm')
for _ in range(30):
    status = docker('ps', '--format', 'json', 'mock-crm')
    if 'healthy' in status:
        break
    time.sleep(1)
assert php(f'echo WordPressQuiz\\deliver({int(row["id"])} ) ? "ok" : "failed";') == 'ok'
assert lead(failed['requestId'])['status'] == 'delivered'
assert len(crm(failed['requestId'])) == 1
print('PASS: CRM outage preserves lead; retry delivers one CRM record')
