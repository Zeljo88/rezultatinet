<?php

namespace App\Contracts;

interface ApiFootballQuotaStore
{
    /**
     * Canonical wire state is the JSON object
     * {schema,generation,cursor,ceiling}, with exactly those fields. Every value
     * is a finite, mathematically integral JSON number in IEEE-754's safe
     * integer range; schema is fixed, generation starts at one, and
     * 0 <= cursor <= ceiling. Readers accept semantically equivalent key order,
     * whitespace, decimal, or exponent notation and writers emit canonical
     * integer JSON. The shared exact range prevents Redis Lua from comparing
     * distinct generations or cursors as the same number.
     */
    public const REPAIR_SCAN_STATE_SCHEMA = 3;

    public const REPAIR_SCAN_STATE_MAX_INTEGER = 9007199254740991;

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
