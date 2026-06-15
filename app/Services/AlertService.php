<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use App\Notifications\ChannelOfflineAlert;
use App\Notifications\ChannelRecoveredAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AlertService
{
    /** Send offline / recovered alerts to all admin users and configured webhooks */
    public function sendOfflineAlert(Channel $channel, string $reason, bool $hasFallback): void
    {
        $admins = User::all();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ChannelOfflineAlert($channel, $reason, $hasFallback));
        }

        $this->fireWebhook('channel.offline', [
            'channel_id'   => $channel->id,
            'channel_name' => $channel->name,
            'stream_status' => $channel->stream_status,
            'reason'        => $reason,
            'has_fallback'  => $hasFallback,
            'timestamp'     => now()->toISOString(),
        ]);

        Log::channel('monitor')->warning(
            "[Alert] {$channel->name} offline — reason: {$reason} — fallback: " . ($hasFallback ? 'yes' : 'no')
        );
    }

    public function sendRecoveryAlert(Channel $channel): void
    {
        $admins = User::all();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ChannelRecoveredAlert($channel));
        }

        $this->fireWebhook('channel.recovered', [
            'channel_id'   => $channel->id,
            'channel_name' => $channel->name,
            'stream_status' => $channel->stream_status,
            'timestamp'     => now()->toISOString(),
        ]);

        Log::channel('monitor')->info("[Alert] {$channel->name} recovered");
    }

    public function sendErrorAlert(Channel $channel, string $error): void
    {
        $admins = User::all();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ChannelOfflineAlert($channel, "Error: {$error}", false));
        }

        $this->fireWebhook('channel.error', [
            'channel_id'   => $channel->id,
            'channel_name' => $channel->name,
            'error'         => $error,
            'timestamp'     => now()->toISOString(),
        ]);

        Log::channel('monitor')->error("[Alert] {$channel->name} error: {$error}");
    }

    private function fireWebhook(string $event, array $payload): void
    {
        $url = config('skymedia.alert_webhook_url');
        if (empty($url)) return;

        try {
            Http::timeout(5)->post($url, [
                'event'   => $event,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::debug("[Alert] Webhook failed: {$e->getMessage()}");
        }
    }
}
