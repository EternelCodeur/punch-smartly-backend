<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Absence
 * Représente une absence pour un employé à une date donnée.
 */
class Absence extends Model
{
    use HasFactory;

    protected $fillable = [
        'employe_id',
        'date',
        'status',
        'reason',
    ];

    protected $casts = [
        // Force JSON serialization as 'YYYY-MM-DD' (no timezone time component)
        'date' => 'date:Y-m-d',
    ];

    public function employe()
    {
        return $this->belongsTo(Employe::class, 'employe_id');
    }
}
