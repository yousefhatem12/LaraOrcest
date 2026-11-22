<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MeetingNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $title = $this->data['meeting_title'] ?? '日程通知';
        $subjects = [
            'zh' => "新会议邀请：{$title}",
            'hi' => "नई बैठक आमंत्रण: {$title}",
            'en' => "New Meeting Invitation: {$title}",
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.meeting-notification";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
