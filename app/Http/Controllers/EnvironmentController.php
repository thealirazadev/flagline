<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class EnvironmentController extends Controller
{
    public function index(): View
    {
        return view('environments.index', [
            'environments' => $this->environments(),
        ]);
    }
}
