<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diagram extends Model
{
    public const TYPE_ENTITY_RELATIONSHIP = 1;

    public const TYPE_RELATIONAL = 2;

    protected $fillable = ['name', 'data', 'type', 'source_diagram_id'];

    protected $casts = [
        'data' => 'array', // Laravel converte JSON <-> array automaticamente
        'type' => 'integer',
    ];

    public function sourceDiagram()
    {
        return $this->belongsTo(self::class, 'source_diagram_id');
    }

    public function relationalDiagram()
    {
        return $this->hasOne(self::class, 'source_diagram_id');
    }
}
