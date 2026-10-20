@extends('admin.layout')
@section('title', 'Account details')
@section('section', 'Users')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">USER MANAGEMENT / ACCOUNT</span><h1>{{ $account->name }}</h1><p>Account details, sign-in access and connected devices.</p></div><div class="heading-actions"><a class="subtle-label" href="/admin/users">← Back to users</a></div></div>
<div class="admin-form-grid">
    <div class="admin-form-stack">
        <section class="admin-card">
            <div class="profile-summary">@if($account->avatar)<img src="{{ $account->avatar }}" alt="Profile photo">@else<span class="avatar-initial">{{ strtoupper(mb_substr($account->name, 0, 1)) }}</span>@endif<div><h2>{{ $account->name }}</h2><p>{{ $account->email }}</p></div></div>
            <dl class="info-list"><div class="info-row"><dt>Account role</dt><dd>{{ $account->is_admin ? 'Administrator' : 'Member' }}</dd></div><div class="info-row"><dt>Status</dt><dd><span class="status-pill {{ $account->is_active ? 'success' : 'warning' }}">{{ $account->is_active ? 'Active' : 'Suspended' }}</span></dd></div><div class="info-row"><dt>Joined</dt><dd>{{ $account->created_at->format('M d, Y') }}</dd></div><div class="info-row"><dt>Email</dt><dd>{{ $account->email_verified_at ? 'Verified' : 'Unverified' }}</dd></div><div class="info-row"><dt>Two-factor sign-in</dt><dd>{{ $account->two_factor_confirmed_at ? 'Enabled' : 'Not enabled' }}</dd></div></dl>
            @if($account->id === auth()->id())<div class="form-actions"><a href="/admin/profile" class="btn-primary-admin">Edit my profile →</a></div>@endif
        </section>
        @unless($account->is_admin)
        <form class="admin-card" method="post" action="/admin/users/{{ $account->id }}">
            @csrf @method('PATCH')
            <div class="section-separator"><h2>Edit account</h2><p>Update the member's name and account access.</p></div>
            <div class="form-fields"><div class="field"><label for="account_name">Full name</label><input id="account_name" name="name" maxlength="120" value="{{ old('name', $account->name) }}" required></div>
            <div class="field"><label for="account_status">Account status</label><select id="account_status" name="is_active"><option value="1" @selected(old('is_active', (int)$account->is_active) == 1)>Active</option><option value="0" @selected(old('is_active', (int)$account->is_active) == 0)>Suspended</option></select></div></div>
            <div class="form-actions"><button type="submit" class="btn-primary-admin">Save changes</button></div>
        </form>
        @endunless
        <section class="admin-card">
            <div class="section-separator"><h2>Devices and access tokens</h2><p>Devices that have previously accessed this account.</p></div>
            <div class="table-wrap"><table class="admin-table"><thead><tr><th>Device</th><th>Last used</th></tr></thead><tbody>
                @forelse($tokens as $token)<tr><td>{{ $token->name }}</td><td>{{ $token->last_used_at ?? 'Never' }}</td></tr>@empty<tr><td colspan="2" class="empty-state">No active access tokens.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>
    <aside class="admin-form-stack">
        @if($account->is_admin)
        <div class="muted-note"><strong>Protected administrator account</strong><div>Administrator account status and role cannot be modified here. Administrators update their own profile information.</div></div>
        @else
        <section class="admin-card"><h2>Revoke device access</h2><p class="section-description">Sign the user out of connected devices and revoke all API access tokens. This does not delete their account.</p><form method="post" action="/admin/users/{{ $account->id }}/tokens">@csrf @method('DELETE')<button type="submit" class="secondary">Revoke all devices</button></form></section>
        @endif
    </aside>
</div>
@endsection
