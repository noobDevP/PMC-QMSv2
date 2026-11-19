<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Division extends Model
{
    protected $fillable = ['name', 'prefix', 'tv_id', 'teller_id'];

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function purposes()
    {
        return $this->hasMany(Purpose::class);
    }
}
