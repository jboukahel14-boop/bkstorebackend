<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commune extends Model
{
    use HasFactory;

    protected $fillable = ['wilaya_id', 'name_en', 'name_ar'];

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }
}