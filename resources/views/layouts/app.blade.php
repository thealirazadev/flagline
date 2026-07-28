<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'flagline')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="{{ url('/flags') }}">flagline</a>
            <nav aria-label="Main">
                <a href="{{ url('/flags') }}">Flags</a>
                <a href="{{ url('/environments') }}">Environments</a>
                <a href="{{ url('/audit') }}">Audit</a>
            </nav>
            <div class="spacer"></div>
            <span class="small muted">{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit" class="btn-link">Log out</button>
            </form>
        </div>
    </header>
@endauth

<main>
    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="flash flash-warning" role="status">{{ session('warning') }}</div>
    @endif
    @if (session('error'))
        <div class="flash flash-error" role="alert">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>
</body>
</html>
