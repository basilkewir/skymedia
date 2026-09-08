<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Import YouTube cookies for yt-dlp.
 *
 * Usage:
 *   php artisan youtube:import-cookies
 *   php artisan youtube:import-cookies /path/to/cookies.txt
 */
class YouTubeOAuthSetup extends Command
{
    protected $signature = 'youtube:oauth-setup {cookieFile? : Path to Netscape cookie file}';
    protected $description = 'Import YouTube cookies for yt-dlp from a Netscape-format cookie file';

    public function handle(): int
    {
        $cookieFile = $this->argument('cookieFile');

        if ($cookieFile === null) {
            $this->info('YouTube Cookie Importer');
            $this->newLine();
            $this->info('To get cookies, run this on your LOCAL machine (not the server):');
            $this->newLine();
            $this->line('  1. Install yt-dlp on your local machine');
            $this->line('  2. Open Chrome/Firefox and log into YouTube');
            $this->line('  3. Run: yt-dlp --cookies-from-browser chrome --skip-download --print-traffic "" 2>&1 | head -1');
            $this->line('     This generates cookies.txt in the current directory');
            $this->line('  4. Or use a browser extension like "Get cookies.txt LOCALLY"');
            $this->line('     Export cookies for youtube.com in Netscape format');
            $this->newLine();
            $this->line('Then copy the file to this server and run:');
            $this->line('  php artisan youtube:oauth-setup /path/to/cookies.txt');
            $this->newLine();

            // Also try to fetch from local browser if running on desktop
            $ytdlp = $this->findYtdlp();
            if ($ytdlp !== null) {
                $this->info('Attempting to fetch cookies from local browser...');
                $this->newLine();

                foreach (['chrome', 'firefox', 'edge'] as $browser) {
                    $cmd = "{$ytdlp} --cookies-from-browser {$browser} --skip-download --print-traffic 'https://www.youtube.com' 2>&1 | head -5";
                    $output = shell_exec($cmd);
                    if (str_contains((string) $output, 'Cookie:')) {
                        $this->info("Found cookies from {$browser}!");
                        // Export the cookies
                        $exportCmd = "{$ytdlp} --cookies-from-browser {$browser} --cookies /tmp/youtube_imported.txt --skip-download 'https://www.youtube.com' 2>&1";
                        shell_exec($exportCmd);
                        if (file_exists('/tmp/youtube_imported.txt')) {
                            $cookieFile = '/tmp/youtube_imported.txt';
                            break;
                        }
                    }
                }
            }

            if ($cookieFile === null) {
                $this->error('No cookie file provided and could not auto-detect browser cookies.');
                $this->error('Please export cookies from your browser and provide the file path.');
                return self::FAILURE;
            }
        }

        if (!file_exists($cookieFile)) {
            $this->error("File not found: {$cookieFile}");
            return self::FAILURE;
        }

        $content = file_get_contents($cookieFile);
        if ($content === false || strlen($content) < 50) {
            $this->error('Cookie file is empty or too small');
            return self::FAILURE;
        }

        // Validate it looks like a Netscape cookie file
        if (!str_contains($content, 'youtube.com') && !str_contains($content, '.google.com')) {
            $this->error('This does not look like a YouTube cookie file. Expected youtube.com or .google.com domains.');
            return self::FAILURE;
        }

        // Save to persistent storage
        $dest = storage_path('app/youtube_cookies_auth.txt');
        file_put_contents($dest, $content);

        $this->newLine();
        $this->info("Cookies saved to: {$dest}");
        $this->info('Size: ' . number_format(strlen($content)) . ' bytes');

        // Count key cookies
        $keyCookies = ['__Secure-1PSID', '__Secure-3PSID', '__Secure-1PSIDTS', 'SID', 'HSID', 'SSID', 'APISID', 'SAPISID'];
        $found = [];
        foreach ($keyCookies as $name) {
            if (str_contains($content, $name)) {
                $found[] = $name;
            }
        }
        $this->info('Key cookies found: ' . implode(', ', $found));

        if (empty($found)) {
            $this->warn('Warning: No key YouTube auth cookies found. Downloads may fail.');
        }

        $this->newLine();
        $this->info('Done! YouTube downloads will now use these cookies.');
        $this->info('Re-export new cookies when they expire (typically every few weeks).');

        return self::SUCCESS;
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
