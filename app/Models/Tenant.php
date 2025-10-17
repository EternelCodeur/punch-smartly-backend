<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contact',
    ];

    public function entreprises()
    {
        return $this->hasMany(Entreprise::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
