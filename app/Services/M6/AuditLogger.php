<?php

namespace App\Services\M6;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use LogicException;

class AuditLogger
{
    private const METADATA_KEYS = ['process_id', 'signal', 'nice', 'algorithm', 'mode', 'process_count', 'quantum'];

    /** @var \WeakMap<AuditLog, string> */
    private \WeakMap $selfRoleChangeContinuations;

    public function __construct()
    {
        $this->selfRoleChangeContinuations = new \WeakMap;
    }

    public function begin(
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?int $pid = null,
        array $metadata = [],
    ): AuditLog {
        $actor = $this->authorizedActor();
        $this->validateIdentifier($action, 'action', false, 80);
        $this->validateIdentifier($targetType, 'target type', true, 80);

        if ($targetId !== null && (! is_scalar($targetId) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', (string) $targetId))) {
            throw new InvalidArgumentException('Invalid audit target identifier.');
        }
        if ($pid !== null && $pid < 1) {
            throw new InvalidArgumentException('Invalid process identifier.');
        }

        $ipAddress = Request::ip();
        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $ipAddress = null;
        }

        $entry = new AuditLog;
        $entry->user_id = $actor->getKey();
        $entry->action = $action;
        $entry->target_type = $targetType;
        $entry->target_id = $targetId === null ? null : (string) $targetId;
        $entry->pid = $pid;
        $entry->status = AuditLog::STATUS_PENDING;
        $entry->metadata = $this->sanitizeMetadata($metadata);
        $entry->ip_address = $ipAddress;
        $entry->save();

        if ($action === 'user.role' && $targetType === 'user'
            && (string) $targetId === (string) $actor->getKey()) {
            $this->selfRoleChangeContinuations[$entry] = (string) $actor->getKey();
        }

        return $entry;
    }

    public function finish(
        AuditLog $audit,
        string $status,
        ?string $result = null,
        ?string $targetId = null,
        ?int $pid = null,
    ): AuditLog {
        if (! in_array($status, [AuditLog::STATUS_SUCCESS, AuditLog::STATUS_FAILURE], true)) {
            throw new InvalidArgumentException('Audit records can only finish as success or failure.');
        }
        if ($result !== null && ! preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $result)) {
            throw new InvalidArgumentException('Invalid audit result code.');
        }
        if ($targetId !== null && ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $targetId)) {
            throw new InvalidArgumentException('Invalid audit target identifier.');
        }
        if ($pid !== null && $pid < 1) {
            throw new InvalidArgumentException('Invalid process identifier.');
        }

        $stored = AuditLog::query()->find($audit->getKey());
        if (! $stored) {
            throw new AuthorizationException('The audit record is not available to this administrator.');
        }

        $actor = $this->authorizedActor($audit, $stored);
        if ((string) $stored->user_id !== (string) $actor->getKey()) {
            throw new AuthorizationException('The audit record is not available to this administrator.');
        }

        $changes = ['status' => $status, 'result' => $result, 'updated_at' => now()];
        if ($targetId !== null) {
            $changes['target_id'] = $targetId;
        }
        if ($pid !== null) {
            $changes['pid'] = $pid;
        }

        $updated = AuditLog::query()
            ->whereKey($stored->getKey())
            ->where('status', AuditLog::STATUS_PENDING)
            ->update($changes);

        if ($updated !== 1) {
            throw new LogicException('Audit record is no longer pending or could not be finalized.');
        }

        if (isset($this->selfRoleChangeContinuations[$audit])) {
            unset($this->selfRoleChangeContinuations[$audit]);
        }

        return $stored->refresh();
    }

    /** @return array<string, int|string|bool> */
    private function sanitizeMetadata(array $metadata): array
    {
        $safe = [];
        foreach (self::METADATA_KEYS as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if (is_int($value) || is_bool($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 64 && preg_match('/^[A-Za-z0-9_.:-]*$/D', $value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function authorizedActor(?AuditLog $audit = null, ?AuditLog $storedAudit = null): User
    {
        $authenticated = Auth::guard('web')->user();
        if (! $authenticated instanceof User || ! $authenticated->exists) {
            throw new AuthorizationException('A persisted administrator is required to write audit records.');
        }

        $actor = User::query()->find($authenticated->getKey());
        if (! $actor instanceof User) {
            throw new AuthorizationException('A persisted administrator is required to write audit records.');
        }

        if ($actor->isAdmin()) {
            return $actor;
        }

        if ($audit !== null && $storedAudit !== null && $this->canContinueSelfRoleChange($audit, $storedAudit, $actor)) {
            return $actor;
        }

        throw new AuthorizationException('A persisted administrator is required to write audit records.');
    }

    private function canContinueSelfRoleChange(AuditLog $audit, AuditLog $storedAudit, User $actor): bool
    {
        if (! isset($this->selfRoleChangeContinuations[$audit]) || ! $actor->isObserver()) {
            return false;
        }

        $authorizedActorId = $this->selfRoleChangeContinuations[$audit];

        return $authorizedActorId === (string) $actor->getKey()
            && (string) $audit->getKey() === (string) $storedAudit->getKey()
            && (string) $storedAudit->user_id === (string) $actor->getKey()
            && $storedAudit->action === 'user.role'
            && $storedAudit->target_type === 'user'
            && (string) $storedAudit->target_id === (string) $actor->getKey()
            && $storedAudit->status === AuditLog::STATUS_PENDING;

    }

    private function validateIdentifier(?string $value, string $label, bool $nullable, int $maximum): void
    {
        if ($value === null && $nullable) {
            return;
        }
        if ($value === null || strlen($value) > $maximum || ! preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $value)) {
            throw new InvalidArgumentException("Invalid audit {$label}.");
        }
    }
}
