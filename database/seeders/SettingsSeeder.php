<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'ffmpeg_binary',          'value' => 'ffmpeg',   'type' => 'string',  'group' => 'system',  'label' => 'FFmpeg Binary Path'],
            ['key' => 'ffprobe_binary',         'value' => 'ffprobe',  'type' => 'string',  'group' => 'system',  'label' => 'FFprobe Binary Path'],
            ['key' => 'dvr_base_path',          'value' => '/var/skymedia/dvr', 'type' => 'string', 'group' => 'dvr', 'label' => 'DVR Base Storage Path'],
            ['key' => 'monitor_tick',           'value' => '3',        'type' => 'integer', 'group' => 'system',  'label' => 'Monitor Tick (seconds)'],
            ['key' => 'srt_latency',            'value' => '200',      'type' => 'integer', 'group' => 'stream',  'label' => 'SRT Latency (ms)'],
            ['key' => 'default_dvr_duration',   'value' => '3600',     'type' => 'integer', 'group' => 'dvr',     'label' => 'Default DVR Duration (sec)'],
            ['key' => 'default_segment_duration','value' => '4',       'type' => 'integer', 'group' => 'dvr',     'label' => 'Default Segment Duration (sec)'],
            ['key' => 'log_retention_days',     'value' => '30',       'type' => 'integer', 'group' => 'system',  'label' => 'Log Retention (days)'],
            ['key' => 'app_name',               'value' => 'SkyMedia', 'type' => 'string',  'group' => 'general', 'label' => 'Application Name'],
        ];

        foreach ($defaults as $s) {
            Setting::updateOrCreate(['key' => $s['key']], $s);
        }
    }
}
