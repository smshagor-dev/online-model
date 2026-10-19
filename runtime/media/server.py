"""Private, offline Hugging Face inference. Run one worker on a GPU host."""
import os

# Must precede all HF imports. Provision snapshots separately; inference is offline.
os.environ['HF_HUB_OFFLINE'] = '1'
os.environ['TRANSFORMERS_OFFLINE'] = '1'
os.environ['HF_HUB_DISABLE_TELEMETRY'] = '1'

import base64
import gc
import hmac
import io
import logging
from pathlib import Path
import tempfile
import threading
from typing import Literal

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

TOKEN = os.environ.get('MEDIA_RUNTIME_KEY', '')
if len(TOKEN) < 32:
    raise RuntimeError('Set MEDIA_RUNTIME_KEY to a random secret of at least 32 characters.')

KINDS = ('image', 'vision', 'transcription', 'speech', 'video')
PATHS = {k: Path(os.environ['MEDIA_' + k.upper() + '_PATH']).resolve()
         for k in KINDS if os.environ.get('MEDIA_' + k.upper() + '_PATH')}
for path in PATHS.values():
    if not path.is_dir() or not (path / 'openmindai-source.json').is_file():
        raise RuntimeError('Provision each model with download.py before enabling it.')

if os.environ.get('MEDIA_DOCUMENT_ENABLED') == 'true':
    PATHS['document'] = None

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
guard = threading.Lock()


class BodyLimit:
    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope['type'] != 'http':
            return await self.app(scope, receive, send)
        size = 0

        async def limited():
            nonlocal size
            message = await receive()
            size += len(message.get('body', b''))
            if size > 11_000_000:
                raise HTTPException(413, 'Request too large')
            return message
        await self.app(scope, limited, send)


app.add_middleware(BodyLimit)


def auth(authorization: str = Header(default='')):
    if not hmac.compare_digest(authorization, 'Bearer ' + TOKEN):
        raise HTTPException(401, 'Unauthorized')


class Task(BaseModel):
    kind: Literal['image', 'vision', 'transcription', 'speech', 'video', 'document']
    prompt: str = Field(max_length=4000)
    input: str | None = Field(default=None, max_length=10_666_672)


def binary(data: bytes, mime: str):
    if not data or len(data) > 20_000_000:
        raise ValueError('Output exceeds limit')
    return {'data': base64.b64encode(data).decode('ascii'), 'mime': mime}


@app.get('/health', dependencies=[Depends(auth)])
def health():
    # Configuration inventory, not a guarantee that a model fits GPU memory.
    return {'kinds': list(PATHS)}


@app.post('/run', dependencies=[Depends(auth)])
def run(task: Task):
    if task.kind not in PATHS:
        raise HTTPException(503, 'Engine not configured')
    needs_input = task.kind in ('vision', 'transcription', 'document')
    if needs_input != bool(task.input):
        raise HTTPException(422, 'Invalid task input')
    if task.kind not in ('transcription', 'document') and not task.prompt.strip():
        raise HTTPException(422, 'Enter a prompt')
    if not guard.acquire(blocking=False):
        raise HTTPException(429, 'Engine busy')
    try:
        raw = base64.b64decode(task.input, validate=True) if task.input else None
        if raw and len(raw) > 8_000_000:
            raise ValueError('Input exceeds limit')
        return {'text': extract_document(raw)} if task.kind == 'document' else infer(task, raw)
    except Exception:
        logging.exception('Local media inference failed')
        raise HTTPException(503, 'Local inference failed') from None
    finally:
        gc.collect()
        if task.kind != 'document':
            import torch
            if torch.cuda.is_available():
                torch.cuda.empty_cache()
        guard.release()


