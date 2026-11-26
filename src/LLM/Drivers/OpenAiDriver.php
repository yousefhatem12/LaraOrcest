<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use Yousef\LaraOrcest\Support\Config;

class OpenAiDriver extends OpenAiCompatibleHttpDriver
{
    public function __construct(
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?string $model = null,
        int $timeout = 60,
        int $retries = 2,
        int $retrySleep = 100
    ) {
        parent::__construct(
            baseUrl: $baseUrl ?: (string) Config::get('lara-orcest.llm.providers.openai.base_url', 'https://api.openai.com/v1'),
            apiKey: $apiKey !== null ? $apiKey : (string) (Config::get('lara-orcest.llm.providers.openai.api_key') ?: Config::get('lara-orcest.llm.drivers.openai_compatible.api_key', '')),
            model: $model ?: (string) (Config::get('lara-orcest.llm.providers.openai.model') ?: Config::get('lara-orcest.llm.drivers.openai_compatible.model', 'gpt-4o')),
            timeout: $timeout,
            retries: $retries,
            retrySleep: $retrySleep,
        );
    }

    public function getName(): string
    {
        return 'openai';
    }
}
