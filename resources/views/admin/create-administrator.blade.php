@extends('admin.layout')
@section('content')
<h1>Create administrator</h1>
<p>This creates an active administrator with a verified sign-in email and access to the admin panel. Existing user accounts are never promoted by this form.</p>
<form class="panel" method="post" action="/admin/administrators" enctype="multipart/form-data">
    @csrf
    <label for="admin_name">Name</label><input id="admin_name" name="name" maxlength="120" value="{{old('name')}}" required autocomplete="off">
    <label for="admin_email">Email</label><input id="admin_email" name="email" type="email" maxlength="254" value="{{old('email')}}" required autocomplete="off">
    @include('admin.photo-field')
    <label for="admin_password">New administrator password</label><input id="admin_password" name="password" type="password" minlength="12" maxlength="1024" required autocomplete="new-password">
    <label for="admin_password_confirmation">Confirm new administrator password</label><input id="admin_password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
    <p><small>At least 12 characters, including uppercase, lowercase and numbers.</small></p>
    <h2>Confirm your identity</h2>
    <label for="creator_password">Your current password</label><input id="creator_password" name="current_password" type="password" required autocomplete="current-password">
    @if(auth()->user()->two_factor_confirmed_at)<label for="creator_code">Your two-factor or recovery code</label><input id="creator_code" name="code" maxlength="64" required autocomplete="one-time-code">@endif
    <button>Create administrator</button>
</form>
@endsection
