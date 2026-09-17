#!/usr/bin/env python3
"""Create local configuration without overwriting existing credentials."""
import os
from pathlib import Path
import secrets

root = Path(__file__).resolve().parent.parent
target = root / '.env'
if target.exists():
    text = target.read_text()
    if not any(line.startswith('WPQ_CRM_TOKEN=') and line.split('=', 1)[1] for line in text.splitlines()):
        text = '\n'.join(line for line in text.splitlines() if not line.startswith('WPQ_CRM_TOKEN='))
        target.write_text(text.rstrip() + '\nWPQ_CRM_TOKEN=' + secrets.token_hex(32) + '\n')
        os.chmod(target, 0o600)
        print('Added a random mock CRM token; existing credentials preserved.')
    else:
        print('.env already exists; no changes made.')
else:
    template = (root / '.env.example').read_text()
    for key in ('WP_ADMIN_PASSWORD', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'WPQ_CRM_TOKEN'):
        template = template.replace(f'{key}=\n', f'{key}={secrets.token_hex(24)}\n')
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        output.write(template)
    print('Created .env with random local passwords. Do not commit this file.')
