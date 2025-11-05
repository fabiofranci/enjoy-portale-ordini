<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cliente_id', // 👈 aggiunto
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * 🔐 Gestione accesso ai vari Filament Panel
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Pannello admin → solo admin
        if ($panel->getId() === 'admin') {
            return $this->hasRole('admin');
        }

        // Pannello clienti → solo utenti con ruolo cliente
        if ($panel->getId() === 'clienti') {
            return $this->hasRole('cliente');
        }

        return false;
    }

    /**
     * 🔗 Relazione con Cliente
     */
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
