<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Employe
 * Représente un employé d'une entreprise.
 */
class Employe extends Model
{
    use HasFactory;

    protected $fillable = [
        'entreprise_id',
        'first_name',
        'last_name',
        'position',
        // daily attendance status fields
        'attendance_date',
        'arrival_signed',
        'departure_signed',
    ];

    public function entreprise()
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function absences()
    {
        return $this->hasMany(Absence::class);
    }
}
