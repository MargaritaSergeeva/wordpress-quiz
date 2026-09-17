"""Internal mock CRM. No external packages, logs contain no lead contents."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import hashlib
import hmac
import json
import os
from pathlib import Path
import sqlite3
import uuid

TOKEN = os.environ['CRM_TOKEN']
DATABASE = '/data/leads.sqlite3'
Path('/data').mkdir(exist_ok=True)
with sqlite3.connect(DATABASE) as db:
    db.execute('CREATE TABLE IF NOT EXISTS leads (id TEXT PRIMARY KEY, request_id TEXT UNIQUE NOT NULL, digest TEXT NOT NULL, payload TEXT NOT NULL)')


class Handler(BaseHTTPRequestHandler):
    server_version = 'QuizMockCRM/1.0'

    def respond(self, status, body):
        raw = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def authorized(self):
        return hmac.compare_digest(self.headers.get('Authorization', ''), 'Bearer ' + TOKEN)

    def do_GET(self):
        if self.path == '/health':
            return self.respond(200, {'ok': True})
        if not self.authorized():
            return self.respond(401, {'error': 'Unauthorized'})
        if self.path != '/leads':
            return self.respond(404, {'error': 'Not found'})
        with sqlite3.connect(DATABASE) as db:
            rows = db.execute('SELECT id, payload FROM leads ORDER BY rowid DESC LIMIT 100').fetchall()
        self.respond(200, {'leads': [{'id': row[0], 'payload': json.loads(row[1])} for row in rows]})

    def do_POST(self):
        if not self.authorized():
            return self.respond(401, {'error': 'Unauthorized'})
        if self.path != '/leads':
            return self.respond(404, {'error': 'Not found'})
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 < length <= 64000:
                return self.respond(413, {'error': 'Invalid size'})
            payload = json.loads(self.rfile.read(length))
            request_id = payload['requestId']
            if str(uuid.UUID(request_id)) != request_id.lower() or request_id != self.headers.get('Idempotency-Key'):
                raise ValueError('Invalid key')
            if not isinstance(payload.get('answers'), list) or not isinstance(payload.get('contact'), dict):
                raise ValueError('Invalid payload')
        except (ValueError, KeyError, TypeError, AttributeError):
            return self.respond(400, {'error': 'Invalid payload'})
        encoded = json.dumps(payload, ensure_ascii=False, sort_keys=True)
        digest = hashlib.sha256(encoded.encode()).hexdigest()
        with sqlite3.connect(DATABASE, timeout=10) as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT id, digest FROM leads WHERE request_id=?', (request_id,)).fetchone()
            if existing:
                if existing[1] != digest:
                    return self.respond(409, {'error': 'Idempotency conflict'})
                return self.respond(200, {'id': existing[0], 'duplicate': True})
            lead_id = str(uuid.uuid4())
            db.execute('INSERT INTO leads VALUES (?, ?, ?, ?)', (lead_id, request_id, digest, encoded))
        self.respond(201, {'id': lead_id})


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
