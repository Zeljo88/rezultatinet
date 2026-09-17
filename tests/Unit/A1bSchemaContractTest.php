<?php

namespace Tests\Unit;

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureScore;
use PHPUnit\Framework\TestCase;

class A1bSchemaContractTest extends TestCase
{
    public function test_elapsed_extra_is_mass_assignable_for_fixture_and_event(): void
    {
        $this->assertContains('elapsed_extra', (new Fixture)->getFillable());
        $this->assertContains('elapsed_extra', (new FixtureEvent)->getFillable());
    }

    public function test_all_score_fields_use_nullable_integer_domain_contract(): void
    {
        $score = new FixtureScore;
        $fields = [
            'goals_home', 'goals_away', 'home_halftime', 'away_halftime',
            'home_fulltime', 'away_fulltime', 'home_extratime', 'away_extratime',
            'home_penalties', 'away_penalties',
        ];

        foreach ($fields as $field) {
            $this->assertContains($field, $score->getFillable());
            $this->assertSame('integer', $score->getCasts()[$field] ?? null);
        }
    }

    public function test_baseline_contains_critical_production_contracts_and_skips_destructive_replay(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql');

        foreach ([
            'CREATE TABLE `players`', 'CREATE TABLE `player_stats`',
            'CREATE TABLE `posts`', 'CREATE TABLE `standings`',
            '`slug` varchar(255)', '`goals_home` int(11) NULL DEFAULT NULL',
            '`goals_away` int(11) NULL DEFAULT NULL', '`elapsed_extra` int(11) NULL DEFAULT NULL',
            'KEY `idx_kickoff_status` (`kick_off`, `status_short`)',
            'UNIQUE KEY `predictions_fixture_id_ip_unique` (`fixture_id`, `ip`)',
            "'2026_03_19_084743_fix_player_stats_unique_index'",
        ] as $required) {
            $this->assertStringContainsString($required, $sql);
        }

        $this->assertStringNotContainsString('DELETE FROM player_stats', $sql);
    }
}
