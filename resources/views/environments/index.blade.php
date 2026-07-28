@extends('layouts.app')

@section('title', 'Environments')

@section('content')
    <h1>Environments</h1>

    <div class="card">
        <p class="muted small">
            Read-only. Add an environment with <code>php artisan app:create-environment {name}</code>.
            The SDK key is the bearer credential a client fetches with; the signing secret verifies the
            document it receives. They are separate on purpose: only the SDK key ever travels in a request.
        </p>

        @if ($environments->isEmpty())
            <p class="empty">No environments yet. Run the seeder to create production and staging.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">SDK key</th>
                        <th scope="col">Signing secret</th>
                        <th scope="col">Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($environments as $environment)
                        <tr>
                            <td>{{ $environment->name }}</td>
                            <td>
                                <details>
                                    <summary>Reveal</summary>
                                    <pre>{{ $environment->sdk_key }}</pre>
                                </details>
                            </td>
                            <td>
                                <details>
                                    <summary>Reveal</summary>
                                    <pre>{{ $environment->signing_secret }}</pre>
                                </details>
                            </td>
                            <td class="small muted">{{ $environment->created_at?->toDayDateTimeString() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
