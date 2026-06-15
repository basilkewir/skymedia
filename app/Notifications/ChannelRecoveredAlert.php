<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChannelRecoveredAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly Channel $channel) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[SkyMedia] ✅ {$this->channel->name} is back online")
            ->greeting('Channel Recovered')
            ->line("The channel **{$this->channel->name}** has recovered and is now streaming normally.")
            ->line('Live push output has been restored.')
            ->action('View Channel', route('channels.show', $this->channel))
            ->line('No further action required.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'channel_id'   => $this->channel->id,
            'channel_name' => $this->channel->name,
            'status'       => 'recovered',
        ];
    }
}
