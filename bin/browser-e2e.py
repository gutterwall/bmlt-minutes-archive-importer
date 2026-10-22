#!/usr/bin/env python3
"""Browser checks for bin/e2e.sh in headless Chrome, driven over the DevTools protocol (standard library only).
Files are chosen in the real file inputs (DOM.setFileInputFiles) and sent by the browser as a normal multipart
upload, so PHP's own upload handling runs.

  browser-e2e.py single <site> <files dir> <year>
      Single Import: a PDF for 31 December <year>, a Google Docs link, a JPEG (refused), the same PDF again
      (duplicate warning).
  browser-e2e.py bulk   <site> <files dir> <year>
      Bulk Import: three files, apply-to-all committee and date, one row changed, one unticked; the batch report.
  browser-e2e.py limits <site> <files dir>
      Server limits: more files than max_file_uploads in one batch, a file over upload_max_filesize, a request over
      post_max_size.
  browser-e2e.py scoped <site> <id of another committee>
      A Minutes Manager limited to Hospitals & Institutions sees only that committee, and a forged request for
      another committee is refused.
  browser-e2e.py public <site> <expected titles json>
      A visitor sees the imported minutes on the [bmlt_minutes] page and can open each document.
"""
import base64, json, os, socket, struct, subprocess, sys, tempfile, time, urllib.request
from urllib.parse import urlparse

PORT = int(os.environ.get('CHROME_DEBUG_PORT', '9337'))

class WS:
    def __init__(self, url):
        u = urlparse(url)
        self.s = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n'
                        f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        head = b''
        while b'\r\n\r\n' not in head:
            head += self.s.recv(1)
        assert b' 101 ' in head.split(b'\r\n')[0], head

    def _read(self, n):
        out = b''
        while len(out) < n:
            chunk = self.s.recv(n - len(out))
            if not chunk:
                raise ConnectionError('closed')
            out += chunk
        return out

    def send(self, text):
        data = text.encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def recv(self):
        message = b''
        while True:
            b1, b2 = self._read(2)
            n = b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._read(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._read(8))[0]
            payload = self._read(n)
            if (b1 & 0x0f) == 9:
                continue
            message += payload
            if b1 & 0x80:
                return message.decode()


