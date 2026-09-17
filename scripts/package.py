"""Build the installable plugin archive, excluding development files."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
plugin = root / 'wp-content/plugins/wordpress-quiz'
output = root / 'dist/wordpress-quiz-0.2.0.zip'
output.parent.mkdir(exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for path in sorted(plugin.rglob('*')):
        if path.is_file():
            archive.write(path, path.relative_to(plugin.parent))
print(output)
