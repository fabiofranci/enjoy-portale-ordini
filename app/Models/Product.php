<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'Prodotti';

    protected $fillable = [
        'nome',
        'codice',
        'categoria_id',
        'unita_misura',
        'packaging',
        'descrizione',
        'immagine',
        'pdf_sicurezza',
        'disponibile',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

}
