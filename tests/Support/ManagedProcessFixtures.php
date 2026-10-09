<?php

namespace Tests\Support;

use App\Models\ManagedProcess;
use App\Services\System\ApplicationProcessUid;
use App\Services\System\ManagedProcessIdentityGuard;
use App\Services\System\ManagedProcessProvenance;
use App\Services\System\TestProcessIdentityReader;

trait ManagedProcessFixtures
{
    private function fixtureProvenance(): ManagedProcessProvenance
    {
        return new ManagedProcessProvenance('synthetic-unit-test-provenance-key');
    }

    private function fixtureGuard(TestProcessIdentityReader $reader, ?int $uid = 1000): ManagedProcessIdentityGuard
    {
        $context = new class($uid) extends ApplicationProcessUid
        {
            public function __construct(private readonly ?int $uid) {}

            public function effectiveUid(): ?int
            {
                return $this->uid;
            }
        };

        return new ManagedProcessIdentityGuard($reader, $context, $this->fixtureProvenance());
    }

    private function sealFixture(ManagedProcess $process): ManagedProcess
    {
        $process->forceFill(['registration_signature' => $this->fixtureProvenance()->seal($process)]);

        return $process;
    }
}
