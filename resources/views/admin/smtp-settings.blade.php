@extends('admin.layout')
@section('title', 'SMTP settings')
@section('section', 'SMTP settings')
@section('content')
<div class="admin-page-heading">
    <div><span class="eyebrow">EMAIL DELIVERY</span><h1>SMTP settings</h1><p>Manage outgoing email delivery, sender identity and test your connection. Configuration is stored in the database.</p></div>
    <div class="heading-actions"><span class="status-pill {{ $smtp?->enabled ? 'success' : 'warning' }}">{{ $smtp?->enabled ? 'Database SMTP enabled' : 'Database SMTP inactive' }}</span></div>
</div>
<div class="admin-form-grid">
    <div class="admin-form-stack">
        <form class="admin-form-stack" method="post" action="/admin/smtp-settings" autocomplete="off">
            @csrf @method('PATCH')
            <section class="admin-card">
                <div class="section-separator"><h2>Mail server</h2><p>Enter the credentials supplied by your email hosting provider or SMTP relay.</p></div>
                <div class="form-fields">
                    <div class="field field-full"><label for="smtp_enabled">Delivery mode</label>
                        <select id="smtp_enabled" name="enabled" required>
                            <option value="1" @selected(old('enabled', $smtp?->enabled ? '1' : '0') == '1')>Use database SMTP settings</option>
                            <option value="0" @selected(old('enabled', $smtp?->enabled ? '1' : '0') == '0')>Inactive (use existing deployment mailer)</option>
                        </select>
                        <p class="field-help">When enabled, the database configuration takes precedence over mail values in deployment files.</p>
                    </div>
                    <div class="field"><label for="smtp_host">SMTP host</label><input id="smtp_host" name="host" value="{{ old('host', $smtp?->host) }}" placeholder="smtp.example.com" maxlength="253" required spellcheck="false"></div>
                    <div class="field"><label for="smtp_port">Port</label><input id="smtp_port" name="port" type="number" min="1" max="65535" value="{{ old('port', $smtp?->port ?? 587) }}" required></div>
                    <div class="field"><label for="smtp_encryption">Connection security</label><select id="smtp_encryption" name="encryption" required>
                        <option value="tls" @selected(old('encryption', $smtp?->encryption ?? 'tls') === 'tls')>STARTTLS required (usually 587)</option>
                        <option value="ssl" @selected(old('encryption', $smtp?->encryption ?? 'tls') === 'ssl')>SSL/TLS (usually 465)</option>
                        <option value="none" @selected(old('encryption', $smtp?->encryption ?? 'tls') === 'none')>None (trusted networks only)</option>
                    </select></div>
                    <div class="field"><label for="smtp_username">Username (optional)</label><input id="smtp_username" name="username" value="{{ old('username', $smtp?->username) }}" maxlength="255" placeholder="mailbox@example.com" autocomplete="off" spellcheck="false"></div>
                    <div class="field field-full"><label for="smtp_password">SMTP password {{ $smtp?->password ? '(saved)' : '' }}</label><input id="smtp_password" name="password" type="password" maxlength="1024" placeholder="{{ $smtp?->password ? 'Leave blank to keep the saved password' : 'Enter an app password or SMTP credential' }}" autocomplete="new-password"><p class="field-help">{{ $smtp?->password ? 'An encrypted credential is saved. The password is never displayed again. Enter a new password only to replace it.' : 'SMTP passwords are encrypted before storage; no credential is returned to the browser.' }}</p></div>
                </div>
            </section>
            <section class="admin-card">
                <div class="section-separator"><h2>Sender identity</h2><p>These details appear in emails sent by the platform.</p></div>
                <div class="form-fields">
                    <div class="field"><label for="smtp_from_name">From name</label><input id="smtp_from_name" name="from_name" maxlength="120" value="{{ old('from_name', $smtp?->from_name ?? 'OpenMindAI') }}" required placeholder="OpenMindAI"></div>
                    <div class="field"><label for="smtp_from_address">From email</label><input id="smtp_from_address" name="from_address" type="email" maxlength="254" value="{{ old('from_address', $smtp?->from_address) }}" required placeholder="noreply@yourdomain.com"></div>
                    <div class="field field-full"><label for="smtp_reply_to">Reply-to email (optional)</label><input id="smtp_reply_to" name="reply_to" type="email" maxlength="254" value="{{ old('reply_to', $smtp?->reply_to) }}" placeholder="support@yourdomain.com"></div>
                </div>
            </section>
            <section class="admin-card">
                <div class="section-separator"><h2>Confirm changes</h2><p>Your administrator password is required to change email delivery credentials.</p></div>
                <div class="form-fields">
                    <div class="field"><label for="smtp_current_password">Your current password</label><input id="smtp_current_password" name="current_password" type="password" required autocomplete="current-password"></div>
                    @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label for="smtp_code">Authenticator or recovery code</label><input id="smtp_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary-admin">Save SMTP settings</button></div>
            </section>
        </form>
    </div>
    <aside class="admin-form-stack">
        <section class="admin-card">
            <h2>Connection summary</h2>
            <p class="section-description">Database settings apply to new outgoing emails when enabled.</p>
            <dl class="info-list">
                <div class="info-row"><dt>Status</dt><dd><span class="status-pill {{ $smtp?->enabled ? 'success' : 'warning' }}">{{ $smtp?->enabled ? 'Enabled' : 'Inactive' }}</span></dd></div>
                <div class="info-row"><dt>Server</dt><dd>{{ $smtp?->host ?: 'Not configured' }}</dd></div>
                <div class="info-row"><dt>Port / security</dt><dd>{{ $smtp?->port ?? '—' }} · {{ strtoupper($smtp?->encryption ?? '—') }}</dd></div>
                <div class="info-row"><dt>Password stored</dt><dd>{{ $smtp?->password ? 'Encrypted' : 'None' }}</dd></div>
                <div class="info-row"><dt>Last test email</dt><dd>{{ $smtp?->last_tested_at?->format('M d, Y H:i') ?? 'Never' }}</dd></div>
            </dl>
        </section>
        <section class="admin-card">
            <h2>Send a test email</h2>
            <p class="section-description">Sends one test message to your own administrator email address to confirm SMTP delivery.</p>
            <div class="muted-note"><strong>Recipient</strong><div>{{ auth()->user()->email }}</div></div>
            <form method="post" action="/admin/smtp-settings/test" class="form-fields single" style="margin-top:17px">
                @csrf
                <div class="field"><label for="smtp_test_password">Your current password</label><input id="smtp_test_password" name="current_password" type="password" required autocomplete="current-password"></div>
                @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label for="smtp_test_code">Authenticator or recovery code</label><input id="smtp_test_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif
                <button type="submit" class="btn-primary-admin" @disabled(! $smtp?->enabled)>Send test email →</button>
            </form>
        </section>
        <div class="muted-note"><strong>Security and hosting</strong><div>The SMTP password is encrypted using Laravel's application key. Protect APP_KEY and back it up securely. Some shared hosting providers block outbound SMTP ports. For queued email jobs, restart long-running queue workers after changing settings.</div></div>
    </aside>
</div>
@endsection
