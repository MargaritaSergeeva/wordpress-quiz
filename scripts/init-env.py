#!/usr/bin/env python3
"""Create local configuration without overwriting existing credentials."""
import os
from pathlib import Path
import secrets

root = Path(__file__).resolve().parent.parent
target = root / '.env'
if target.exists():
    print('.env already exists; no changes made.')
else:
    template = (root / '.env.example').read_text()
    for key in ('WP_ADMIN_PASSWORD', 'DB_PASSWORD', 'DB_ROOT_PASSWORD'):
        template = template.replace(f'{key}=\n', f'{key}={secrets.token_hex(24)}\n')
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        output.write(template)
    print('Created .env with random local passwords. Do not commit this file.')
