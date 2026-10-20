# Workspace additions

## Resume interrupted background runs

Task/research runs now retain encrypted checkpoints after the initial evidence batch, before/after each follow-up search/read, and after each generated answer segment. A failed or cancelled run with a checkpoint displays **Resume saved progress** in its run history. Resuming reuses the same run and daily request allowance. It requires task ownership, no other active run for that task, an active account and a configured enabled local model. Finished runs clear the checkpoint. Changing the task prompt, model or kind requires a new run; an older checkpoint cannot silently continue under a changed task definition.

Checkpoints contain private model context, retrieved page text, pending read/search actions and partial output, encrypted using Laravel's APP_KEY. Keep that key stable across deployment. Checkpoints are hidden from API/export serialization and deleted with the owning task/user. They remain on failed/cancelled runs until resumed or the task is deleted. The resumable flag and saved result/source summaries are visible to the owner.

Each worker claim has a new execution token. Checkpoint/result/failure writes require the current token, so a stale worker cannot overwrite a replacement run. A pending saved read/search can be repeated after a crash, and a model response lost before checkpointing may require another provider call. This is checkpoint recovery, not exactly-once external execution. Interrupted initial seed gathering may restart its batch. The existing scheduler detects abandoned runs; resuming is an explicit user action. Background local-model recovery and saved interactive NVIDIA recovery use separate checkpoints; native local interactive streams still do not support crash resume.

## Resume saved NVIDIA chat

The latest interrupted saved NVIDIA response shows **Resume response**. The same user/assistant message IDs are reused; resume does not consume a second daily chat request. A running request blocks resume until it has stopped or has missed heartbeats for ten minutes. Laravel issues a fresh attempt identity, rejecting old checkpoint/finalization writes. Deleted messages are never recreated. A later conversation turn makes the older response ineligible for resume.

The Next.js server still calls NVIDIA directly; Laravel stores encrypted initial context, research actions/evidence and answer continuation state using APP_KEY. Research checkpoints before and after each search/read, so an interrupted pending read-only tool may repeat. Answer deltas checkpoint roughly every two seconds and at completed segment boundaries. A process crash can lose recent unsaved tokens or repeat provider work; this is not exactly-once generation. Completed responses clear private checkpoints. Failed/cancelled responses retain them until resumed successfully or their chat/account is deleted. Checkpoints and attempt tokens are hidden from API/export serialization. Temporary chats have no database checkpoints. Automatic memory extraction is not repeated on resume.

Deploy the new messages migration and rebuild/restart Next.js with a stable APP_KEY. Existing interrupted messages created before this migration have no checkpoint and cannot resume. The resume workflow is tested with controlled provider streams; live NVIDIA credentials and deployed proxy behavior still need acceptance testing.

## Admin media model downloads

Open `/admin/model-bundles` from the model library. Select a media purpose, inspect a Hugging Face repository, review its card/license and pinned manifest, import it, then click **Download snapshot**. The manifest supports safetensors weights and verified JSON/text/tokenizer/config files. Python and pickle-style weight files are excluded. Every selected file must have size and SHA-256 LFS or Git-blob SHA-1 metadata. A checksum mismatch or unsafe path fails the download and removes staging files.

Downloads share the GGUF download lock and storage budget. Ready folders are private at `storage/app/models/bundles/{id}` with an `openmindai-source.json` provenance manifest. Admin shows the matching `MEDIA_*_PATH` setting. Transfer the complete folder if the GPU runtime is on another machine. Restart the compatible media runtime separately; downloading does not install a GPU, load models or guarantee pipeline compatibility. Existing CLI provisioning remains available. Gated models require the operator's HF access/license acceptance.

## Optional automatic memory

Personalization now has **Automatically remember preferences from completed chats**, disabled by default and dependent on saved memory being enabled. NVIDIA extraction runs on the Next.js server after the answer is saved; local streaming chat queues a short extraction job on `tasks`. Extraction uses the user's current message, not assistant claims, files or full search/history context. Temporary chats are excluded. Live consent is checked again before storing facts.

The model proposes at most three short stable preference/professional facts, with duplicate checks and a basic secret-keyword filter before insertion. Extraction is fallible and is not a guarantee that every sensitive or inaccurate fact is detected. Users can review, edit or delete memories using the existing Memory controls, or disable automatic collection. Existing saved memories remain until explicitly deleted. Optional extraction failure does not fail an already saved chat. It can add provider usage. Legacy non-streaming chat does not currently perform extraction.

## Public assistant templates

Browse `/explore` or Workspace → Explore. Publishing requires explicit consent from the private assistant owner and snapshots its name, description and instructions, together with the author's display name. Files, credentials and model mappings are not published/copied. Preview instructions before copying; a copy is a private assistant using the importing user's selected model. Templates do not grant additional tool permissions.

Editing a private assistant does not update its public snapshot until the owner republishes. Unpublishing removes public access; existing private copies remain independent. Suspended authors' publications are hidden. Verified admins can block/unblock publications at `/admin/assistants`; blocked snapshots cannot be republished through their existing source assistant. This is a public template directory with basic moderation, not a paid assistant store or a comprehensive abuse-prevention service.

## Deployment and verification

Run `php artisan migrate --force`, clear/rebuild Laravel caches, restart the `tasks`/default/media workers and rebuild/restart Next.js. Preserve APP_KEY. Use the existing queue scheduler and database configuration. No live model weights or credentials are shipped. Backend tests use controlled HTTP fixtures; model/GPU, deployed DNS/network, browser/device and SMTP acceptance checks still need the actual target environment.

These additions do not establish complete ChatGPT parity. Teams, reviewed token-based MCP tools and continuous chained voice with sampled video observations are implemented; see [collaboration and live setup](COLLABORATION_AND_LIVE.md). Native duplex audio/video models and connected-account OAuth remain outside this implementation. Billing and subscription processing are explicitly excluded from the requested scope. They are not exposed as working integrations by these changes.
