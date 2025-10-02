<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Attendance
 * Représente la présence d'un employé pour une date donnée (arrivée/départ).
 */
class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employe_id',
        'date',
        'check_in_at',
        'check_in_signature',
        'check_out_at',
        'check_out_signature',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function employe()
    {
        return $this->belongsTo(Employe::class);
    }
}
