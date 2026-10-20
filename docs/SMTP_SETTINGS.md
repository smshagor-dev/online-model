# SMTP settings

Open the administration console and select **SMTP settings** from the left sidebar, or navigate to `/admin/smtp-settings`.

Configure the host, port, security, username and SMTP app password along with a verified sender email and optional reply-to address. Choose **Use database SMTP settings**, save with your current administrator password (and two-factor code when enabled), and use **Send test email**. The test is sent only to the signed-in administrator's email.

## Delivery configuration

When database SMTP is enabled, Laravel's outgoing mail configuration is populated from the `smtp_settings` table when the mail manager is first resolved. This applies to password-reset links, email verification, account email-change notifications, and other Laravel mail. Laravel/Symfony Mailer uses `smtps` for port 465 SSL and SMTP with TLS required for STARTTLS on port 587. The **None** option is only appropriate for trusted, isolated networks.

SMTP passwords use Laravel's encrypted model cast, backed by `APP_KEY`. The password is not included in HTML responses, URLs, logs, or source control. Saving a blank password retains the current credential when the username is unchanged. Changing the SMTP username requires a new password; removing it clears the saved password. Both saving credentials and sending a test require current-password verification and two-factor verification (if configured). Test emails cannot be sent to an arbitrary address and both write/test routes are rate-limited.

When database SMTP is inactive, the existing deployment mail configuration remains in use for compatibility. To make SMTP fully controlled from the database, enable this page and complete the required fields. Database credentials, `APP_KEY`, and process/runtime secrets remain in server configuration.

## Deployment

Apply the new migration and clear Laravel's cached configuration:

```bash
php artisan migrate --force
php artisan optimize:clear
```

If using long-running queue workers, restart them after changing the SMTP settings (`php artisan queue:restart`). The worker processes otherwise retain previously resolved mailers. Hosting firewalls and mail provider account/app-password restrictions may affect actual delivery. A successful send means the mail transport accepted the message, not that it arrived in the recipient inbox.

Tests: `php artisan test --filter=SmtpSettingsTest`.

The table is a singleton keyed by ID 1; it is intentionally separate from public, user-editable workspace preferences.
