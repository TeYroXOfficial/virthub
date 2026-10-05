<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Szablon e-mail nadpisany przez administratora (klucz + język). */
class EmailTemplate extends Model
{
    protected $fillable = ['key', 'locale', 'subject', 'body', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
