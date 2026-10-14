<?php

namespace App\Domain\Security;

use App\Models\AuditLog;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Weryfikacja dwuetapowa: kody TOTP (RFC 6238, 30 s, 6 cyfr, SHA-1 — tak jak
 * Google Authenticator, Authy, 1Password) i jednorazowe kody zapasowe.
 *
 * Sekret powstaje przy „Włącz”, ale działa dopiero po potwierdzeniu kodem z
 * aplikacji — nie da się zablokować konta źle zeskanowanym kodem QR.
 */
class TwoFactor
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Dopuszczalne przesunięcie zegara: ±1 krok (30 s). */
    private const WINDOW = 1;

    private const RECOVERY_CODES = 8;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function enabled(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /** Nowy (jeszcze niepotwierdzony) sekret. */
    public function begin(User $user): string
    {
        $secret = $this->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();

        return $secret;
    }

    /**
     * Potwierdzenie kodem z aplikacji. Zwraca kody zapasowe (pokazywane raz) albo null przy złym kodzie.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || $this->enabled($user) || ! $this->verifyTotp($user, $code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        AuditLog::record('account.2fa_enabled', $user);

        return $codes;
    }

    public function disable(User $user, ?User $actor = null): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        AuditLog::record('account.2fa_disabled', $user, [], $actor);
    }

    /** Kod z aplikacji albo kod zapasowy (zużywany). */
    public function verify(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }
        $code = trim($code);

        return preg_match('/^\d[\d ]*$/', $code) ? $this->verifyTotp($user, $code) : $this->useRecoveryCode($user, $code);
    }

    /** @return list<string> */
    public function newRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }
        $user->forceFill(['two_factor_recovery_codes' => encrypt(array_map(fn ($c) => Hash::make($c), $codes))])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($this->hashes($user));
    }

    public function otpauthUri(User $user): string
    {
        $issuer = (string) config('virthub.brand');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email)
            .'?secret='.$user->two_factor_secret.'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public function qrSvg(User $user): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));
        $svg = $writer->writeString($this->otpauthUri($user));

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /** Kod dla danego czasu — używany też w testach. */
    public function code(string $secret, ?int $timestamp = null): string
    {
        return $this->hotp($this->decode($secret), intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD));
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', (string) $code) || $user->two_factor_secret === null) {
            return false;
        }
        $key = $this->decode($user->two_factor_secret);
        $now = intdiv(now()->getTimestamp(), self::PERIOD);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            // Kod raz użyty (albo starszy) nie przechodzi ponownie — ochrona przed podejrzeniem i powtórzeniem.
            if ($user->two_factor_last_step !== null && $step <= $user->two_factor_last_step) {
                continue;
            }
            if (hash_equals($this->hotp($key, $step), (string) $code)) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }
        }

        return false;
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower($code);
        $hashes = $this->hashes($user);
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => encrypt(array_values($hashes))])->save();
                AuditLog::record('account.2fa_recovery_used', $user, ['left' => count($hashes)]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function hashes(User $user): array
    {
        if (! $user->two_factor_recovery_codes) {
            return [];
        }
        try {
            $hashes = decrypt($user->two_factor_recovery_codes);
        } catch (\Throwable) {
            return [];
        }

        return is_array($hashes) ? array_values($hashes) : [];
    }

    private function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
