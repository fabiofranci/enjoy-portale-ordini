<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'import_batches';

    protected $fillable = [
        'fornitore_id',
        'listino_id',
        'user_id',
        'nome_file_originale',
        'file_hash',
        'profilo',
        'stato',
        'righe_lette',
        'referenze_create',
        'referenze_aggiornate',
        'prezzi_creati',
        'prezzi_aggiornati',
        'righe_ignorate',
        'warnings',
        'errori',
        'riepilogo',
        'iniziato_il',
        'completato_il',
    ];

    protected $casts = [
        'warnings' => 'array',
        'errori' => 'array',
        'riepilogo' => 'array',
        'iniziato_il' => 'datetime',
        'completato_il' => 'datetime',
    ];

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'fornitore_id');
    }

    public function listino(): BelongsTo
    {
        return $this->belongsTo(Listino::class, 'listino_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
