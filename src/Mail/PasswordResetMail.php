<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $subjects = [
            'zh' => '重置您的账户密码',
            'hi' => 'अपना पासवर्ड रीसेट करें',
            'en' => 'Reset Your Password',
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.password-reset";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
