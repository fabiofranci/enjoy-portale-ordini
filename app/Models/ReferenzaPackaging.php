<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenzaPackaging extends Model
{
    protected $table = 'referenza_packagings';

    protected $fillable = [
        'referenza_fornitore_id',
        'unita_contenitore',
        'unita_contenuta',
        'quantita',
        'livello',
        'origine_campo',
        'origine_valore',
        'obbligatorio',
    ];

    protected $casts = [
        'quantita' => 'decimal:5',
        'livello' => 'integer',
        'obbligatorio' => 'boolean',
    ];

    public function referenza(): BelongsTo
    {
        return $this->belongsTo(ReferenzaFornitore::class, 'referenza_fornitore_id');
    }
}
