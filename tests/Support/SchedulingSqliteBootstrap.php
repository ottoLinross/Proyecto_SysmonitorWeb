<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

// Run PHPUnit with --bootstrap=tests/Support/SchedulingSqliteBootstrap.php.
// Prepare isolated SQLite using migrate, so existing RefreshDatabase tests never need a fresh migration.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

$schedulingTestApp = require dirname(__DIR__, 2).'/bootstrap/app.php';
$schedulingErrorReporting = error_reporting();
$schedulingTestKernel = $schedulingTestApp->make(Kernel::class);
try {
    $schedulingTestKernel->bootstrap();

    if (! $schedulingTestApp->environment('testing')
        || $schedulingTestApp['config']->get('database.default') !== 'sqlite'
        || $schedulingTestApp['config']->get('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Este bootstrap requiere testing y SQLite :memory:.');
    }

    // DB_URL can override the raw configuration; inspect the effective connection before any query.
    $schedulingTestConnection = $schedulingTestApp['db']->connection('sqlite');
    if ($schedulingTestConnection->getDriverName() !== 'sqlite' || $schedulingTestConnection->getDatabaseName() !== ':memory:') {
        throw new RuntimeException('La conexión efectiva debe ser SQLite :memory:.');
    }

    if ($schedulingTestKernel->call('migrate', ['--database' => 'sqlite', '--force' => true]) !== 0) {
        throw new RuntimeException('No se pudieron aplicar las migraciones en SQLite de testing.');
    }

    RefreshDatabaseState::$inMemoryConnections['sqlite'] = $schedulingTestConnection->getPdo();
    RefreshDatabaseState::$migrated = true;
} finally {
    // Restore PHPUnit's handlers even when the isolation guard rejects the configuration.
    HandleExceptions::flushState();
    error_reporting($schedulingErrorReporting);
}
