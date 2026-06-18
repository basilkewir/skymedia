<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;

class GenerateSlate extends Command
{
    protected $signature   = 'channels:generate-slate {--channel= : Channel ID (omit for all)}';
    protected $description = 'Generate a "be back soon" slate MP4 for offline fallback';

    // Multilingual "be back soon" messages
    private const MESSAGES = [
        'We\'ll be right back',
        'Nous revenons bientôt',
        'Volveremos pronto',
        'Wir sind gleich zurück',
        'Torneremo presto',
        'Voltamos em breve',
        'Сейчас вернёмся',
        'すぐ戻ります',
        'نعود قريباً',
        '我们马上回来',
    ];

    public function handle(): int
    {
        $query = Channel::query();
        if ($id = $this->option('channel')) {
            $query->where('id', (int) $id);
        }

        $query->each(function (Channel $channel) {
            $this->generateSlate($channel);
        });

        return self::SUCCESS;
    }

    public function generateSlate(Channel $channel): bool
    {
        $dir = $channel->dvr_directory;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $output = $dir . '/slate.mp4';
        $font   = $this->resolveFont();

        // Build scrolling text: channel name + all languages, one per line, cycling every 3s
        $lines    = array_merge([$channel->name], self::MESSAGES);
        $duration = count($lines) * 3; // 3 seconds per message

        // drawtext filters — channel name at top, messages cycling in centre
        $channelNameFilter = "drawtext=fontfile={$font}"
            . ":text='" . $this->escapeText($channel->name) . "'"
            . ":fontsize=48:fontcolor=white:x=(w-text_w)/2:y=h*0.25"
            . ":shadowcolor=black:shadowx=2:shadowy=2";

        // Each message shown for 3s using enable= expression
        $msgFilters = [];
        foreach (self::MESSAGES as $i => $msg) {
            $start = ($i + 1) * 3; // offset by 3s (after channel name display)
            $end   = $start + 3;
            $msgFilters[] = "drawtext=fontfile={$font}"
                . ":text='" . $this->escapeText($msg) . "'"
                . ":fontsize=32:fontcolor=yellow:x=(w-text_w)/2:y=h*0.55"
                . ":shadowcolor=black:shadowx=1:shadowy=1"
                . ":enable='between(mod(t,{$duration}),{$start},{$end})'";
        }

        // Always show channel name
        $vf = implode(',', array_merge([$channelNameFilter], $msgFilters));

        $cmd = [
            config('skymedia.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-f',       'lavfi',
            '-i',       "color=c=0x1a1a2e:size=1280x720:rate=25",
            '-f',       'lavfi',
            '-i',       'aevalsrc=0:channel_layout=stereo:sample_rate=48000',
            '-vf',      $vf,
            '-t',       (string) $duration,
            '-c:v',     'libx264',
            '-preset',  'veryfast',
            '-crf',     '28',
            '-pix_fmt', 'yuv420p',
            '-c:a',     'aac',
            '-b:a',     '64k',
            '-movflags', '+faststart',
            '-stream_loop', '-1',
            $output,
        ];

        // Remove -stream_loop from generation command (it's for playback not encoding)
        $cmd = array_filter($cmd, fn($v, $k) => !($v === '-stream_loop' || (isset($cmd[$k-1]) && $cmd[$k-1] === '-stream_loop')), ARRAY_FILTER_USE_BOTH);
        $cmd = array_values($cmd);

        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $out, $code);

        if ($code !== 0) {
            $this->error("Slate generation failed for [{$channel->name}]: " . implode("\n", $out));
            return false;
        }

        $this->info("Slate generated for [{$channel->name}] → {$output}");
        return true;
    }

    private function resolveFont(): string
    {
        $candidates = [
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ];
        foreach ($candidates as $f) {
            if (file_exists($f)) return $f;
        }
        return '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    }

    private function escapeText(string $text): string
    {
        // ffmpeg drawtext escaping
        return str_replace(["'", ':', '\\'], ["\\'", '\\:', '\\\\'], $text);
    }
}
