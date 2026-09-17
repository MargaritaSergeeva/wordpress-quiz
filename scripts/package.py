"""Build the installable plugin archive, excluding development files."""
from pathlib import Path
import re
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
plugin = root / 'wp-content/plugins/wordpress-quiz'
version = re.search(r'Version: ([0-9.]+)', (plugin / 'wordpress-quiz.php').read_text()).group(1)
output = root / f'dist/wordpress-quiz-{version}.zip'
output.parent.mkdir(exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for path in sorted(plugin.rglob('*')):
        if path.is_file():
            archive.write(path, path.relative_to(plugin.parent))
print(output)
