<?php

namespace App\Support;

enum ApiFootballBlockReason: string
{
    case SportDisabled = 'sport_disabled';
    case FixtureRepairBudget = 'fixture_repair_budget';
    case GlobalQuota = 'global_quota';
    case Circuit = 'circuit';
    case AccountingUnavailable = 'accounting_unavailable';
    case ProviderRateLimited = 'provider_rate_limited';
    case OtherQuota = 'other_quota';
}
