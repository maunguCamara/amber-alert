<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'phone',
        'full_name',
        'password',
        'role',
        'county',
        'api_token',
        'refresh_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function isOfficer(): bool
    {
        return in_array($this->role, ['officer', 'admin', 'superadmin'], true);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin'], true);
    }

    public function hasApiToken(): bool
    {
        return filled($this->api_token);
    }
}