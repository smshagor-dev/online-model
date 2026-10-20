# Teams, MCP connectors and live conversation

Open Workspace → **Teams**, **Connectors** or **Live**. Billing and subscriptions are excluded. These workflows are implemented in Laravel, Next.js and the optional private media runtime; they do not establish complete ChatGPT product parity.

## Team workspace

Create a team, invite an existing or future account by email, then accept the invitation from that account's Teams tab. Acceptance requires a verified matching email and expires after seven days. Invitations are in-app; no email is sent. The scheduler removes expired invitations daily.

The owner can manage admins, delete the team and its shared data. Admins can invite/remove ordinary members and moderate discussions. Members can leave, participate in discussions and edit shared documents. Admins cannot create other admins or remove/demote the owner. Personal chats, memories, files, connector credentials and automatic memory remain personal.

Shared documents use revision numbers: a stale save receives 409 instead of overwriting somebody else's edits. Copy unsaved edits before reloading. This is shared editing with conflict detection, not simultaneous character-by-character co-editing. Discussion updates poll every eight seconds while open. The current view displays the latest 100 posts; each thread accepts at most 1,000 posts. A team has up to 100 members, 100 documents and 100 discussions; an account can create up to ten teams.

To share a personal chat, choose it while creating a discussion, **Review snapshot**, review the exact text and consent. A fingerprint rejects publication if the completed messages changed after preview. Only completed message text is copied; hidden inference checkpoints, attachments and authentication data are excluded. Source chat edits/deletion do not change an existing team snapshot. Removing a member immediately blocks later API reads/writes, but cannot erase text that member already saw or copied. Deleting an owner's account deletes teams they own; another author's posts in surviving teams retain their visible author name and shared content with a null user reference. Delete shared content/team before account deletion if it should not remain.

Export includes team memberships and the current user's authored posts, plus connector metadata/results. Shared documents remain team-owned and available through the team editor/API; personal export does not claim to be a full team backup.

## MCP connectors

The operator must review and configure fixed HTTPS endpoints in Laravel's `.env`:

```dotenv
MCP_SERVERS_JSON='{"docs":{"name":"Research documents","url":"https://your-mcp-host.example/mcp"}}'
MCP_DAILY_LIMIT=100
```

Use your own real endpoint, not this example. Keep endpoints under operator control, review their permissions, and restrict network egress appropriately. Users cannot provide arbitrary URLs, launch stdio commands, add executable plugins or follow redirects. Private-network endpoints are possible only through this operator-managed catalogue. Catalogue changes require a Laravel config refresh. Changing an endpoint invalidates existing connection consent; a user's saved credential is never automatically forwarded to its replacement.

In Connectors, choose a server, enter a personal bearer token if required, and consent. Credentials are encrypted using APP_KEY and excluded from browser responses/exports. Anonymous servers can use an empty token. Disconnect deletes the local credential; revoke the token separately at the external provider if necessary. Other users/teams never inherit this connection.

The client supports the **2025-11-25 and 2025-06-18 MCP tool lifecycle** over Streamable HTTP, JSON and request-scoped SSE responses, initialization/version negotiation, session headers, paginated tool discovery and explicit `tools/call`. It is a deliberately restricted client, not support for every MCP revision or feature. OAuth sign-in, stdio, resource subscriptions, server sampling/elicitation and autonomous model-controlled tool execution are not included. Input schemas are shown for review; the remote server validates its full schema. Tool annotations are untrusted hints, not permission grants.

Choose a discovered tool, enter a JSON argument object, review its exact destination/name/arguments and **Approve and run**. Approval applies only to that operation. Any read/write/delete behavior belongs to that external tool; Openmindai does not infer safety from its name. Results are rendered as text/JSON and can be explicitly inserted into a private chat as untrusted reference data. Results do not trigger more tools automatically.

Each approved call has a stable user-scoped request UUID. Retrying that UUID returns the saved status rather than executing again; using it with different arguments is rejected. A timeout/disconnect is **unknown**, not proof the external action did not happen. Check the external service before intentionally creating a new request. Browser/server crashes cannot guarantee exactly-once external execution. Per-user locks serialize approval execution, reconnection and disconnection. Individual network requests are bounded to 20 seconds and replies to 1 MB; redirects are rejected.

