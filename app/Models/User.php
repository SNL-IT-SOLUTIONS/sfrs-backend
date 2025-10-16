<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * ✅ The attributes that are mass assignable.
     */
    protected $fillable = [
        'full_name',
        'email',
        'role',
        'password',
        'is_active',
        'is_archived',
        'is_approved'
    ];

    /**
     * 🫣 The attributes that should be hidden when serializing.
     */
    protected $hidden = [
        'password',
    ];
}
