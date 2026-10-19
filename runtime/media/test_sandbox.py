import base64
import io
import json
import os
import unittest
from unittest.mock import patch, Mock
import zipfile
import sandbox

class SandboxContractTests(unittest.TestCase):
    def test_mutable_image_tag_is_rejected(self):
        compile(sandbox.BOOTSTRAP, "sandbox_bootstrap", "exec")
        with patch.dict(os.environ, {'MEDIA_CODE_IMAGE':'python:latest'}), patch('subprocess.Popen') as launch:
            with self.assertRaises(ValueError): sandbox.execute('print(1)')
            launch.assert_not_called()

    def test_runner_uses_bounded_networkless_nonroot_container_and_removes_it(self):
        archive = io.BytesIO()
        with zipfile.ZipFile(archive,'w') as z: z.writestr('stdout.txt','Hello')
        payload=json.dumps({'text':'Hello','mime':'application/zip','data':base64.b64encode(archive.getvalue()).decode()}).encode()
        read, write = os.pipe()
        os.write(write,payload);os.close(write)
        process=Mock();process.stdin=io.BytesIO();process.stdout=os.fdopen(read,'rb');process.returncode=0;process.poll.return_value=0
        try:
            with patch.dict(os.environ, {'MEDIA_CODE_IMAGE':'openmindai-sandbox@sha256:'+'a'*64}), patch('subprocess.Popen',return_value=process) as launch, patch('subprocess.run') as cleanup:
                result=sandbox.execute('print("Hello")')
                self.assertEqual(result['text'],'Hello')
                args=launch.call_args.args[0]
                for flag in ['--pull=never','--network=none','--read-only','--cap-drop=ALL','--security-opt=no-new-privileges','--pids-limit=64','--memory=512m','--cpus=1','--user=65534:65534','--log-driver=none']:
                    self.assertIn(flag,args)
                self.assertFalse(any(a.startswith('--volume') or a.startswith('--mount') for a in args))
                self.assertEqual(cleanup.call_args.args[0][:3],['docker','rm','-f'])
        finally: process.stdout.close()
