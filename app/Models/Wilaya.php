<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wilaya extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name_en', 'name_ar', 'delivery_fee_home', 'delivery_fee_desk'];

    protected $casts = [
        'delivery_fee_home' => 'decimal:2',
        'delivery_fee_desk' => 'decimal:2',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }
}