<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

class OpenAiDriver extends OpenAiCompatibleHttpDriver
{
    public function getName(): string
    {
        return 'openai';
    }
}
