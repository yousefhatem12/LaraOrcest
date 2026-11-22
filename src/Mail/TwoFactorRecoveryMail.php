<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $subjects = [
            'zh' => '双重认证 (2FA) 备用恢复代码使用提醒',
            'hi' => 'दो-चरणीय प्रमाणीकरण (2FA) पुनर्प्राप्ति कोड सूचना',
            'en' => 'Two-Factor Authentication Recovery Code Notice',
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.two-factor-recovery";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
