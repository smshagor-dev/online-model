@extends('admin.layout')
@section('content')
<h1>My administrator profile</h1>
<form class="panel" method="post" action="/admin/profile" enctype="multipart/form-data">
    @csrf @method('PATCH')
    <label for="profile_name">Name</label><input id="profile_name" name="name" maxlength="120" value="{{old('name', $account->name)}}" required autocomplete="name">
    <p>Sign-in email: <strong>{{$account->email}}</strong></p>
    @include('admin.photo-field', ['avatar' => $account->avatar])
    <button>Save profile</button>
</form>
<h2>Change email</h2>
<p>The existing email stays active until confirmation at the new address. Email delivery and the frontend confirmation URL must be configured.</p>
@if($account->pending_email)
    <p>Awaiting confirmation: {{$account->pending_email}}</p>
    <form method="post" action="/admin/profile/email">@csrf @method('DELETE')<button class="secondary">Cancel pending email change</button></form>
@endif
<form class="panel" method="post" action="/admin/profile/email">
    @csrf
    <label for="new_email">New email</label><input id="new_email" name="email" type="email" maxlength="254" required autocomplete="email">
    <label for="email_password">Current password</label><input id="email_password" name="password" type="password" required autocomplete="current-password">
    @if($account->two_factor_confirmed_at)<label for="email_code">Two-factor or recovery code</label><input id="email_code" name="code" maxlength="64" required autocomplete="one-time-code">@endif
    <button>Send confirmation</button>
</form>
<h2>Change password</h2>
<form class="panel" method="post" action="/admin/profile/password">
    @csrf
    <label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password">
    <label for="new_password">New password</label><input id="new_password" name="password" type="password" minlength="12" maxlength="1024" required autocomplete="new-password">
    <label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
    @if($account->two_factor_confirmed_at)<label for="password_code">Two-factor or recovery code</label><input id="password_code" name="code" maxlength="64" required autocomplete="one-time-code">@endif
    <p><small>Use at least 12 characters with uppercase, lowercase and numbers. Other devices will be signed out.</small></p>
    <button>Change password</button>
</form>
@endsection
