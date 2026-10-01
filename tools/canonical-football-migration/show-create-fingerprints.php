<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tables = [
    'sports',
    'providers',
    'competitions',
    'competition_seasons',
    'participants',
    'events',
    'event_participants',
    'provider_competition_mappings',
    'provider_participant_mappings',
    'provider_event_mappings',
    'import_runs',
    'identity_quarantines',
];

foreach ($tables as $table) {
    $rows = DB::select(sprintf('SHOW CREATE TABLE `%s`', $table));
    $values = isset($rows[0]) ? array_values((array) $rows[0]) : [];

    if (! isset($values[1])) {
        fwrite(STDERR, "Missing SHOW CREATE TABLE output for $table\n");
        exit(1);
    }

    printf("%s\t%s\n", $table, hash('sha256', $values[1]));
}