def infer(task: Task, raw: bytes | None):
    import torch
    from PIL import Image
    from transformers import pipeline

    path = str(PATHS[task.kind])
    device = 'cuda' if torch.cuda.is_available() else 'cpu'
    dtype = torch.bfloat16 if device == 'cuda' and torch.cuda.is_bf16_supported() else (torch.float16 if device == 'cuda' else torch.float32)
    common = {'local_files_only': True, 'trust_remote_code': False, 'use_safetensors': True}
    # Load one engine per request; release it afterwards instead of retaining all models in VRAM.
    if task.kind == 'vision':
        Image.MAX_IMAGE_PIXELS = 16_000_000
        with Image.open(io.BytesIO(raw)) as source:
            if source.width * source.height > 16_000_000:
                raise ValueError('Image exceeds limit')
            image = source.convert('RGB')
        pipe = pipeline('image-text-to-text', model=path, device=device, dtype=dtype, model_kwargs=common, trust_remote_code=False)
        messages = [{'role': 'user', 'content': [{'type': 'image'}, {'type': 'text', 'text': task.prompt}]}]
        result = pipe(text=messages, images=[image], max_new_tokens=1024, return_full_text=False)[0]['generated_text']
        return {'text': result}
    if task.kind == 'transcription':
        # Decode locally with FFmpeg; cap decoded duration before ASR.
        import subprocess
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder) / 'input'
            source.write_bytes(raw)
            wav = Path(folder) / 'input.wav'
            probe = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', str(source)], capture_output=True, timeout=15, check=True)
            duration = float(probe.stdout)
            if not 0 < duration <= 120:
                raise ValueError('Audio must be at most 120 seconds')
            subprocess.run(['ffmpeg', '-nostdin', '-v', 'error', '-i', str(source), '-t', '120', '-ac', '1', '-ar', '16000', str(wav)], capture_output=True, timeout=30, check=True)
            pipe = pipeline('automatic-speech-recognition', model=path, device=device, dtype=dtype, model_kwargs=common, trust_remote_code=False)
            return {'text': pipe(str(wav), chunk_length_s=30)['text']}
    if task.kind == 'speech':
        import numpy as np
        import soundfile as sf
        if len(task.prompt) > 1000:
            raise ValueError('Speech input exceeds 1000 characters')
        pipe = pipeline('text-to-audio', model=path, device=device, model_kwargs=common, trust_remote_code=False)
        result = pipe(task.prompt)
        data = io.BytesIO()
        sf.write(data, np.asarray(result['audio']).squeeze(), result['sampling_rate'], format='WAV', subtype='PCM_16')
        return binary(data.getvalue(), 'audio/wav')
    if task.kind == 'image':
        from diffusers import AutoPipelineForText2Image
        pipe = AutoPipelineForText2Image.from_pretrained(path, torch_dtype=dtype, **common).to(device)
        image = pipe(task.prompt, num_inference_steps=25, width=512, height=512).images[0]
        data = io.BytesIO()
        image.save(data, format='PNG')
        return binary(data.getvalue(), 'image/png')
    if task.kind == 'video':
        # Fixed Wan text-to-video adapter; not every video repository shares this API.
        from diffusers import WanPipeline, AutoencoderKLWan
        from diffusers.utils import export_to_video
        vae = AutoencoderKLWan.from_pretrained(path, subfolder='vae', torch_dtype=torch.float32, local_files_only=True, use_safetensors=True)
        pipe = WanPipeline.from_pretrained(path, vae=vae, torch_dtype=dtype, **common).to(device)
        frames = pipe(prompt=task.prompt, height=480, width=832, num_frames=17, num_inference_steps=30, guidance_scale=5.0).frames[0]
        with tempfile.TemporaryDirectory() as folder:
            output = str(Path(folder) / 'video.mp4')
            export_to_video(frames, output, fps=8)
            return binary(Path(output).read_bytes(), 'video/mp4')
    raise ValueError('Unsupported engine')


def extract_document(raw: bytes) -> str:
    """Extract text only; never render macros, external links, scripts or embedded files."""
    if raw.startswith(b'%PDF-'):
        from pypdf import PdfReader
        reader = PdfReader(io.BytesIO(raw), strict=True)
        if reader.is_encrypted or len(reader.pages) > 100:
            raise ValueError('Encrypted PDF or more than 100 pages')
        pieces = []
        for page in reader.pages:
            piece = page.extract_text() or ''
            pieces.append(piece)
            if sum(len(p) for p in pieces) > 100_000:
                raise ValueError('Document text exceeds limit')
        text = '\n\n'.join(pieces)
    else:
        import zipfile
        import xml.etree.ElementTree as ET
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            entries = archive.infolist()
            if len(entries) > 2000 or sum(e.file_size for e in entries) > 20_000_000:
                raise ValueError('Document archive exceeds limit')
            xml = archive.read('word/document.xml')
            if b'<!DOCTYPE' in xml.upper() or b'<!ENTITY' in xml.upper():
                raise ValueError('XML entities forbidden')
            root = ET.fromstring(xml)
            ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
            text = '\n'.join(''.join(p.itertext()) for p in root.findall('.//w:p', ns))
    if not text.strip() or len(text.encode('utf-8')) > 100_000:
        raise ValueError('No extractable text or text exceeds limit; scanned PDFs need OCR')
    return text
