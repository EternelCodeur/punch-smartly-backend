<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entreprise extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'enterprise_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
