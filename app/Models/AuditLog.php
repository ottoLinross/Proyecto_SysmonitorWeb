<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class AuditLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SUCCESS, self::STATUS_FAILURE];

    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    /** @return Attribute<string, string> */
    protected function status(): Attribute
    {
        return Attribute::make(set: static function (mixed $value): string {
            if (! is_string($value) || ! in_array($value, self::STATUSES, true)) {
                throw new InvalidArgumentException('Invalid audit status.');
            }

            return $value;
        });
    }
}
