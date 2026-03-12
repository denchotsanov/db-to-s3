<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\MigrationResult;
use App\DTOs\UploadResult;
use App\Services\Migration\MigrationOrchestrator;
use App\Contracts\MigrationStrategyContract;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MigrationOrchestratorTest extends TestCase
{
    private MigrationOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new MigrationOrchestrator(new NullLogger());
    }

    public function test_run_returns_empty_array_when_no_strategies_registered(): void
    {
        $results = $this->orchestrator->run();

        $this->assertSame([], $results);
    }

    public function test_run_returns_result_keyed_by_strategy_label(): void
    {
        $expected = new MigrationResult(10, 9, 1, 0, 1.5);

        $strategy = $this->createMock(MigrationStrategyContract::class);
        $strategy->method('label')->willReturn('Test Strategy');
        $strategy->method('migrate')->willReturn($expected);

        $this->orchestrator->addStrategy($strategy);

        $results = $this->orchestrator->run();

        $this->assertArrayHasKey('Test Strategy', $results);
        $this->assertSame($expected, $results['Test Strategy']);
    }

    public function test_run_passes_options_to_all_strategies(): void
    {
        $options = ['chunk_size' => 100, 'dry_run' => true];

        $strategyA = $this->createMock(MigrationStrategyContract::class);
        $strategyA->method('label')->willReturn('A');
        $strategyA->expects($this->once())->method('migrate')->with($options)
            ->willReturn(new MigrationResult(1, 1, 0, 0, 0.1));

        $strategyB = $this->createMock(MigrationStrategyContract::class);
        $strategyB->method('label')->willReturn('B');
        $strategyB->expects($this->once())->method('migrate')->with($options)
            ->willReturn(new MigrationResult(1, 1, 0, 0, 0.1));

        $this->orchestrator->addStrategy($strategyA);
        $this->orchestrator->addStrategy($strategyB);
        $this->orchestrator->run($options);
    }

    public function test_run_re_throws_exception_from_strategy(): void
    {
        $strategy = $this->createMock(MigrationStrategyContract::class);
        $strategy->method('label')->willReturn('Failing');
        $strategy->method('migrate')->willThrowException(new \RuntimeException('boom'));

        $this->orchestrator->addStrategy($strategy);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->orchestrator->run();
    }

    public function test_add_strategy_is_fluent(): void
    {
        $strategy = $this->createMock(MigrationStrategyContract::class);
        $strategy->method('label')->willReturn('X');
        $strategy->method('migrate')->willReturn(new MigrationResult(0, 0, 0, 0, 0.0));

        $return = $this->orchestrator->addStrategy($strategy);

        $this->assertSame($this->orchestrator, $return);
    }

    public function test_run_executes_multiple_strategies_in_order(): void
    {
        $callOrder = [];

        $strategyA = $this->createMock(MigrationStrategyContract::class);
        $strategyA->method('label')->willReturn('First');
        $strategyA->method('migrate')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'First';
            return new MigrationResult(1, 1, 0, 0, 0.1);
        });

        $strategyB = $this->createMock(MigrationStrategyContract::class);
        $strategyB->method('label')->willReturn('Second');
        $strategyB->method('migrate')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'Second';
            return new MigrationResult(1, 1, 0, 0, 0.1);
        });

        $this->orchestrator->addStrategy($strategyA);
        $this->orchestrator->addStrategy($strategyB);
        $this->orchestrator->run();

        $this->assertSame(['First', 'Second'], $callOrder);
    }
}