class Browser:
    """Headless Chrome with a fresh profile; collects console errors, exceptions and every request URL."""

    def __init__(self):
        self.profile = tempfile.mkdtemp(prefix='mai-chrome-')
        self.proc = subprocess.Popen([os.environ.get('CHROME', 'google-chrome'), '--headless=new', f'--remote-debugging-port={PORT}',
                                      f'--user-data-dir={self.profile}', '--no-first-run', '--no-default-browser-check', '--disable-gpu',
                                      '--lang=en-US', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        pages = []
        for i in range(180):
            try:
                pages = [t for t in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list', timeout=5)) if t['type'] == 'page']
                if pages:
                    break
                if i >= 8:
                    urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{PORT}/json/new?about:blank', method='PUT'), timeout=5).read()
            except Exception:
                pass
            time.sleep(0.25)
        if not pages:
            self.close()
            sys.exit('Chrome did not start')
        self.ws = WS(pages[0]['webSocketDebuggerUrl'])
        self.n = 0
        self.errors, self.requests = [], []
        for domain in ('Page', 'Runtime', 'Network', 'Log'):
            self.call(domain + '.enable')

    def _event(self, msg):
        m, p = msg.get('method'), msg.get('params', {})
        if m == 'Runtime.exceptionThrown':
            self.errors.append('exception: ' + p['exceptionDetails'].get('text', '') + ' ' + json.dumps(p['exceptionDetails'].get('exception', {}).get('description', ''))[:300])
        elif m == 'Runtime.consoleAPICalled' and p.get('type') in ('error', 'assert'):
            self.errors.append('console: ' + ' '.join(str(a.get('value', a.get('description', ''))) for a in p.get('args', [])))
        elif m == 'Log.entryAdded' and p['entry'].get('level') == 'error' and 'favicon' not in p['entry'].get('url', ''):
            self.errors.append('log: ' + p['entry'].get('text', '') + ' ' + p['entry'].get('url', ''))
        elif m == 'Network.requestWillBeSent':
            self.requests.append(p['request']['url'])

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({'id': self.n, 'method': method, 'params': params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get('id') == self.n:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})
            self._event(msg)

    def js(self, expression):
        r = self.call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
        return r.get('result', {}).get('value')

    def wait(self, check, timeout=30):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.js(check):
                    return
            except Exception:
                pass
            time.sleep(0.25)
        raise TimeoutError(check + '\n' + (self.js('document.body ? document.body.innerText.slice(0, 1500) : ""') or ''))

    def go(self, url):
        self.call('Page.navigate', url=url)
        time.sleep(0.3)
        self.wait('document.readyState === "complete"')

    def login(self, site, user, password):
        self.call('Network.clearBrowserCookies')
        self.go(site + '/wp-login.php')
        self.wait('!!document.getElementById("wp-submit")')
        self.js(f'document.getElementById("user_login").value={json.dumps(user)};'
                f'document.getElementById("user_pass").value={json.dumps(password)};'
                'document.getElementById("wp-submit").click(); true')
        time.sleep(0.5)
        self.wait('document.readyState === "complete" && !location.href.includes("wp-login.php")')

    def close(self):
        try:
            self.proc.terminate()
            self.proc.wait(10)
        except Exception:
            pass
        subprocess.run(['rm', '-rf', self.profile], stderr=subprocess.DEVNULL)



def ok(text):
    print('  ok   ' + text)


def fail(text):
    print('  FAIL ' + text)
    sys.exit(1)


def critical(b, where):
    text = b.js('document.body ? document.body.innerText : ""') or ''
    for bad in ('There has been a critical error', 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'The link you followed has expired'):
        if bad in text:
            fail(f'{where}: "{bad}" on the page: ' + text[:400])


PAGE = '/wp-admin/edit.php?post_type=bmlt_minutes&page=bmlt-minutes-archive-importer'


def choose_files(b, selector, paths):
    root = b.call('DOM.getDocument', depth=-1)['root']['nodeId']
    node = b.call('DOM.querySelector', nodeId=root, selector=selector)['nodeId']
    if not node:
        fail('no file input ' + selector)
    b.call('DOM.setFileInputFiles', files=paths, nodeId=node)


def notice(b):
    return b.js('(() => { const n = document.querySelector(".wrap .notice.is-dismissible"); return n ? [n.className.match(/notice-(success|warning|error)/)[1], n.querySelector("p").innerText.trim()] : null; })()')


def select_by_text(b, selector, text):
    ok_ = b.js(f'''(() => {{ const s = document.querySelector({json.dumps(selector)});
        const o = [...s.options].find(o => o.text.trim() === {json.dumps(text)}); if (!o) return false; s.value = o.value; return true; }})()''')
    if not ok_:
        fail(f'no option {text!r} in {selector}')


def submit_single(b, site, committee, y, m, d, file=None, link='', expect=None):
    b.go(site + PAGE)
    critical(b, 'single import page')
    select_by_text(b, '#bmai_committee', committee)
    b.js(f'document.querySelector("[name=year]").value = "{y}"; document.querySelector("[name=month]").value = "{m}"; document.querySelector("[name=day]").value = "{d}"; true')
    if file:
        choose_files(b, '#bmai_file', [file])
    if link:
        b.js(f'document.getElementById("bmai_url").value = {json.dumps(link)}; true')
    b.js('document.getElementById("bmai_file").form.querySelector("[type=submit]").click(); true')
    time.sleep(0.5)
    b.wait('document.readyState === "complete" && !!document.querySelector(".wrap h1")', 60)
    critical(b, 'after single import')
    got = notice(b)
    if expect and (not got or got[0] != expect[0] or not got[1].startswith(expect[1])):
        fail(f'single import notice {got}, expected {expect}')
    return got


def single(site, files, year):
    b = Browser()
    try:
        b.login(site, 'admin', 'Admin-pass-1')
        submit_single(b, site, 'Area Service Committee', year, 12, 31, file=os.path.join(files, 'December ASC Minutes.pdf'),
                      expect=('success', 'Minutes imported successfully.'))
        ok(f'PDF for 31 December {year} imported (real browser upload)')
        submit_single(b, site, 'Policy', 2001, 12, 31, link='https://docs.google.com/document/d/abc123/edit',
                      expect=('success', 'Minutes imported successfully.'))
        ok('Google Docs link imported')
        got = submit_single(b, site, 'Literature', 2002, 1, 1, file=os.path.join(files, 'photo.jpg'), expect=('error', 'Upload failed:'))
        ok(f'JPEG refused although the browser sent it: "{got[1]}"')
        submit_single(b, site, 'Area Service Committee', year, 12, 31, file=os.path.join(files, 'December ASC Minutes.pdf'),
                      expect=('warning', 'Minutes already exist for that committee and meeting date.'))
        ok('the same committee and date again: duplicate warning')
        if b.errors:
            fail('browser errors: ' + '; '.join(b.errors[:5]))
    finally:
        b.close()


def bulk(site, files, year):
    b = Browser()
    try:
        b.login(site, 'admin', 'Admin-pass-1')
        b.go(site + PAGE + '&tab=bulk')
        critical(b, 'bulk tab')
        if b.js('document.getElementById("bmai-bulk-submit").disabled') is not True:
            fail('submit enabled before files were chosen')
        names = ['January ASC Minutes.pdf', 'H&I Sep Minutes.txt', 'motions.csv']
        choose_files(b, '#bmai_bulk_files', [os.path.join(files, n) for n in names])
        b.wait('document.querySelectorAll("#bmai-bulk-table tbody tr").length === 3')
        shown = b.js('[...document.querySelectorAll("#bmai-bulk-table tbody tr td:nth-child(2) strong")].map(s => s.innerText)')
        if shown != names:
            fail(f'review table shows {shown}')
        ok('three files chosen: the review table lists them, submit enabled')
        select_by_text(b, '#bmai-apply-committee', 'Activities')
        b.js(f'document.querySelector("[name=apply_year]").value = "{year}"; document.querySelector("[name=apply_month]").value = "1"; document.querySelector("[name=apply_day]").value = "7";'
             ' document.getElementById("bmai-apply-all").click(); true')
        rows = b.js('[...document.querySelectorAll("#bmai-bulk-table tbody tr")].map(tr => [...tr.querySelectorAll("select")].map(s => s.options[s.selectedIndex].text))')
        if rows != [['Activities', 'January', '7', str(year)]] * 3:
            fail(f'apply to all: {rows}')
        ok('"Apply committee & date" set every row')
        select_by_text(b, 'select[name="rows[1][committee]"]', 'Hospitals & Institutions')
        b.js('document.querySelector("[name=\'rows[1][day]\']").value = "8"; document.querySelector("[name=\'rows[0][title]\']").value = "January Area Minutes";'
             ' document.querySelector("[name=\'rows[2][include]\']").checked = false; document.getElementById("bmai-bulk-submit").click(); true')
        time.sleep(0.5)
        b.wait('document.body.innerText.includes("Bulk import complete")', 60)
        critical(b, 'after bulk import')
        text = b.js('document.querySelector(".wrap .notice").innerText')
        if '2 imported, 0 failed, 1 skipped.' not in text:
            fail('bulk report: ' + text)
        b.js('document.querySelector(".wrap .notice details").open = true; true')
        details = b.js('[...document.querySelectorAll(".wrap .notice li")].map(l => l.innerText)')
        if details != ['January-ASC-Minutes.pdf: imported successfully.', 'HI-Sep-Minutes.txt: imported successfully.', 'motions.csv: skipped by user.']:
            fail(f'bulk details {details}')
        ok('row 2 changed to H&I on the 8th, row 3 unticked: "2 imported, 0 failed, 1 skipped", details per file')
        if b.errors:
            fail('browser errors: ' + '; '.join(b.errors[:5]))
    finally:
        b.close()


def limits(site, files):
    b = Browser()
    try:
        b.login(site, 'admin', 'Admin-pass-1')
        b.go(site + PAGE + '&tab=bulk')
        limit = int(b.js('document.querySelector("#bmai-bulk-form .description strong").innerText'))
        names = [f'Outreach {i}.txt' for i in range(1, limit + 3)]
        choose_files(b, '#bmai_bulk_files', [os.path.join(files, n) for n in names])
        b.wait(f'document.querySelectorAll("#bmai-bulk-table tbody tr").length === {len(names)}')
        select_by_text(b, '#bmai-apply-committee', 'Outreach')
        b.js('document.querySelector("[name=apply_year]").value = "2015"; document.getElementById("bmai-apply-all").click();'
             ' document.querySelectorAll("#bmai-bulk-table tbody tr").forEach((tr, i) => tr.querySelector("select[name$=\'[day]\']").value = String(i + 1));'
             ' document.querySelector("[name=allow_duplicate]").checked = true; document.getElementById("bmai-bulk-submit").click(); true')
        time.sleep(0.5)
        b.wait('document.body.innerText.includes("Bulk import complete")', 60)
        text = b.js('document.querySelector(".wrap .notice").innerText')
        if f'{limit} imported, 2 failed, 0 skipped.' not in text:
            fail(f'{len(names)} files with max_file_uploads {limit}: ' + text)
        b.js('document.querySelector(".wrap .notice details").open = true; true')
        lost = b.js('[...document.querySelectorAll(".wrap .notice li")].filter(l => l.innerText.includes("not received")).length')
        if lost != 2:
            fail('files over the limit not reported')
        ok(f'{len(names)} files, server accepts {limit} per request: "{limit} imported, 2 failed", the two dropped files named')

        got = submit_single(b, site, 'Outreach', 2014, 2, 2, file=os.path.join(files, 'big-7mb.pdf'), expect=('error', 'Upload failed:'))
        ok(f'file over upload_max_filesize: "{got[1]}"')
        got = submit_single(b, site, 'Outreach', 2014, 2, 3, file=os.path.join(files, 'huge-12mb.pdf'), expect=('error', 'The upload ('))
        ok(f'request over post_max_size: "{got[1][:90]}…" (not "The link you followed has expired")')
    finally:
        b.close()


def scoped(site):
    b = Browser()
    try:
        b.login(site, 'hi-manager', 'Hi-pass-1')
        b.go(site + PAGE)
        critical(b, 'importer as a committee-limited manager')
        options = b.js('[...document.querySelectorAll("#bmai_committee option")].map(o => o.text.trim()).filter(t => !t.startsWith("—"))')
        if options != ['Hospitals & Institutions']:
            fail(f'committees offered: {options}')
        ok('a Minutes Manager limited to H&I is offered only H&I')
        # A forged request: another committee's id in the form.
        other = int(sys.argv[3])
        b.js(f'const s = document.getElementById("bmai_committee"); s.add(new Option("forged", "{other}")); s.value = "{other}";'
             ' document.getElementById("bmai_url").value = "https://example.org/forged.pdf";'
             ' document.querySelector("[name=year]").value = "2013"; s.form.querySelector("[type=submit]").click(); true')
        time.sleep(0.5)
        b.wait('!!document.querySelector(".wrap h1")', 60)
        got = notice(b)
        if got != ['error', 'You can only import minutes for your own committees.']:
            fail(f'forged committee: {got}')
        ok('a forged request for another committee is refused')
    finally:
        b.close()


def public(site, titles_json):
    expected = json.loads(titles_json)
    b = Browser()
    try:
        b.go(site + '/meeting-minutes/')
        critical(b, 'minutes page')
        text = b.js('document.body.innerText')
        missing = [t for t in expected if t not in text]
        if missing:
            fail(f'not on the minutes page: {missing}\n{text[:1500]}')
        links = b.js('[...document.querySelectorAll("a")].map(a => a.href).filter(h => h.includes("/wp-content/uploads/") || h.includes("docs.google.com"))')
        local = [l for l in links if '/wp-content/uploads/' in l]
        for link in local:
            with urllib.request.urlopen(link) as r:
                if r.status != 200 or not r.read(5):
                    fail(f'{link}: {r.status}')
        ok(f'visitor sees {len(expected)} imported minutes on the [bmlt_minutes] page; {len(local)} documents download, the Google Docs link is there' if any('docs.google.com' in l for l in links) else 'Google Docs link missing')
        if b.errors:
            fail('browser errors: ' + '; '.join(b.errors[:5]))
    finally:
        b.close()


if __name__ == '__main__':
    mode = sys.argv[1] if len(sys.argv) > 1 else ''
    if mode == 'single' and len(sys.argv) == 5:
        single(sys.argv[2], sys.argv[3], int(sys.argv[4]))
    elif mode == 'bulk' and len(sys.argv) == 5:
        bulk(sys.argv[2], sys.argv[3], int(sys.argv[4]))
    elif mode == 'limits' and len(sys.argv) == 4:
        limits(sys.argv[2], sys.argv[3])
    elif mode == 'scoped' and len(sys.argv) == 4:
        scoped(sys.argv[2])
    elif mode == 'public' and len(sys.argv) == 4:
        public(sys.argv[2], sys.argv[3])
    else:
        sys.exit(__doc__)
