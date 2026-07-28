@extends('layouts.app')

@section('title', 'Flags')

@section('content')
    <h1>Flags</h1>

    <nav class="switcher" aria-label="Environment">
        @foreach ($environments as $option)
            <a href="{{ url('/flags') }}?env={{ urlencode($option->name) }}"
               @if ($option->is($environment)) aria-current="page" @endif>{{ $option->name }}</a>
        @endforeach
    </nav>

    <div class="card">
        <form method="GET" action="{{ url('/flags') }}" class="filters">
            <input type="hidden" name="env" value="{{ $environment->name }}">
            <div class="field">
                <label for="q">Search</label>
                <input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="key or name">
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">All</option>
                    <option value="boolean" @selected(request('type') === 'boolean')>boolean</option>
                    <option value="string" @selected(request('type') === 'string')>string</option>
                </select>
            </div>
            <div class="field checkbox">
                <input id="archived" name="archived" type="checkbox" value="1" @checked($archived)>
                <label for="archived">Archived only</label>
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="{{ url('/flags') }}?env={{ urlencode($environment->name) }}">Clear filters</a>
            <a class="btn" href="{{ url('/flags/create') }}?env={{ urlencode($environment->name) }}">New flag</a>
        </form>

        @if ($flags->isEmpty())
            <p class="empty">
                @if (request()->hasAny(['q', 'type']) || $archived)
                    No flags match these filters.
                @else
                    No flags yet. Create your first flag.
                @endif
            </p>
        @else
            <div class="table-scroll">
                <table>
                    <caption class="small muted">State shown for the {{ $environment->name }} environment.</caption>
                    <thead>
                    <tr>
                        <th scope="col">Key</th>
                        <th scope="col">Name</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last changed</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($flags as $flag)
                        @php $state = $flag->flagEnvironments->first(); @endphp
                        <tr @class(['is-killed' => $state && $state->killed])>
                            <td class="mono">
                                <a href="{{ url("/flags/{$flag->key}/edit") }}?env={{ urlencode($environment->name) }}">{{ $flag->key }}</a>
                            </td>
                            <td>{{ $flag->name }}</td>
                            <td><span class="badge badge-type">{{ $flag->type }}</span></td>
                            <td>
                                @if ($flag->isArchived())
                                    <span class="badge badge-archived">archived</span>
                                @elseif ($state && $state->killed)
                                    <span class="badge badge-killed">killed</span>
                                @elseif ($state && $state->enabled)
                                    <span class="badge badge-on">on</span>
                                @else
                                    <span class="badge badge-off">off</span>
                                @endif
                            </td>
                            <td class="small muted">{{ $flag->updated_at?->toDayDateTimeString() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            {{ $flags->links('pagination::simple-default') }}
        @endif
    </div>
@endsection
