<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Jingle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class JingleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'jingles' => Jingle::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'media' => 'required|file|max:524288|mimes:mp4,mov,mkv,webm,ts,mpeg,mpg,avi',
        ]);

        $file = $request->file('media');
        $directory = storage_path('app/jingles');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $filepath = $directory . '/' . $filename;
        $file->move($directory, $filename);

        $duration = $this->probeDuration($filepath);
        if ($duration <= 0) {
            @unlink($filepath);
            return response()->json(['success' => false, 'error' => 'Could not read media duration.'], 422);
        }

        $jingle = Jingle::create([
            'name'      => $file->getClientOriginalName(),
            'filepath'  => $filepath,
            'mime_type' => $file->getMimeType(),
            'filesize'  => filesize($filepath),
            'duration'  => $duration,
            'sort_order' => Jingle::max('sort_order') + 1,
        ]);

        return response()->json(['success' => true, 'jingle' => $jingle]);
    }

    public function destroy(Jingle $jingle): JsonResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);

        if (file_exists($jingle->filepath)) {
            @unlink($jingle->filepath);
        }
        $jingle->delete();

        return response()->json(['success' => true]);
    }

    private function probeDuration(string $filepath): float
    {
        try {
            $proc = new Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $filepath,
            ]);
            $proc->setTimeout(30);
            $proc->run();
            $out = trim($proc->getOutput());
            if ($proc->isSuccessful() && is_numeric($out)) {
                return (float) $out;
            }
        } catch (\Throwable) {}
        return 0.0;
    }
}
