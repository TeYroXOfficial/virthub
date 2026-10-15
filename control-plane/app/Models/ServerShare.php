<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maszyna udostępniona innemu użytkownikowi z wybranymi uprawnieniami.
 * Udostępniony widzi maszynę na liście i może to, co mu nadano — nigdy jej
 * nie usunie, nie udostępni dalej ani nie przejmie.
 */
class ServerShare extends Model
{
    /** Uprawnienia, które właściciel może nadać (klucze jak w uprawnieniach konta). */
    public const PERMISSIONS = ['servers.power', 'servers.console', 'servers.firewall', 'servers.snapshots', 'servers.password', 'servers.iso', 'servers.rebuild'];

    public const MAX_PER_SERVER = 10;

    protected $fillable = ['server_id', 'user_id', 'permissions', 'created_by'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, (array) $this->permissions, true);
    }

    public static function label(string $permission): string
    {
        return match ($permission) {
            'servers.power' => __('zasilanie'),
            'servers.console' => __('konsola'),
            'servers.firewall' => __('zapora'),
            'servers.snapshots' => __('migawki i kopie'),
            'servers.password' => __('reset hasła'),
            'servers.iso' => __('obrazy ISO'),
            'servers.rebuild' => __('reinstalacja'),
            default => $permission,
        };
    }
}