New tool operations have a separate 100/day default allowance, plus existing burst protection. Duplicate request UUIDs do not consume another allowance. At most 100 uncleared results are retained per user. **Clear saved result** removes arguments/results but retains minimal operation metadata/fingerprint/UUID as an idempotency tombstone, so a later retry cannot silently repeat a destructive action. Account deletion removes the records. Saved arguments/results are encrypted. Discovery/initialization do not consume daily tool-call allowance.

Protocol references: [transport](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [lifecycle](https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle), [tools](https://modelcontextprotocol.io/specification/2025-11-25/server/tools).

## Continuous voice and camera/screen conversation

Live uses continuous microphone capture and voice activity detection, automatic utterance segmentation, direct private transcription, normal streamed chat and sentence-by-sentence browser speech playback. Speaking or **Interrupt response** cancels playback/generation. Mute stops microphone track input; **End conversation**, switching workspace tabs or closing the workspace stops recording, all video tracks, timers and pending requests. Late permission responses are discarded and their tracks stopped. Opening the resulting saved chat uses the same conversation history.

This is a chained speech → text → model → browser speech architecture. It is not a native duplex audio/video model, video calling between team members, or a guarantee of human conversation latency. Microphone permissions and browser speech playback require a supported HTTPS browser. A browser-selected speech voice may use the browser vendor's service; consent explicitly discloses this. Headphones reduce echo-triggered interruptions. ASR language accuracy and answer pronunciation depend on the provisioned model and browser voices.

With Vision enabled, camera/screen sharing is an explicit second action. Video is previewed locally; bounded JPEG observations are requested about every five seconds while the engine is free. Speech transcription has priority over new frame requests. Only the latest observation from the last 30 seconds is added to a spoken question, visibly in the saved transcript. These sampled observations are not motion-level understanding of every video frame. Raw audio/JPEG bytes are processed ephemerally and are not saved as media jobs. Spoken question text, included visual descriptions and assistant answers are saved in ordinary private chat rows. Temporary chat is not part of this Live workflow.

### Runtime setup

Provision compatible revision-pinned HF ASR and optional vision models using the existing media download process. Run the private GPU runtime with its existing secret and paths, then set **both** environments:

```dotenv
# Laravel and the Python runtime
MEDIA_LIVE_ENABLED=true
# Laravel only
LIVE_DAILY_LIMIT=20
MEDIA_ENABLED_KINDS=transcription,vision
```

Keep Laravel's `MEDIA_RUNTIME_URL`/`MEDIA_RUNTIME_KEY` aligned with the private Python host. Python requires `MEDIA_TRANSCRIPTION_PATH`, optional `MEDIA_VISION_PATH` and the provenance markers. `/live` uses the same bearer authentication and shared GPU inference lock as `/run`; it never exposes credentials to the browser. The ASR/vision pipelines are retained warm between calls. Provision enough RAM/VRAM for both and other loaded runtimes; an incompatible/overloaded engine fails explicitly rather than producing a fake transcript. Cold loading may exceed the 20-second live call deadline; warm the private service before device acceptance tests. FFmpeg/ffprobe remain required.

A session expires after two hours; a replacement revokes the old one. The default allowance is 20 started sessions per rolling day per user. Internal transcription/frame calls do not consume additional daily sessions or queued-media requests, but retain API burst/concurrency limits. Each spoken question uses the existing chat daily/burst allowance. Queued image/media limits remain separate. A live session does not reserve a GPU or bypass inference/provider limits.

### Deployment and verification

Run `php artisan migrate --force`, refresh Laravel config/routes and restart PHP/workers/scheduler. Preserve APP_KEY. Rebuild/restart Next.js and the private media runtime. cPanel can host the API when it supports the required PHP runtime, outbound requests and worker capacity; GPU inference still needs its separate configured host. No model weights, personal bearer tokens or hosted provider credentials are shipped.

Tests exercise real Laravel routes/database authorization, encrypted fields, snapshot/revision conflicts, request replay, MCP JSON/SSE fixtures, live session quotas/revocation, VAD/sentence assembly and private Python endpoint contracts. They do not replace real microphone/camera permission checks, installed browser speech voices, actual MCP accounts or GPU/NVIDIA inference. Live latency, barge-in echo behavior, media model compatibility and deployed ingress timeouts need acceptance testing on the target devices and infrastructure.
