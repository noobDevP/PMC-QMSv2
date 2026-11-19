<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purpose extends Model
{
    protected $fillable = ['name', 'division_id'];

    public function division()
    {
        return $this->belongsTo(Division::class);
    }
}
