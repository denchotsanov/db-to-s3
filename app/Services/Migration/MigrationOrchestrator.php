<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Contracts\MigrationStrategyContract;
use App\DTOs\MigrationResult;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one or more MigrationStrategy instances.
 *
 * This is the central "Command Bus"-style coordinator.
 * The command stays lean: it builds options and hands them to this class.
 *
 * Open/Closed: new migration types are added by registering a new strategy,
 * without touching this class or the command.
 */
final class MigrationOrchestrator
{
    /** @var MigrationStrategyContract[] */
    private array $strategies = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Register a strategy to be run by this orchestrator.
     */
    public function addStrategy(MigrationStrategyContract $strategy): self
    {
        $this->strategies[] = $strategy;

        return $this;
    }

    /**
     * Run all registered strategies and return each result keyed by label.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, MigrationResult>
     */
    public function run(array $options = []): array
    {
        $this->logger->info('MigrationOrchestrator: starting migration run.', [
            'strategies' => array_map(fn ($s) => $s->label(), $this->strategies),
            'options'    => $options,
        ]);

        $results = [];
        $strategiesToRun    = $this->strategies;
        $this->strategies   = [];   // reset so re-running handle() starts clean

        foreach ($strategiesToRun as $strategy) {
            $this->logger->info("MigrationOrchestrator: running strategy [{$strategy->label()}].");

            try {

                $results[$strategy->label()] = $strategy->migrate($options);
            } catch (\Throwable $e) {
                $this->logger->critical("MigrationOrchestrator: strategy [{$strategy->label()}] threw an unrecoverable exception.", [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);

                // Re-throw so the command can catch it and exit with a non-zero code.
                throw $e;
            }
        }

        $this->logger->info('MigrationOrchestrator: all strategies completed.');

        return $results;
    }
}

