<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data = [],
        public $locale = 'zh'
    ) {}

    public function build(): self
    {
        $appName = config('app.name', 'LaraOrcest');
        $subjects = [
            'zh' => "欢迎加入 {$appName}！",
            'hi' => "{$appName} में आपका स्वागत है!",
            'en' => "Welcome to {$appName}!",
        ];

        $subject = $subjects[$this->locale] ?? $subjects['en'];
        $view = "emails.{$this->locale}.welcome";

        return $this->subject($subject)
            ->view($view, $this->data);
    }
}
