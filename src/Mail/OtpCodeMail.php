<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $subjects = [
            'zh' => '您的一次性安全验证码 (OTP)',
            'hi' => 'आपका सुरक्षा वन-टाइम पासवर्ड (OTP)',
            'en' => 'Your Security One-Time Password (OTP)',
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.otp-code";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
