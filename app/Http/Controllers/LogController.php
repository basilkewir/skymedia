<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = StreamLog::with('channel:id,name')->latest();

        if ($request->filled('channel_id')) {
            $query->where('channel_id', $request->channel_id);
        }
        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }
        if ($request->filled('event')) {
            $query->where('event', 'like', '%' . $request->event . '%');
        }

        $logs     = $query->paginate(100)->withQueryString();
        $channels = Channel::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Logs/Index', [
            'logs'     => $logs,
            'channels' => $channels,
            'filters'  => $request->only(['channel_id', 'level', 'event']),
        ]);
    }
}
