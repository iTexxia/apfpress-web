<?php

namespace App\Notifications;

use App\Models\ContactInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ContactInquiryNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly ContactInquiry $inquiry) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('APF Press website inquiry — '.Str::squish($this->inquiry->subject))
            ->replyTo($this->inquiry->email, $this->inquiry->name)
            ->greeting('New APF Press website inquiry')
            ->line('From: '.$this->inquiry->name.' <'.$this->inquiry->email.'>')
            ->line('Subject: '.$this->inquiry->subject)
            ->line($this->inquiry->message)
            ->line('Reply to this email to respond directly to '.$this->inquiry->name.'.');
    }
}
