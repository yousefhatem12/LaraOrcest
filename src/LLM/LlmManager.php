<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM;

use InvalidArgumentException;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\LLM\Drivers\AnthropicDriver;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\LLM\Drivers\GeminiDriver;
use Yousef\LaraOrcest\LLM\Drivers\OllamaDriver;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiDriver;
use Yousef\LaraOrcest\Support\Config;

class LlmManager
{
    /**
     * @var array<string, LlmDriverContract>
     */
    protected array $drivers = [];

    protected ?string $defaultDriver = null;

    public function __construct(?string $defaultDriver = null)
    {
        $this->defaultDriver = $defaultDriver;
    }

    public function driver(?string $name = null): LlmDriverContract
    {
        $name = $name ?: $this->getDefaultDriver();

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        return $this->drivers[$name] = $this->createDriver($name);
    }

    public function extend(string $name, LlmDriverContract $driver): self
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    public function getDefaultDriver(): string
    {
        if ($this->defaultDriver !== null) {
            return $this->defaultDriver;
        }

        return (string) Config::get('lara-orcest.llm.default', 'openai_compatible');
    }

    public function setDefaultDriver(string $name): self
    {
        $this->defaultDriver = $name;

        return $this;
    }

    protected function createDriver(string $name): LlmDriverContract
    {
        return match (strtolower($name)) {
            'fake' => new FakeDriver(),
            'openai_compatible', 'openai-compatible', 'custom' => new OpenAiCompatibleHttpDriver(),
            'openai' => new OpenAiDriver(),
            'groq' => new OpenAiCompatibleHttpDriver(
                baseUrl: (string) Config::get('lara-orcest.llm.providers.groq.base_url', 'https://api.groq.com/openai/v1'),
                apiKey: (string) (Config::get('lara-orcest.llm.providers.groq.api_key') ?: Config::get('lara-orcest.llm.drivers.openai_compatible.api_key', '')),
                model: (string) Config::get('lara-orcest.llm.providers.groq.model', 'llama-3.3-70b-versatile'),
            ),
            'openrouter' => new OpenAiCompatibleHttpDriver(
                baseUrl: (string) Config::get('lara-orcest.llm.providers.openrouter.base_url', 'https://openrouter.ai/api/v1'),
                apiKey: (string) (Config::get('lara-orcest.llm.providers.openrouter.api_key') ?: Config::get('lara-orcest.llm.drivers.openai_compatible.api_key', '')),
                model: (string) Config::get('lara-orcest.llm.providers.openrouter.model', 'openai/gpt-4o-mini'),
            ),
            'anthropic' => new AnthropicDriver(),
            'gemini' => new GeminiDriver(),
            'ollama' => new OllamaDriver(),
            default => throw new InvalidArgumentException("Unsupported LLM driver: [{$name}]"),
        };
    }
}
