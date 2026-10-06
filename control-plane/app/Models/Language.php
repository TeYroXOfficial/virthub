<?php

namespace App\Models;

use App\Domain\Settings\Languages;
use Illuminate\Database\Eloquent\Model;

/** Język panelu. Polski jest językiem źródłowym (klucze tłumaczeń), angielski ma pliki w lang/. */
class Language extends Model
{
    protected $fillable = ['code', 'name', 'base', 'is_enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function isBuiltin(): bool
    {
        return in_array($this->code, Languages::BUILTIN, true);
    }
}
