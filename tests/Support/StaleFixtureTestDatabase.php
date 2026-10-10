<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StaleFixtureTestDatabase
{
    public static function configureMariaDb(): void
    {
        $socket = getenv('STALE_TEST_SOCKET') ?: '';
        $database = getenv('STALE_TEST_DATABASE') ?: '';
        if (! app()->environment('testing') || getenv('STALE_TEST_ALLOW_FIXTURES') !== '1'
            || ! preg_match('/^stale_reconciliation_test_[a-z0-9_]+$/D', $database)
            || ! str_starts_with($socket, sys_get_temp_dir().'/stale-reconciliation-')
            || ! is_dir(dirname($socket)) || is_link($socket)) {
            throw new RuntimeException('Disposable local MariaDB test authority/socket/database guard failed.');
        }
        // Dedicated local socket-authenticated runner only. No production config,
        // secret env, TCP endpoint, or inherited database URL is used.
        config()->set('database.connections.stale_test', [
            'driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost',
            'database' => $database, 'username' => get_current_user(), 'password' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        config()->set('database.default', 'stale_test');
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        if (! str_starts_with($version, '10.11.') || ! str_contains($version, 'MariaDB')) {
            throw new RuntimeException('MariaDB 10.11 required.');
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
    }
}
