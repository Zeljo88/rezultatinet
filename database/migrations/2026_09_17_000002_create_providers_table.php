<?php

use Illuminate\Database\Migrations\Migration;
require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('providers', '2026_09_17_000002_create_providers_table', <<<'SQL'
CREATE TABLE providers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sport_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vendor_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_namespace VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY providers_code_unique (code),
    UNIQUE KEY providers_sport_product_unique (sport_id, product_namespace),
    CONSTRAINT providers_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000002_create_providers_table'
SQL);
    }

    public function down(): void
    {
        CanonicalFootballMigration::drop('providers', '2026_09_17_000002_create_providers_table');
    }
};
