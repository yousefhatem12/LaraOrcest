<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $subjects = [
            'zh' => '验证您的电子邮件地址',
            'hi' => 'अपना ईमेल पता सत्यापित करें',
            'en' => 'Verify Your Email Address',
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.verify-account";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
