<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChannelOfflineAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Channel $channel,
        public readonly string  $reason,
        public readonly bool    $hasFallback,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->hasFallback
            ? "[SkyMedia] ⚠ {$this->channel->name} is offline — fallback active"
            : "[SkyMedia] 🔴 {$this->channel->name} is offline — no fallback";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Channel Offline Alert")
            ->line("The channel **{$this->channel->name}** has lost its source stream.")
            ->line("Reason: {$this->reason}")
            ->line('Current status: ' . strtoupper($this->channel->stream_status))
            ->line('Fallback available: ' . ($this->hasFallback ? 'Yes — output continuing via VOD loop' : 'No — push output is stopped'))
            ->action('View Channel', route('channels.show', $this->channel))
            ->line('The system will automatically restore the live stream when the source becomes available again.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'channel_id'   => $this->channel->id,
            'channel_name' => $this->channel->name,
            'status'       => $this->channel->stream_status,
            'has_fallback' => $this->hasFallback,
            'reason'       => $this->reason,
        ];
    }
}
