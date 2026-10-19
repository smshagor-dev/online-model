"""Provision a revision-pinned HF snapshot. Not an inference-provider API."""
import argparse
import json
from pathlib import Path
import re
from huggingface_hub import snapshot_download

parser = argparse.ArgumentParser()
parser.add_argument('repository', help='Hugging Face owner/repository')
parser.add_argument('revision', help='Full 40-character commit SHA')
parser.add_argument('directory', type=Path)
parser.add_argument('--accept-license', action='store_true', required=True)
args = parser.parse_args()
if not re.fullmatch(r'[\w.-]+/[\w.-]+', args.repository) or not re.fullmatch(r'[a-f0-9]{40}', args.revision):
    parser.error('Use a Hugging Face repository and full commit SHA')
args.directory.mkdir(parents=True, exist_ok=True)
if any(args.directory.iterdir()):
    parser.error('Destination must be empty; never mix model revisions')
snapshot_download(repo_id=args.repository, revision=args.revision, local_dir=args.directory,
                  ignore_patterns=['*.bin', '*.pt', '*.pth', '*.ckpt', '*.pkl', '*.pickle', '*.py'])
(args.directory / 'openmindai-source.json').write_text(json.dumps({
    'repository': args.repository, 'revision': args.revision, 'license_accepted': True,
}))
print('Pinned snapshot ready:', args.directory)
