<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdMedia extends Model
{
    protected $fillable = ['filename', 'file_type', 'duration'];
}
