<?php

namespace App\Contracts;

interface ApiFootballQuotaStore
{
    public function reserve(string $endpointClass, string $caller): array;

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void;

    public function openCircuit(int $until, string $reason): void;

    public function status(): array;

    public function acquireRepair(int $fixtureId, string $caller): bool;

    public function releaseRepair(int $fixtureId): void;

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void;

    /**
     * @return array{schema: int, generation: int, cursor: int, ceiling: int}|null
     */
    public function repairScanState(string $scan): ?array;

    /**
     * @param  array{schema: int, generation: int, cursor: int, ceiling: int}|null  $expected
     * @param  array{schema: int, generation: int, cursor: int, ceiling: int}  $next
     */
    public function compareAndSetRepairScanState(string $scan, ?array $expected, array $next): bool;
}
