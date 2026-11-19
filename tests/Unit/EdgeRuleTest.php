<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Graph\EdgeRule;
use Yousef\LaraOrcest\State\RunState;

class EdgeRuleTest extends TestCase
{
    public function test_always_factory_creates_unconditional_rule(): void
    {
        $rule = EdgeRule::always('node_a', 'node_b', priority: 15);

        $this->assertSame('node_a', $rule->fromNode);
        $this->assertSame('node_b', $rule->toNode);
        $this->assertSame(15, $rule->priority);

        $state = RunState::initial('run_1', 'Prompt');
        $this->assertTrue($rule->matches($state));
    }

    public function test_when_factory_evaluates_condition(): void
    {
        $rule = EdgeRule::when(
            'qa_agent',
            'reviewer_agent',
            fn (RunState $s) => $s->areTestsPassing(),
            priority: 20
        );

        $failingState = RunState::initial('run_2', 'Prompt');
        $this->assertFalse($rule->matches($failingState));

        $passingState = $failingState->recordTestResult(true, 0, 'Tests: 1 passed', 'OK');
        $this->assertTrue($rule->matches($passingState));
    }

    public function test_describe_returns_string(): void
    {
        $rule = EdgeRule::always('start', 'finish', 10);
        $this->assertSame('[start -> finish (priority: 10)]', $rule->describe());
    }
}
