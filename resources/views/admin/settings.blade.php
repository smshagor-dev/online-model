@extends('admin.layout')
@section('title', 'Workspace settings')
@section('section', 'Settings')
@section('content')
<div class="admin-page-header"><div><span class="eyebrow">ADMINISTRATION</span><h1>Workspace settings</h1><p>Update business preferences directly from the dashboard.</p></div></div>
<form method="post" action="/admin/settings" class="settings-layout">
@csrf @method('PATCH')
<section class="card"><div class="panel-heading"><h2>Company profile</h2><small>Visible in administration</small></div>
<label for="site_name">Workspace name</label><input id="site_name" name="site_name" maxlength="60" required value="{{ old('site_name', $settings['site_name']) }}">
<label for="support_email">Support email (optional)</label><input id="support_email" name="support_email" type="email" maxlength="254" value="{{ old('support_email', $settings['support_email']) }}" placeholder="support@example.com">
<label for="dashboard_note">Dashboard message</label><textarea id="dashboard_note" name="dashboard_note" maxlength="220" rows="3">{{ old('dashboard_note', $settings['dashboard_note']) }}</textarea></section>
<section class="card"><div class="panel-heading"><h2>Access &amp; usage</h2><small>Database-controlled policies</small></div>
<label for="registration_enabled">New account registration</label><select id="registration_enabled" name="registration_enabled"><option value="1" @selected(old('registration_enabled', $settings['registration_enabled']) == '1')>Open</option><option value="0" @selected(old('registration_enabled', $settings['registration_enabled']) == '0')>Closed</option></select>
<div class="settings-fields"><div><label for="chat_daily_limit">Chat requests per day</label><input id="chat_daily_limit" type="number" min="1" max="100000" name="chat_daily_limit" required value="{{ old('chat_daily_limit', $settings['chat_daily_limit']) }}"></div><div><label for="task_daily_limit">Task runs per day</label><input id="task_daily_limit" type="number" min="1" max="10000" name="task_daily_limit" required value="{{ old('task_daily_limit', $settings['task_daily_limit']) }}"></div></div><p>Updated limits apply to new requests, not jobs already running.</p></section>
<section class="card settings-confirm"><div class="panel-heading"><h2>Verify changes</h2><small>Restricted to verified administrators</small></div>
<label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" required autocomplete="current-password">
@if(auth()->user()->two_factor_confirmed_at)<label for="code">Authenticator or recovery code</label><input id="code" name="code" maxlength="64" required autocomplete="one-time-code">@endif
<button class="btn-primary-admin" type="submit">Save workspace settings</button></section>
</form>
<div class="card"><h2>Infrastructure credentials stay private</h2><p>Database connection credentials, APP_KEY, hosting process settings and API secrets remain in secure deployment configuration. This page intentionally does not expose them.</p></div>
@endsection
