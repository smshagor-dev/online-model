import pathlib
import subprocess
import tempfile
from unittest.mock import patch

from runtime.maxine.server import SPECS, run_model


def fake_wave():
    return b"RIFF" + b"\x00" * 4 + b"WAVE" + b"x" * 40


def test_models_derive_commands_only_from_allowlist():
    with tempfile.TemporaryDirectory() as base:
        root = pathlib.Path(base)
        for spec in SPECS.values():
            script = root / spec["script"]
            script.parent.mkdir(parents=True, exist_ok=True)
            script.write_text("pass\n")
        for slug in ("nvidia-background-noise-removal", "nvidia-studio-voice"):
            with patch("runtime.maxine.server.subprocess.run") as run:
                def complete(cmd, **kwargs):
                    path = pathlib.Path(cmd[cmd.index("--output") + 1])
                    path.write_bytes(fake_wave())
                run.side_effect = complete
                mime, result = run_model(slug, fake_wave(), root=root, api_key="test-server-key")
                assert mime == "audio/wav"
                assert result == fake_wave()
                cmd = run.call_args.args[0]
                assert "grpc.nvcf.nvidia.com:443" in cmd
                assert SPECS[slug]["function"] in cmd
                assert run.call_args.kwargs["timeout"] == 720


def test_arbitrary_model_and_wrong_mime_fail():
    with tempfile.TemporaryDirectory() as base:
        root = pathlib.Path(base)
        for slug in ("nvidia-arbitrary-host", "../some-model"):
            try:
                run_model(slug, fake_wave(), root=root, api_key="test-server-key")
                raise AssertionError("Unrecognized model unexpectedly accepted")
            except ValueError:
                pass
        try:
            run_model("nvidia-3d-body-pose", fake_wave(), root=root, api_key="test-server-key")
            raise AssertionError("WAV passed as video")
        except ValueError:
            pass
