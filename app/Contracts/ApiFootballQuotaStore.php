<?php

namespace App\Contracts;

interface ApiFootballQuotaStore
{
    public function reserve(string $endpointClass, string $caller): array;

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void;

    public function openCircuit(int $until, string $reason): void;

    public function status(): array;

    public function acquireRepair(int $fixtureId, string $caller): bool;

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void;
}
