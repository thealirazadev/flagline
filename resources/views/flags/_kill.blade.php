@unless ($flag->isArchived())
    @if ($state && $state->killed)
        <form method="POST" action="{{ url("/flags/{$flag->key}/environments/{$environment->name}/restore") }}">
            @csrf
            <button type="submit" class="btn btn-secondary">Restore</button>
        </form>
    @elseif ($state)
        <details>
            <summary>Kill</summary>
            <form method="POST" action="{{ url("/flags/{$flag->key}/environments/{$environment->name}/kill") }}">
                @csrf
                <button type="submit" class="btn btn-danger">Yes, kill {{ $flag->key }} in {{ $environment->name }}</button>
            </form>
        </details>
    @endif
@endunless
