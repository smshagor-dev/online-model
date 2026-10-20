@extends('admin.layout')
@section('title', 'My profile')
@section('section', 'My profile')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">ACCOUNT SETTINGS</span><h1>My administrator profile</h1><p>Update your display details, sign-in email and password in one place.</p></div></div>
<div class="admin-form-grid">
    <div class="admin-form-stack">
        <form class="admin-card" method="post" action="/admin/profile" enctype="multipart/form-data">
            @csrf @method('PATCH')
            <div class="section-separator"><h2>Profile details</h2><p>This information appears in the admin console.</p></div>
            <div class="form-fields single">
                <div class="field"><label for="profile_name">Display name</label><input id="profile_name" name="name" maxlength="120" value="{{ old('name', $account->name) }}" required autocomplete="name"></div>
                <div class="field"><label for="profile_email">Current sign-in email</label><input id="profile_email" type="email" value="{{ $account->email }}" readonly aria-readonly="true"></div>
                <div class="field">@include('admin.photo-field', ['avatar' => $account->avatar])</div>
            </div>
            <div class="form-actions"><button class="btn-primary-admin" type="submit">Save profile</button></div>
        </form>
        <section class="admin-card">
            <div class="section-separator"><h2>Change email address</h2><p>Your current email remains active until you confirm the new address.</p></div>
            @if($account->pending_email)
            <div class="muted-note"><strong>Confirmation pending:</strong> {{ $account->pending_email }}</div>
            <form method="post" action="/admin/profile/email" class="form-actions">@csrf @method('DELETE')<button class="secondary" type="submit">Cancel pending email change</button></form>
            @endif
            <form method="post" action="/admin/profile/email">
                @csrf
                <div class="form-fields">
                    <div class="field"><label for="new_email">New email address</label><input id="new_email" name="email" type="email" maxlength="254" required autocomplete="email" placeholder="name@company.com"></div>
                    <div class="field"><label for="email_password">Current password</label><input id="email_password" name="password" type="password" required autocomplete="current-password"></div>
                    @if($account->two_factor_confirmed_at)<div class="field"><label for="email_code">Two-factor or recovery code</label><input id="email_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif
                </div>
                <p class="field-help">Email delivery and the frontend confirmation URL must be configured.</p>
                <div class="form-actions"><button class="btn-primary-admin" type="submit">Send confirmation</button></div>
            </form>
        </section>
        <form class="admin-card" method="post" action="/admin/profile/password">
            @csrf
            <div class="section-separator"><h2>Change password</h2><p>Other devices will be signed out after your password is changed.</p></div>
            <div class="form-fields">
                <div class="field field-full"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
                <div class="field"><label for="new_password">New password</label><input id="new_password" name="password" type="password" minlength="6" maxlength="1024" required autocomplete="new-password"><p class="field-help">Minimum 6 characters.</p></div>
                <div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
                @if($account->two_factor_confirmed_at)<div class="field field-full"><label for="password_code">Two-factor or recovery code</label><input id="password_code" name="code" maxlength="64" required autocomplete="one-time-code"></div>@endif
            </div>
            <div class="form-actions"><button class="btn-primary-admin" type="submit">Change password</button></div>
        </form>
    </div>
    <aside class="admin-form-stack">
        <section class="admin-card">
            <div class="profile-summary">
                @if($account->avatar)<img src="{{ $account->avatar }}" alt="Profile photo">@else<span class="avatar-initial">{{ strtoupper(mb_substr($account->name,0,1)) }}</span>@endif
                <div><h2>{{ $account->name }}</h2><p>{{ $account->email }}</p></div>
            </div>
            <dl class="info-list"><div class="info-row"><dt>Role</dt><dd>Administrator</dd></div><div class="info-row"><dt>Two-factor sign-in</dt><dd>{{ $account->two_factor_confirmed_at ? 'Enabled' : 'Not enabled' }}</dd></div><div class="info-row"><dt>Account status</dt><dd><span class="status-pill success">Active</span></dd></div></dl>
        </section>
        <div class="muted-note"><strong>Keep your account secure</strong><div>Use a unique password, protect your recovery codes and review access whenever your device changes.</div></div>
    </aside>
</div>
@endsection
