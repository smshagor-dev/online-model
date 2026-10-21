<?php

// Official model contract references for visible but not yet routable services.
// Only concrete working adapters may switch these models to API-ready.
return [
    'models' => [
        'nvidia-3d-body-pose' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Video-frame gRPC inference worker',
            'note' => 'Video body pose estimation uses a streaming gRPC service.',
            'reference' => 'https://build.nvidia.com/nvidia/body-pose/api',
        ],
        'nvidia-nemotron-voicechat' => [
            'transport' => 'realtime_audio',
            'requirement' => 'Early access and bidirectional audio bridge',
            'note' => 'The hosted demo requires early access; full-duplex speech is not a JSON request.',
            'reference' => 'https://build.nvidia.com/nvidia/nemotron-voicechat',
        ],
        'nvidia-background-noise-removal' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Maxine gRPC audio worker',
            'note' => 'Background Noise Removal uses NVIDIA Maxine streaming audio.',
            'reference' => 'https://build.nvidia.com/nvidia/bnr/api',
        ],
        'nvidia-magpie-tts-zeroshot' => [
            'transport' => 'gated_audio',
            'requirement' => 'NVIDIA approval and speaker consent',
            'note' => 'Magpie ZeroShot requires approved access and a consented voice reference.',
            'reference' => 'https://build.nvidia.com/nvidia/magpie-tts-zeroshot',
        ],
        'nvidia-studio-voice' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Maxine gRPC audio worker',
            'note' => 'Studio Voice requires 16-bit mono WAV and a gRPC client.',
            'reference' => 'https://build.nvidia.com/nvidia/studiovoice/api',
        ],
        'nvidia-synthetic-video-detector' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Private access approval and gRPC worker',
            'note' => 'Synthetic Video Detector is gated through the AI for Media Private Access Program.',
            'reference' => 'https://build.nvidia.com/nvidia/synthetic-video-detector/api',
        ],
        'nvidia-active-speaker-detection' => [
            'transport' => 'grpc_stream',
            'requirement' => 'Video, audio and diarization gRPC worker',
            'note' => 'Requires synchronized video, audio and speaker diarization inputs.',
            'reference' => 'https://build.nvidia.com/nvidia/active-speaker-detection/api',
        ],
        'nvidia-cosmos-transfer2.5-2b' => [
            'transport' => 'controlled_video',
            'requirement' => 'Verified video-condition request workflow',
            'note' => 'This model consumes spatial or control videos; generic JSON prompts are insufficient.',
            'reference' => 'https://build.nvidia.com/nvidia/cosmos-transfer2_5-2b/modelcard',
        ],
        'nvidia-streampetr' => [
            'transport' => 'av_scene',
            'requirement' => 'Queue-backed hosted scene inference and ZIP results',
            'note' => 'NVIDIA offers predefined nuScenes scene IDs with ZIP or signed-S3 download responses. Live entitlement remains unverified.',
            'reference' => 'https://docs.api.nvidia.com/nim/reference/nvidia-streampetr-infer',
        ],
        'nvidia-sparsedrive' => [
            'transport' => 'self_hosted_av',
            'requirement' => 'Separate GPU-hosted NVIDIA NIM worker',
            'note' => 'This scene inference route is configured against an operator-managed NIM, not a verified public NVIDIA hostname. Provider access is unverified.',
            'reference' => 'https://docs.api.nvidia.com/nim/reference/nvidia-sparsedrive-infer',
        ],
        'nvidia-bevformer' => [
            'transport' => 'self_hosted_av',
            'requirement' => 'Separate GPU-hosted NVIDIA NIM worker',
            'note' => 'Requires operator-managed BEV NIM scene inference with documented inputs and JSON response; NVIDIA public example.com URL is a placeholder.',
            'reference' => 'https://docs.api.nvidia.com/nim/reference/nvidia-bevformer-infer',
        ],
    ],
];
