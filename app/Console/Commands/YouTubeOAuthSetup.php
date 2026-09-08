<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Setup YouTube OAuth2 for yt-dlp.
 *
 * This runs the device code flow — you visit a URL, enter a code,
 * and yt-dlp stores a refresh token that auto-renews forever.
 *
 * Usage:
 *   php artisan youtube:oauth-setup
 */
class YouTubeOAuthSetup extends Command
{
    protected $signature = 'youtube:oauth-setup';
    protected $description = 'Setup YouTube OAuth2 for yt-dlp (one-time device code flow)';

    public function handle(): int
    {
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            $this->error('yt-dlp not found');
            return self::FAILURE;
        }

        $this->info('Starting YouTube OAuth2 setup...');
        $this->newLine();
        $this->info('This will generate a device code.');
        $this->info('You need to visit a URL and enter the code in your browser.');
        $this->newLine();

        // Run yt-dlp with oauth2 — it will print a URL and code, then wait
        $this->line("Running: {$ytdlp} --username oauth2 --password '' --list-formats 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'");
        $this->newLine();

        $this->info('┌─────────────────────────────────────────────────────┐');
        $this->info('│  Open this URL in your browser:                     │');
        $this->info('│  https://www.youtube.com/device                     │');
        $this->info('│                                                     │');
        $this->info('│  Enter the code shown by yt-dlp below.              │');
        $this->info('│  After approving, press Enter here to continue.     │');
        $this->info('└─────────────────────────────────────────────────────┘');
        $this->newLine();

        // Use proc_open to run interactively
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDOUT,
        ];

        $process = proc_open(
            $ytdlp . " --username oauth2 --password '' --list-formats 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'",
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $this->error('Failed to start yt-dlp');
            return self::FAILURE;
        }

        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            $this->newLine();
            $this->info('OAuth2 setup complete! Token saved to ~/.yt-dlp/oauth2.token');
            $this->info('yt-dlp will auto-refresh this token. No cookies needed anymore.');

            // Copy token to persistent storage
            $tokenPath = storage_path('app/youtube_oauth2.token');
            $homeToken = getenv('HOME') . '/.yt-dlp/oauth2.token';
            if (file_exists($homeToken)) {
                copy($homeToken, $tokenPath);
                $this->info("Token also saved to: {$tokenPath}");
            }

            return self::SUCCESS;
        }

        $this->error("OAuth2 setup failed (exit code: {$exitCode})");
        return self::FAILURE;
    }

    private function findYtdlp(): ?string
    {
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
            if (is_executable($p)) return $p;
        }
        $found = trim((string) shell_exec('which yt-dlp 2>/dev/null'));
        return $found !== '' ? $found : null;
    }
}
