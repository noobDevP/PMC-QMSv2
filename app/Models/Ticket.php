<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_number',
        'customer_type',
        'customer_name',
        'additional_info',
        'division_id',
        'purpose_id',
        'status',
        'served_at',
        'completed_at',
        'teller_id',
        'served_by',
    ];

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function purpose()
    {
        return $this->belongsTo(Purpose::class);
    }
}
