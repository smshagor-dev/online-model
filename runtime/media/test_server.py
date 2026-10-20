"""API contract tests; no model downloads or synthetic claims about GPU inference."""
import base64
import importlib
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
import io
import zipfile
from unittest.mock import patch

from fastapi.testclient import TestClient


class RuntimeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.folder = tempfile.TemporaryDirectory()
        Path(cls.folder.name, 'openmindai-source.json').write_text('{}')
        os.environ['MEDIA_RUNTIME_KEY'] = 'a' * 32
        os.environ['MEDIA_IMAGE_PATH'] = cls.folder.name
        os.environ['MEDIA_VISION_PATH'] = cls.folder.name
        cls.module = importlib.import_module('server')
        cls.client = TestClient(cls.module.app)
        cls.headers = {'Authorization': 'Bearer ' + 'a' * 32}

    @classmethod
    def tearDownClass(cls):
        cls.folder.cleanup()

    def setUp(self):
        self.torch = patch.dict(sys.modules, {'torch': types.SimpleNamespace(cuda=types.SimpleNamespace(is_available=lambda: False))})
        self.torch.start()

    def tearDown(self):
        self.torch.stop()

    def test_live_requires_authentication_opt_in_and_supported_engine(self):
        data = {'kind': 'vision', 'prompt': 'Describe', 'input': base64.b64encode(b'frame').decode()}
        self.assertEqual(self.client.post('/live', json=data).status_code, 401)
        self.assertEqual(self.client.post('/live', headers=self.headers, json=data).status_code, 503)
        with patch.object(self.module, 'LIVE_ENABLED', True), patch.object(self.module, 'infer', return_value={'text': 'Live frame'}) as infer:
            result = self.client.post('/live', headers=self.headers, json=data)
            self.assertEqual(result.json(), {'text': 'Live frame'})
            self.assertTrue(infer.call_args.kwargs['live_mode'])
            self.assertEqual(self.client.post('/live', headers=self.headers, json={'kind': 'image', 'prompt': 'Hi'}).status_code, 503)

    def test_live_rejects_busy_engine_bad_base64_and_oversized_frames(self):
        with patch.object(self.module, 'LIVE_ENABLED', True), patch.object(self.module, 'infer') as infer:
            for value in ['invalid!', base64.b64encode(b'x' * 3_000_001).decode()]:
                self.assertEqual(self.client.post('/live', headers=self.headers, json={'kind': 'vision', 'prompt': 'Describe', 'input': value}).status_code, 503)
            infer.assert_not_called()
            self.module.guard.acquire()
            try:
                self.assertEqual(self.client.post('/live', headers=self.headers, json={'kind': 'vision', 'prompt': 'Describe', 'input': 'YQ=='}).status_code, 429)
            finally:
                self.module.guard.release()

    def test_live_pipeline_is_warm_and_separate_by_kind(self):
        self.module._live_pipelines.clear()
        calls = []
        def load():
            calls.append(True)
            return object()
        first = self.module.live_pipeline('transcription', load)
        self.assertIs(first, self.module.live_pipeline('transcription', load))
        self.assertIsNot(first, self.module.live_pipeline('vision', load))
        self.assertEqual(len(calls), 2)
        self.module._live_pipelines.clear()

    def test_authentication_and_offline_mode(self):
        self.assertEqual(self.client.get('/health').status_code, 401)
        self.assertEqual(self.client.get('/health', headers=self.headers).json()['kinds'], ['image', 'vision'])
        self.assertEqual(os.environ['HF_HUB_OFFLINE'], '1')
        self.assertEqual(self.client.get('/docs').status_code, 404)

    def test_disabled_engine_rejected_before_inference(self):
        with patch.object(self.module, 'infer') as infer:
            r = self.client.post('/run', headers=self.headers, json={'kind': 'video', 'prompt': 'Hi'})
            self.assertEqual(r.status_code, 503)
            infer.assert_not_called()

    def test_valid_task_contract(self):
        with patch.object(self.module, 'infer', return_value={'text': 'Description'}) as infer:
            raw = b'contract fixture'
            r = self.client.post('/run', headers=self.headers, json={'kind': 'vision', 'prompt': 'Describe', 'input': base64.b64encode(raw).decode()})
            self.assertEqual(r.json(), {'text': 'Description'})
            self.assertEqual(infer.call_args.args[1], raw)

    def test_rejects_invalid_or_missing_input(self):
        for data in [
            {'kind': 'vision', 'prompt': 'Describe'},
            {'kind': 'image', 'prompt': 'Hi', 'input': 'YQ=='},
            {'kind': 'image', 'prompt': ''},
            {'kind': 'image', 'prompt': 'x' * 4001},
            {'kind': 'arbitrary-code', 'prompt': 'Hi'},
        ]:
            self.assertEqual(self.client.post('/run', headers=self.headers, json=data).status_code, 422)

    def test_busy_engine_does_not_start_another_task(self):
        self.module.guard.acquire()
        try:
            self.assertEqual(self.client.post('/run', headers=self.headers, json={'kind': 'image', 'prompt': 'Hi'}).status_code, 429)
        finally:
            self.module.guard.release()

    def test_failure_hides_details_and_releases_gpu_lock(self):
        with patch.object(self.module, 'infer', side_effect=RuntimeError('secret path')), patch('logging.exception'):
            r = self.client.post('/run', headers=self.headers, json={'kind': 'image', 'prompt': 'Hi'})
            self.assertEqual(r.status_code, 503)
            self.assertNotIn('secret', r.text)
            self.assertFalse(self.module.guard.locked())

    def test_pdf_extracts_text_without_an_external_service(self):
        from pypdf import PdfWriter
        from pypdf.generic import DictionaryObject, NameObject, DecodedStreamObject
        writer = PdfWriter()
        page = writer.add_blank_page(width=200, height=200)
        font = DictionaryObject({NameObject('/Type'): NameObject('/Font'), NameObject('/Subtype'): NameObject('/Type1'), NameObject('/BaseFont'): NameObject('/Helvetica')})
        page[NameObject('/Resources')] = DictionaryObject({NameObject('/Font'): DictionaryObject({NameObject('/F1'): font})})
        content = DecodedStreamObject()
        content.set_data(b'BT /F1 12 Tf 10 100 Td (Private PDF text) Tj ET')
        page[NameObject('/Contents')] = content
        data = io.BytesIO()
        writer.write(data)
        self.assertIn('Private PDF text', self.module.extract_document(data.getvalue()))

    def test_docx_extracts_only_document_text(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as z:
            z.writestr('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Private document</w:t></w:r></w:p></w:body></w:document>')
        self.assertEqual(self.module.extract_document(data.getvalue()), 'Private document')

    def test_docx_rejects_entity_declarations(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as z:
            z.writestr('word/document.xml', '<!DOCTYPE x [<!ENTITY ext SYSTEM "file:///etc/passwd">]><x/>')
        with self.assertRaises(ValueError):
            self.module.extract_document(data.getvalue())

    def test_request_body_size_limit(self):
        r = self.client.post('/run', headers={**self.headers, 'Content-Type': 'application/json'}, content=b' ' * 11_000_001)
        self.assertEqual(r.status_code, 413)


if __name__ == '__main__':
    unittest.main()
