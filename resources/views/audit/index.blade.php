@extends('layouts.app')

@section('title', 'Audit trail')

@section('content')
    <h1>Audit trail</h1>

    <div class="card">
        <form method="GET" action="{{ url('/audit') }}" class="filters">
            <div class="field">
                <label for="flag_id">Flag</label>
                <select id="flag_id" name="flag_id">
                    <option value="">All</option>
                    @foreach ($flags as $flag)
                        <option value="{{ $flag->id }}" @selected(request('flag_id') == $flag->id)>{{ $flag->key }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="environment_id">Environment</label>
                <select id="environment_id" name="environment_id">
                    <option value="">All</option>
                    @foreach ($environments as $environment)
                        <option value="{{ $environment->id }}"
                                @selected(request('environment_id') == $environment->id)>{{ $environment->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">All</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="{{ url('/audit') }}">Clear filters</a>
        </form>

        @if ($entries->isEmpty())
            <p class="empty">
                @if (request()->hasAny(['flag_id', 'environment_id', 'action']))
                    No audit entries match these filters.
                @else
                    No changes recorded yet. Every flag mutation shows up here.
                @endif
            </p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Flag</th>
                        <th scope="col">Environment</th>
                        <th scope="col">Version</th>
                        <th scope="col">Change</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="small">{{ $entry->created_at?->toDayDateTimeString() }}</td>
                            <td class="small">{{ $entry->user?->email ?? 'system' }}</td>
                            <td class="mono">{{ $entry->action }}</td>
                            <td class="mono">{{ $entry->flag?->key ?? '-' }}</td>
                            <td>{{ $entry->environment?->name ?? '-' }}</td>
                            <td class="mono">{{ $entry->ruleset_version ?? '-' }}</td>
                            <td>
                                <details>
                                    <summary>before / after</summary>
                                    <p class="small muted">Before</p>
                                    <pre>{{ $entry->before === null ? 'null' : json_encode($entry->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    <p class="small muted">After</p>
                                    <pre>{{ $entry->after === null ? 'null' : json_encode($entry->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            {{ $entries->links('pagination::simple-default') }}
        @endif
    </div>
@endsection
