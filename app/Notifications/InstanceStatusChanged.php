<?php

namespace App\Notifications;

use App\Models\Instance;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstanceStatusChanged extends Notification
{
    public function __construct(public Instance $instance, public bool $down, public ?string $error = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $i = $this->instance;
        $m = (new MailMessage)
            ->subject(($this->down ? '[DOWN] ' : '[UP] ').$i->name.' ('.$i->environment.')')
            ->greeting($this->down ? $i->name.' is not responding' : $i->name.' is back up')
            ->line('URL: '.$i->url);
        if ($this->down) {
            $m->error()->line('Failed checks in a row: '.$i->consecutive_failures)->line('Last error: '.($this->error ?? 'unknown'));
        } else {
            $m->success()->line('Version: '.($i->version ?? 'unknown'));
        }

        return $m->action('Open instance manager', url('/instances/'.$i->id));
    }
}
