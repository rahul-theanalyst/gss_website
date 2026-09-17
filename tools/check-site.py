"""Check local website references. Run from any directory with Python 3."""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlsplit
import re
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


class PageReferences(HTMLParser):
    def __init__(self):
        super().__init__()
        self.references = []

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        for key, value in attrs.items():
            if not value:
                continue
            if key in ('src', 'href', 'poster', 'action'):
                self.references.append(value)
            elif key in ('srcset', 'imagesrcset'):
                self.references.extend(part.strip().split()[0]
                                       for part in value.split(',') if part.strip())
            elif tag == 'meta' and key == 'content' and attrs.get('property') == 'og:image':
                self.references.append(value)


def local_target(source, value):
    url = urlsplit(value)
    if url.scheme or url.netloc or not url.path:
        return None
    path = unquote(url.path)
    return ((ROOT / path.lstrip('/')) if path.startswith('/')
            else source.parent / path).resolve()


def collect_references():
    references = []
    for page in sorted((ROOT / 'html').glob('*.html')) + [ROOT / 'index.html']:
        parser = PageReferences()
        parser.feed(page.read_text(encoding='utf-8'))
        references.extend((page, value) for value in parser.references)
    for stylesheet in sorted((ROOT / 'css').rglob('*.css')):
        text = re.sub(r'/\*.*?\*/', '', stylesheet.read_text(encoding='utf-8'), flags=re.S)
        for value in re.findall(r'url\(\s*[\x27\"]?([^\x27\")]+)', text):
            references.append((stylesheet, value.strip()))
    return references


def main():
    failures = []
    checked = 0
    for source, value in collect_references():
        target = local_target(source, value)
        if target is None:
            continue
        checked += 1
        if not target.is_file():
            failures.append(f'{source.relative_to(ROOT)}: missing {value}')

    # Fetch URLs resolve relative to the document, not the JavaScript folder.
    careers_script = (ROOT / 'js/pages/careers.js').read_text(encoding='utf-8')
    feed = re.search(r"\bDATA_URL\s*=\s*['\"]([^'\"]+)['\"]", careers_script)
    if not feed or not local_target(ROOT / 'html/career.html', feed[1]).is_file():
        failures.append('Careers feed URL does not resolve to a local endpoint.')

    node = shutil.which('node')
    scripts = sorted((ROOT / 'js').rglob('*.js'))
    if node:
        for script in scripts:
            result = subprocess.run([node, '--check', str(script)], capture_output=True, text=True)
            if result.returncode:
                failures.append(result.stderr.strip())
    else:
        print('Node is unavailable; JavaScript syntax checks skipped.')

    if failures:
        print('\n'.join(failures))
        return 1
    print(f'Passed: {checked} local references and the careers endpoint.')
    if node:
        print(f'Passed: syntax checks for {len(scripts)} JavaScript files.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
