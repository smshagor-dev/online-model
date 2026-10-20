# Administration console

The Laravel admin panel at /admin uses a responsive left sidebar and a Tabler-inspired company dashboard. The layout imports the MIT-licensed Tabler CSS at a pinned version and includes a local stylesheet so the navigation remains usable if the CDN is unavailable. This keeps the Laravel Blade forms and existing protected endpoints intact.

## Dashboard data

The overview reads directly from the application's database: users (including new, active and suspended accounts), conversations, messages, GGUF model inventory, ready and enabled models, in-progress downloads, media jobs and background task runs. Its seven-day signup chart is built from real user timestamps. The operations panel shows **database job states**, not a promise that a separate inference host is reachable.

Navigation provides users, administrators, chat models, media snapshots, public assistant moderation, workspace settings and the administrator profile. The mobile menu opens from the header and closes with the backdrop or Escape key.

## Database-managed workspace settings

After running migrations, open **Administration → Workspace settings**. Changes require the signed-in admin's current password and an authenticator/recovery code when two-factor authentication is enabled. All fields are validated server-side. Supported settings:

| Setting | Effect |
| --- | --- |
| Workspace name | Administration branding |
| Support email | Workspace contact preference |
| Dashboard message | Dashboard introduction |
| New account registration | Allows or blocks new public registrations |
| Chat daily request limit | Applies to the next chat API request through Laravel rate limiting |
| Task daily run limit | Applies to new manual and scheduled background task runs |

Values are stored in the \`admin_settings\` table and persist across deployments. New installations use safe in-code defaults until changed in the dashboard. The original environment quota examples are now legacy deployment hints; the database is authoritative for these three policy controls.

Infrastructure settings are deliberately **not** managed in the database form. Keep database passwords, application encryption keys, NVIDIA/Hugging Face secrets, cPanel runtime and queue connections, and other bootstrap credentials in protected deployment configuration. Downloading a model still does not launch an inference server.

Run \`php artisan migrate --force\` after deploying, then clear cached routes/config and restart workers. No seeding is required.

Design reference: https://github.com/tabler/tabler (MIT).
