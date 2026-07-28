<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The environment switcher is the `env` query parameter. Production is the
     * default, and an unknown name falls back to it rather than erroring.
     */
    protected function selectedEnvironment(Request $request): Environment
    {
        $name = (string) $request->query('env', 'production');

        return Environment::where('name', $name)->first()
            ?? Environment::where('name', 'production')->firstOrFail();
    }

    /**
     * @return Collection<int, Environment>
     */
    protected function environments(): Collection
    {
        return Environment::orderBy('name')->get();
    }
}
