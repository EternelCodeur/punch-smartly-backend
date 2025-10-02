<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TemporaryDeparture extends Model
{
    use HasFactory;

    protected $fillable = [
        'employe_id',
        'date',
        'departure_time',
        'reason',
        'return_time',
        'return_signature',
        'return_signature_file_url',
    ];

    public function employe()
    {
        return $this->belongsTo(Employe::class);
    }
}
