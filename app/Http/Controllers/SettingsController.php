<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\YouTubeMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $settings = Setting::orderBy('group')->orderBy('key')->get()
            ->groupBy('group');

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings'       => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable|string',
        ]);

        foreach ($data['settings'] as $item) {
            Setting::where('key', $item['key'])->update(['value' => $item['value'] ?? '']);

            // Write YouTube cookies to file so yt-dlp can use them
            if ($item['key'] === 'youtube_cookies') {
                $cookiePath = storage_path('app/youtube_cookies.txt');
                $content = trim($item['value'] ?? '');
                if ($content !== '') {
                    file_put_contents($cookiePath, $content);
                } elseif (file_exists($cookiePath)) {
                    @unlink($cookiePath);
                }
            }
        }

        return back()->with('success', 'Settings saved');
    }

    /**
     * Test the YouTube API key by making a lightweight API call.
     */
    public function testYoutubeKey(): JsonResponse
    {
        abort_unless(auth()->user()->is_admin ?? false, 403);

        $apiKey = Setting::get('youtube_api_key', '');
        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'error' => 'No YouTube API key configured. Add one in the YouTube settings section.',
            ]);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)->get(
                'https://www.googleapis.com/youtube/v3/videos',
                [
                    'part' => 'id',
                    'id' => 'dQw4w9WgXcQ', // Rick Astley — never gonna give you up
                    'key' => $apiKey,
                ]
            );

            if ($response->successful()) {
                $data = $response->json();
                $found = count($data['items'] ?? []) > 0;
                return response()->json([
                    'success' => $found,
                    'message' => $found
                        ? 'API key is valid and working. YouTube Data API v3 accessible.'
                        : 'API key accepted but returned no results — this is unusual.',
                ]);
            }

            $error = $response->json('error.message', 'Unknown error');
            $code = $response->json('error.code', $response->status());

            return response()->json([
                'success' => false,
                'error' => "YouTube API error ({$code}): {$error}",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }
}
