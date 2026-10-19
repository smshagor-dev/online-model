# cPanel deployment

## Hosting requirements

PHP 8.2+ CLI and web runtime, Composer/SSH or a prebuilt vendor directory, MySQL, sufficient disk quota, outbound HTTPS to Hugging Face and its model CDNs, cron and background-worker support are needed. A small PHP hosting plan can manage accounts and requests; serving large model inference requires suitable RAM/VRAM, CPU/GPU and permission to run an inference process. Confirm those hosting capabilities before expecting chat to run on that same account.

## Application location

Keep the application outside `public_html`, for example `/home/USER/online-model`. Point the subdomain's document root to `/home/USER/online-model/public`. Never expose the project root, `.env`, database files or `storage/app/models` through the web server. No public storage symlink is needed.

Set the domain and CLI to PHP 8.2, then follow README installation commands with the correct cPanel PHP binary, often `/opt/cpanel/ea-php82/root/usr/bin/php`. Give the application owner write access to `storage` and `bootstrap/cache`; do not use world-writable permissions. Keep `APP_DEBUG=false`, HTTPS and secure cookies enabled.

Configure real SMTP so registration verification and password-reset emails arrive. Keep `APP_KEY` backed up securely; it encrypts the TOTP secrets and sessions. Configure your own inference endpoint. `HF_TOKEN` is optional for public repositories and may be required for gated files after accepting the repository license. It is used only for Hugging Face access, never for commercial inference.

## Queue and scheduler

Preferred: run one persistent worker under the hosting process manager:

```bash
php artisan queue:work database --queue=default --sleep=3 --timeout=7200 --tries=0 --max-time=86400
```

`retry_after=7500` exceeds the job timeout. Download attempts have a 24-hour deadline. Lock contention releases a queued job; transfer/integrity failures are marked failed for an explicit admin retry. Restart persistent workers after deployment with `php artisan queue:restart`.

Cron entry for scheduled expired-token cleanup:

```cron
* * * * * cd /home/USER/online-model && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

If your provider permits long cron processes but not persistent workers, a separate guarded cron may process the queue:

```cron
* * * * * flock -n /home/USER/online-model-worker.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/USER/online-model/artisan queue:work database --stop-when-empty --timeout=7200 --tries=0 >> /home/USER/online-model/storage/logs/worker.log 2>&1
```

A provider may terminate cron jobs or cap process memory/duration. In that case use a permitted worker host sharing the application's database and model filesystem; the download button cannot bypass hosting limits. Do not use the `sync` queue for production multi-GB downloads.

## Storage and recovery

`MODEL_MAX_BYTES` caps each file; `MODEL_STORAGE_BUDGET_BYTES` caps completed files plus the next download. Actual cPanel account quotas may be lower than filesystem free space. Reserve space for logs, database, backups and temporary files. Downloads are serialized with a filesystem lock on a single/shared filesystem.

If the host kills a worker without running Laravel's failure handler, confirm no download worker is running before marking its stale `downloading` entry failed in the database and removing its `{id}.part` file. Never modify status while a worker is active. Admin may then retry. The initial implementation does not support resumable range downloads or model deletion from the panel.

Back up database and `APP_KEY`; model weights can be downloaded again from pinned revisions while they remain available. Chat content is stored in the database; restrict backup access and define your retention policy. Keep the runtime private/authenticated, and test a real model request after connecting it.
