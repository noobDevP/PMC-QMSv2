<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'password_hash',
        'role',
        'division_id',
        'additional_info',
        'teller_id',
    ];

    protected $hidden = [
        'password_hash',
        'password',
        'remember_token',
    ];

    public function division()
    {
        return $this->belongsTo(Division::class);
    }
}
