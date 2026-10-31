<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;

class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_OBSERVER = 'observer';

    /** @var array<string, mixed> */
    protected $attributes = ['role' => self::ROLE_OBSERVER];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isObserver(): bool
    {
        return $this->role === self::ROLE_OBSERVER;
    }

    /**
     * Explicit assignment is reserved for trusted administrative code.
     *
     * @return Attribute<string, mixed>
     */
    protected function role(): Attribute
    {
        return Attribute::make(set: function (mixed $value): string {
            if (! in_array($value, [self::ROLE_ADMIN, self::ROLE_OBSERVER], true)) {
                throw new InvalidArgumentException('Invalid user role.');
            }

            return $value;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
