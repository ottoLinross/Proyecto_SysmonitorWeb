<?php

namespace Tests\Support;

trait CreatesSchedulingStorage
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each application uses its own SQLite :memory: connection. Never refresh/drop tables.
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', [
            '--database' => 'sqlite',
            '--path' => 'database/migrations/2026_10_15_000000_create_scheduling_runs_table.php',
            '--force' => true,
        ])->assertExitCode(0);
    }
}
