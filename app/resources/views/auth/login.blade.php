<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in to OpenMemory</title>
</head>
<body>
    <main>
        <h1>Sign in to OpenMemory</h1>
        <p>Use the local account created by your server administrator.</p>
        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif
        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <p><label>Email <input name="email" type="email" autocomplete="username" required value="{{ old('email') }}"></label></p>
            <p><label>Password <input name="password" type="password" autocomplete="current-password" required></label></p>
            <button type="submit">Sign in</button>
        </form>
        <p>This login is separate from Internet Identity and application API keys.</p>
    </main>
</body>
</html>
