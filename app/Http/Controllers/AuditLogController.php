<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Flag;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $entries = AuditLog::query()
            ->when($request->filled('flag_id'), fn ($query) => $query->where('flag_id', $request->integer('flag_id')))
            ->when($request->filled('environment_id'),
                fn ($query) => $query->where('environment_id', $request->integer('environment_id')))
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')->toString()))
            ->with(['user', 'flag', 'environment'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('audit.index', [
            'entries' => $entries,
            'flags' => Flag::orderBy('key')->get(),
            'environments' => $this->environments(),
            'actions' => AuditLog::ACTIONS,
        ]);
    }
}
