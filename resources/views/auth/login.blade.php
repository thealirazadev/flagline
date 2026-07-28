@extends('layouts.app')

@section('title', 'Log in to flagline')

@section('content')
    <div class="card card-narrow">
        <h1>flagline</h1>
        <p class="muted small">Operator sign in.</p>

        <form method="POST" action="{{ url('/login') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username">
                @error('email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn">Log in</button>
        </form>
    </div>
@endsection
