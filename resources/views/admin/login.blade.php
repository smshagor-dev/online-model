<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $challenging ? 'Verify your identity' : 'Admin sign in' }} · Openmindai</title>
    <link rel="icon" href="{{ asset('openmindai-logo.png') }}">
    <style>
        :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,sans-serif;background:#0c1017;color:#edf2f7}*{box-sizing:border-box}body{margin:0;min-height:100svh;display:flex;flex-direction:column;background:radial-gradient(ellipse at 50% 0%,#172b35 0%,transparent 55%)}header{padding:28px 6%}.brand{display:inline-flex;align-items:center;gap:12px;color:inherit;text-decoration:none;font-size:21px;font-weight:650;letter-spacing:-.6px}.brand img{border-radius:10px}main{flex:1;display:grid;place-items:center;padding:24px 20px 64px}.card{width:100%;max-width:440px;padding:36px;background:#121923;border:1px solid #293440;border-radius:22px;box-shadow:0 24px 90px #0004}.eyebrow{font-size:11px;font-weight:700;letter-spacing:1.8px;color:#65d5bd;margin:0 0 18px}h1{font-size:29px;letter-spacing:-1px;line-height:1.2;margin:0 0 12px}.description{font-size:14px;color:#9dabbc;line-height:1.7;margin:0 0 28px}label{display:block;font-size:13px;font-weight:600;margin:0 0 9px}input{width:100%;min-height:48px;border:1px solid #344353;background:#0c121b;color:#edf2f7;border-radius:10px;padding:12px 14px;font:inherit;font-size:15px;margin:0 0 22px}input:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid #65d5bd;outline-offset:4px}input:focus{border-color:#65d5bd}button{width:100%;min-height:48px;border:0;border-radius:10px;background:#77e2c7;color:#08251e;font:inherit;font-size:14px;font-weight:700;cursor:pointer}button:hover{background:#a0eedb}.error{background:#3b2027;border:1px solid #7b3947;color:#ffd2da;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.6;margin:0 0 22px}.back{display:block;text-align:center;color:#acbacb;font-size:13px;margin-top:22px;text-underline-offset:4px}.footer{text-align:center;color:#7e8c9e;font-size:12px;line-height:1.7;margin:24px 0 0}footer{text-align:center;color:#68788c;font-size:12px;padding:0 20px 24px}@media(max-width:480px){header{padding:24px}main{padding:20px 16px 40px}.card{padding:28px 24px}h1{font-size:26px}}
    </style>
</head>
<body>
<header><a class="brand" href="/admin/login"><img src="{{ asset('openmindai-logo.png') }}" width="34" height="34" alt="">Openmindai</a></header>
<main><section class="card" aria-labelledby="login-title">
    <p class="eyebrow">ADMIN CONSOLE</p>
    <h1 id="login-title">{{ $challenging ? 'Verify your identity' : 'Welcome back' }}</h1>
    <p class="description">{{ $challenging ? 'Two-factor authentication is enabled on your account. Enter your authenticator code or a recovery code to continue.' : 'Sign in to manage your users, models and workspace.' }}</p>
    @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="/admin/login">
        @csrf
        @if($challenging)
            <label for="code">Authenticator or recovery code</label>
            <input id="code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required autofocus spellcheck="false" autocapitalize="off">
        @else
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="you@example.com" required autocomplete="username" autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        @endif
        <button type="submit">{{ $challenging ? 'Verify and sign in' : 'Sign in' }}</button>
    </form>
    @if($challenging)<a class="back" href="/admin/login?restart=1">Back to sign in</a>@endif
    <p class="footer">Authorized administrators only</p>
</section></main>
<footer>Openmindai · Admin console</footer>
</body>
</html>
