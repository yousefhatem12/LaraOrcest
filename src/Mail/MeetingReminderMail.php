<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MeetingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $title = $this->data['meeting_title'] ?? '项目会议';
        $subjects = [
            'zh' => "会议日程提醒：{$title}",
            'hi' => "बैठक अनुस्मारक: {$title}",
            'en' => "Meeting Reminder: {$title}",
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.meeting-reminder";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
