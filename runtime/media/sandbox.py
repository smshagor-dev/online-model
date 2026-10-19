"""Optional Python runner. Use a dedicated rootless Docker host, never cPanel."""
import base64
import json
import os
import re
import selectors
import subprocess
import time
import uuid

BOOTSTRAP = r'''
import base64, contextlib, io, json, pathlib, traceback, zipfile
class Limited(io.StringIO):
    def write(self, s):
        if self.tell() + len(s) > 80000: raise RuntimeError('Output limit exceeded')
        return super().write(s)
code = input()
code = json.loads(code)['code']
pathlib.Path('/work/output').mkdir(exist_ok=True)
log = Limited()
try:
    with contextlib.redirect_stdout(log), contextlib.redirect_stderr(log):
        exec(compile(code, 'user.py', 'exec'), {'__name__': '__main__'})
except BaseException:
    log.write(traceback.format_exc()[-8000:])
archive = io.BytesIO(); names=[]; total=0
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for p in pathlib.Path('/work/output').rglob('*'):
        if p.is_symlink() or not p.is_file(): continue
        name = p.relative_to('/work/output').as_posix()
        total += p.stat().st_size
        if total > 10000000 or len(names) >= 20: raise RuntimeError('Artifact limit exceeded')
        z.writestr(name, p.read_bytes()); names.append(name)
    z.writestr('stdout.txt', log.getvalue())
print(json.dumps({'text': log.getvalue() + '\nFiles: ' + ', '.join(names), 'mime': 'application/zip', 'data': base64.b64encode(archive.getvalue()).decode()}))
'''


def execute(code: str):
    image = os.environ.get('MEDIA_CODE_IMAGE', '')
    if not re.fullmatch(r'(?:[a-zA-Z0-9./:_-]+@)?sha256:[a-f0-9]{64}', image):
        raise ValueError('Configure an immutable local sandbox image digest')
    name = 'openmindai-' + uuid.uuid4().hex
    command = ['docker', 'run', '--rm', '--pull=never', '--name', name, '--network=none', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--pids-limit=64', '--memory=512m', '--memory-swap=512m', '--cpus=1', '--user=65534:65534', '--tmpfs=/work:rw,noexec,nosuid,size=64m,mode=1777', '--tmpfs=/tmp:rw,noexec,nosuid,size=16m,mode=1777', '--workdir=/work', '--env=MPLCONFIGDIR=/tmp/matplotlib', '--env=MPLBACKEND=Agg', '--log-driver=none', '-i', image, 'python', '-I', '-c', BOOTSTRAP]
    process = None
    try:
        process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        process.stdin.write((json.dumps({'code': code}) + '\n').encode()); process.stdin.close()
        data = bytearray(); started = time.monotonic()
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                if time.monotonic() - started > 35:
                    raise TimeoutError('Code execution exceeded 35 seconds')
                events = selector.select(0.2)
                if events:
                    chunk = os.read(process.stdout.fileno(), 65536)
                    if not chunk:
                        break
                    data.extend(chunk)
                    if len(data) > 28_000_000:
                        raise ValueError('Runner output exceeds limit')
                elif process.poll() is not None:
                    break
        process.wait(timeout=3)
        if process.returncode:
            raise ValueError('Sandbox failed')
        result = json.loads(data)
        if not isinstance(result.get('text'), str) or len(result['text']) > 100000 or result.get('mime') != 'application/zip':
            raise ValueError('Invalid sandbox result')
        if len(base64.b64decode(result['data'], validate=True)) > 20_000_000:
            raise ValueError('Artifact limit exceeded')
        return result
    finally:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10, check=False)
        if process and process.poll() is None:
            process.kill(); process.wait(timeout=5)
