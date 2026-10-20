<?php

namespace Tests\Support;

use Tests\TestCase;

abstract class StorageTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['storage_access.username' => 'storage-test', 'storage_access.password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->withBasicAuth('storage-test', 'test-only-password');
    }
}
