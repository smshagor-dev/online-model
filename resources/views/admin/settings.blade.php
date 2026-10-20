@extends('admin.layout')
@section('title', 'Workspace settings')
@section('section', 'Settings')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>Workspace settings</h1><p>Manage branding, registration and usage policies stored in the application database.</p></div></div>
<form method="post" action="/admin/settings" class="settings-layout">
    @csrf @method('PATCH')
    <section class="card"><div class="section-separator"><h2>Company profile</h2><p>Branding and support information displayed in the administration workspace.</p></div>
        <div class="field"><label for="site_name">Workspace name</label><input id="site_name" name="site_name" maxlength="60" required value="{{ old('site_name', $settings['site_name']) }}"></div>
        <div class="field"><label for="support_email">Support email (optional)</label><input id="support_email" name="support_email" type="email" maxlength="254" value="{{ old('support_email', $settings['support_email']) }}" placeholder="support@example.com"></div>
        <div class="field"><label for="dashboard_note">Dashboard message</label><textarea id="dashboard_note" name="dashboard_note" maxlength="220" rows="3">{{ old('dashboard_note', $settings['dashboard_note']) }}</textarea></div>
    </section>
    <section class="card"><div class="section-separator"><h2>Access and usage</h2><p>Business policies controlled by the database.</p></div>
        <div class="field"><label for="registration_enabled">New account registration</label><select id="registration_enabled" name="registration_enabled"><option value="1" @selected(old('registration_enabled', $settings['registration_enabled']) == '1')>Open</option><option value="0" @selected(old('registration_enabled', $settings['registration_enabled']) == '0')>Closed</option></select></div>
        <div class="settings-fields"><div class="field"><label for="chat_daily_limit">Chat requests per day</label><input id="chat_daily_limit" type="number" min="1" max="100000" name="chat_daily_limit" required value="{{ old('chat_daily_limit', $settings['chat_daily_limit']) }}"></div><div class="field"><label for="task_daily_limit">Task runs per day</label><input id="task_daily_limit" type="number" min="1" max="10000" name="task_daily_limit" required value="{{ old('task_daily_limit', $settings['task_daily_limit']) }}"></div></div>
        <p class="field-help">Updates apply to new requests, not jobs already running.</p>
    </section>
    <section class="card settings-confirm"><div class="section-separator"><h2>Confirm workspace changes</h2><p>Verify your administrator credentials before saving these settings.</p></div>
        <div class="form-fields"><div class="field"><label for="settings_password">Current password</label><input id="settings_password" type="password" name="current_password" required autocomplete="current-password"></div>
        @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label for="settings_code">Authenticator or recovery code</label><input id="settings_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif</div>
        <div class="form-actions"><button class="btn-primary-admin" type="submit">Save workspace settings</button></div>
    </section>
</form>
<div class="muted-note"><strong>Infrastructure credentials stay private.</strong><div>Database passwords, APP_KEY, API secrets and runtime hosting settings remain in protected deployment configuration and are not exposed in these business controls.</div></div>
@endsection
