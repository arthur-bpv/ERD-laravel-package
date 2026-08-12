<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diagram extends Model
{
    protected $fillable = ['name', 'data'];

    protected $casts = [
        'data' => 'array', // Laravel converte JSON <-> array automaticamente
    ];
}
