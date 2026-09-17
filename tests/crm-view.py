"""Check the authenticated CRM viewer against the real receiver and error states."""
from html import escape
from http.cookiejar import CookieJar
import json
from pathlib import Path
import subprocess
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor

ROOT = Path(__file__).resolve().parent.parent
config = dict(
    line.split('=', 1)
    for line in (ROOT / '.env').read_text().splitlines()
    if line and not line.startswith('#')
)
base = config['WP_URL']
assert base.startswith(('http://localhost:', 'http://127.0.0.1:'))
page = base + '/wp-admin/edit.php?post_type=wpq_quiz&page=wpq-crm'
client = build_opener(HTTPCookieProcessor(CookieJar()))
assert 'wp-login.php' in client.open(page, timeout=15).url
client.open(base + '/wp-login.php', timeout=15).read()
client.open(
    base + '/wp-login.php',
    data=urlencode({
        'log': config['WP_ADMIN_USER'],
        'pwd': config['WP_ADMIN_PASSWORD'],
        'wp-submit': 'Войти',
        'redirect_to': page,
        'testcookie': '1',
    }).encode(),
    timeout=20,
).read()
html = client.open(page, timeout=20).read().decode()
assert 'Тестовая CRM — полученные заявки' in html
assert 'Обновить таблицу' in html
assert config['WPQ_CRM_TOKEN'] not in html


def php(code):
    return subprocess.check_output([
        'docker', 'compose', 'exec', '-T', 'wordpress', 'php', '-r',
        'require "/var/www/html/wp-load.php"; ' + code,
    ], cwd=ROOT, text=True)


records = json.loads(php('echo json_encode(WordPressQuiz\\crm_records());'))
assert records, 'Submit a synthetic local demo lead first.'
for row in records:
    assert escape(row['id']) in html
    assert escape(row['payload']['contact']['email']) in html
    for answer in row['payload']['answers']:
        assert escape(answer['question']) in html
        assert escape(answer['answer']) in html
print('PASS: login required; CRM records, contacts and answers shown; token absent')

setup = 'wp_set_current_user(1); '
failed = php(setup + '''add_filter("pre_http_request", static fn() => new WP_Error("offline", "test")); WordPressQuiz\\crm_page();''')
assert 'Не удалось прочитать данные из CRM' in failed and '<table' not in failed
empty = php(setup + '''add_filter("pre_http_request", static fn() => ["response" => ["code" => 200], "body" => "{\\"leads\\":[]}"]); WordPressQuiz\\crm_page();''')
assert 'В CRM пока нет заявок' in empty
assert php('echo WordPressQuiz\\crm_text("<script>alert(1)</script>");') == '&lt;script&gt;alert(1)&lt;/script&gt;'
print('PASS: receiver errors are explicit; empty state and output escaping work')
