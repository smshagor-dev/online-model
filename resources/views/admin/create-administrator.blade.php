@extends('admin.layout')
@section('title', 'Create administrator')
@section('section', 'Administrators')
@section('content')
<div class="admin-page-heading">
    <div><span class="eyebrow">TEAM MANAGEMENT</span><h1>Create administrator</h1><p>Add a new administrator to the management console. Every administrator has their own sign-in and credentials.</p></div>
    <div class="heading-actions"><a class="subtle-label" href="/admin/users">← Back to users</a></div>
</div>
<form method="post" action="/admin/administrators" enctype="multipart/form-data" class="admin-form-grid">
    @csrf
    <div class="admin-form-stack">
        <section class="admin-card">
            <div class="section-separator"><h2>Account details</h2><p>Use the administrator's legal or preferred display name and sign-in email.</p></div>
            <div class="form-fields">
                <div class="field"><label for="admin_name">Full name</label><input id="admin_name" name="name" type="text" maxlength="120" value="{{ old('name') }}" placeholder="Administrator name" required autocomplete="off"></div>
                <div class="field"><label for="admin_email">Email address</label><input id="admin_email" name="email" type="email" maxlength="254" value="{{ old('email') }}" placeholder="name@company.com" required autocomplete="off"></div>
                <div class="field"><label for="admin_password">Password</label><input id="admin_password" name="password" type="password" minlength="6" maxlength="1024" required autocomplete="new-password"><p class="field-help">Use at least 6 characters.</p></div>
                <div class="field"><label for="admin_password_confirmation">Confirm password</label><input id="admin_password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
            </div>
        </section>
        <section class="admin-card"><div class="section-separator"><h2>Profile image</h2><p>Optional. Add a photo to identify this administrator within the workspace.</p></div>@include('admin.photo-field')</section>
        <section class="admin-card">
            <div class="section-separator"><h2>Confirm your identity</h2><p>Verify your own administrator account before granting privileged access.</p></div>
            <div class="form-fields">
                <div class="field"><label for="creator_password">Your current password</label><input id="creator_password" name="current_password" type="password" required autocomplete="current-password"></div>
                @if(auth()->user()->two_factor_confirmed_at)<div class="field"><label for="creator_code">Two-factor or recovery code</label><input id="creator_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif
            </div>
            <div class="form-actions"><button class="btn-primary-admin" type="submit">Create administrator →</button><a href="/admin/users">Cancel</a></div>
        </section>
    </div>
    <aside class="admin-form-stack">
        <section class="admin-card"><h2>Administrator access</h2><p class="section-description">The new account will be active immediately and can sign in to the administration console.</p>
            <dl class="info-list"><div class="info-row"><dt>Account role</dt><dd>Administrator</dd></div><div class="info-row"><dt>Account status</dt><dd><span class="status-pill success">Active</span></dd></div><div class="info-row"><dt>Sign-in email</dt><dd>Verified at creation</dd></div></dl>
        </section>
        <div class="muted-note"><strong>Security reminder</strong><div>Only grant administrator access to trusted people. Use a unique password and enable two-factor authentication after first sign-in.</div></div>
    </aside>
</form>
@endsection
