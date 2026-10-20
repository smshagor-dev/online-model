# Administrator setup and profile

## Seed the first administrator

Set explicit values in the deployed Laravel environment:

```dotenv
ADMIN_NAME="Your name"
ADMIN_EMAIL=your-admin-email@example.com
ADMIN_PASSWORD=your-own-strong-password
# Optional absolute local PNG/JPEG/WebP path, <= 512x512 and 96 KB:
ADMIN_AVATAR_PATH=
```

Use at least 12 password characters, including uppercase, lowercase and numbers. No default sign-in password is shipped. Apply the database migrations, clear stale configuration and run:

```bash
php artisan migrate --force
php artisan config:clear
php artisan db:seed --class=AdminSeeder --force
php artisan config:cache
```

The seed creates an active, verified administrator at `/admin/login`. Running it again does not reset the existing administrator's password, name, photo, suspension or security settings. A matching regular user is rejected rather than promoted. Optional photos must be readable local files and pass raster/image-size validation. Invalid settings do not create an account. `php artisan db:seed` also invokes this seed when both email and password are configured; otherwise it reports that admin creation was skipped.

After bootstrap, remove the bootstrap password from the environment and refresh the config cache if it is no longer needed. Existing login remains valid; reseeding explicitly requires a valid configured password even though it never overwrites an existing account. The existing interactive `php artisan admin:create` command remains available.

## Edit your profile

Use **My profile** in the admin header to update your name, upload/replace/remove your photo, request a verified email change or change your password. Photo resizing happens in the browser; the backend independently accepts only PNG/JPEG/WebP up to 512x512 pixels and 96 KB. Without JavaScript, upload an already-sized file. Photos are stored in the existing user avatar DB field and also appear in Users and the normal frontend profile; no public storage symlink is needed.

Password changes require the current password and enabled 2FA/recovery code. Other database sessions, API tokens and old reset tokens are revoked; the current admin session is regenerated. Email changes use the existing one-hour confirmation workflow: the old email stays active until confirmation, with all devices revoked upon completion. SMTP and `FRONTEND_URL` must be configured for email delivery/confirmation.

## Create another administrator

Open **New admin** or Users → **Create administrator**. Enter the new name, unique email, password/confirmation and optional photo, then confirm your own current password and enabled 2FA/recovery code. Only a verified administrator session can submit the form; ordinary/API-only users cannot access it. The new account is explicitly granted active, verified administrator access and can sign in immediately. It does not inherit the creator's sessions, tokens, 2FA or personal profile. Existing accounts cannot be promoted or overwritten through this form. Each admin can edit their own profile; ordinary user management remains separate.

Deploy these changes, refresh Laravel configuration/routes/views and restart the application as usual. No new database migration is required beyond the project's existing user settings/avatar migration. Backend coverage verifies seeding/idempotency, image validation, session/2FA authorization, profile changes and credential revocation. Browser photo resize and SMTP delivery still need acceptance checks on the deployed host.
