<?php

namespace App\Http\Controllers\Web;

use App\Domain\Security\AccountSecurity;
use App\Domain\Security\ApiScopes;
use App\Domain\Security\TwoFactor;
use App\Domain\Security\UserAgent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\IpUtils;

/** Moje konto → Bezpieczeństwo: 2FA, sesje, tokeny API, dozwolone adresy IP. */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AccountSecurity $security,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $sessions = collect();
        if (config('session.driver') === 'database') {
            $current = $request->session()->getId();
            $sessions = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->limit(50)
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn ($s) => (object) [
                    'key' => hash('sha256', $s->id),
                    'ip' => $s->ip_address,
                    'device' => UserAgent::describe($s->user_agent),
                    'last_activity' => Carbon::createFromTimestamp($s->last_activity),
                    'current' => hash_equals($s->id, $current),
                ]);
        }

        return view('panel.account-security', [
            'user' => $user,
            'twoFactorEnabled' => $this->twoFactor->enabled($user),
            'twoFactorPending' => $user->two_factor_secret !== null && ! $this->twoFactor->enabled($user),
            'qr' => $user->two_factor_secret !== null && ! $this->twoFactor->enabled($user) ? $this->twoFactor->qrSvg($user) : null,
            'recoveryLeft' => $this->twoFactor->recoveryCodesLeft($user),
            'mustEnable' => $this->security->mustEnableTwoFactor($user),
            'sessions' => $sessions,
            'tokens' => $user->tokens()->orderByDesc('id')->get(),
            'scopes' => ApiScopes::for($user),
            'currentIp' => $request->ip(),
        ]);
    }

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactor', ['current_password' => ['required', 'current_password']]);
        abort_if($this->twoFactor->enabled($request->user()), 409);
        $this->twoFactor->begin($request->user());

        return back()->with('status', __('Zeskanuj kod QR w aplikacji i wpisz kod, aby zakończyć włączanie.'));
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $code = (string) $request->validateWithBag('twoFactor', ['code' => ['required', 'string', 'max:16']])['code'];
        $codes = $this->twoFactor->confirm($request->user(), $code);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('Nieprawidłowy kod.')])->errorBag('twoFactor');
        }

        return back()->with('status', __('Weryfikacja dwuetapowa włączona.'))->with('recovery_codes', $codes);
    }

    public function cancelTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $this->twoFactor->enabled($user)) {
            $user->forceFill(['two_factor_secret' => null])->save();
        }

        return back();
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validateWithBag('twoFactor', [
            'current_password' => ['required', 'current_password'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        if ($user->role !== User::ROLE_CUSTOMER && AccountSecurity::staffMustUseTwoFactor()) {
            throw ValidationException::withMessages(['code' => __('Administrator wymaga weryfikacji dwuetapowej dla kont personelu.')])->errorBag('twoFactor');
        }
        if (! $this->twoFactor->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('Nieprawidłowy kod.')])->errorBag('twoFactor');
        }
        $this->twoFactor->disable($user);

        return back()->with('status', __('Weryfikacja dwuetapowa wyłączona.'));
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactor', ['current_password' => ['required', 'current_password']]);
        abort_unless($this->twoFactor->enabled($request->user()), 409);
        $codes = $this->twoFactor->newRecoveryCodes($request->user());
        AuditLog::record('account.2fa_recovery_regenerated', $request->user());

        return back()->with('status', __('Nowe kody zapasowe — poprzednie przestały działać.'))->with('recovery_codes', $codes);
    }

    public function destroySession(Request $request, string $key): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database', 404);
        $user = $request->user();
        $table = DB::table(config('session.table', 'sessions'));
        $match = $table->clone()->where('user_id', $user->id)->pluck('id')
            ->first(fn ($id) => hash_equals(hash('sha256', $id), $key));
        abort_if($match === null, 404);
        abort_if(hash_equals($match, $request->session()->getId()), 422, __('To jest bieżąca sesja — użyj „Wyloguj”.'));
        $table->where('id', $match)->delete();
        AuditLog::record('account.session_revoked', $user);

        return back()->with('status', __('Sesja zakończona.'));
    }

    public function allowedIps(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validateWithBag('ips', [
            'allowed_ips' => ['nullable', 'string', 'max:4000'],
            'current_password' => ['required', 'current_password'],
        ]);
        $items = AccountSecurity::parseIps((string) ($data['allowed_ips'] ?? ''));
        if ($bad = AccountSecurity::invalidIps($items)) {
            throw ValidationException::withMessages(['allowed_ips' => __('Nieprawidłowe adresy: :list', ['list' => implode(', ', $bad)])])->errorBag('ips');
        }
        // Bez obecnego adresu na liście konto zablokowałoby się natychmiast.
        if ($items !== [] && ! IpUtils::checkIp((string) $request->ip(), $items)) {
            throw ValidationException::withMessages(['allowed_ips' => __('Lista musi zawierać Twój obecny adres (:ip) — inaczej stracisz dostęp do konta.', ['ip' => $request->ip()])])->errorBag('ips');
        }
        $user->forceFill(['allowed_ips' => $items === [] ? null : implode("\n", $items)])->save();
        AuditLog::record('account.allowed_ips', $user, ['count' => count($items)]);

        return back()->with('status', $items === [] ? __('Logowanie dozwolone z każdego adresu.') : __('Zapisano dozwolone adresy IP.'));
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validateWithBag('tokens', [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(ApiScopes::for($user)))],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        abort_if($user->tokens()->count() >= 50, 422, __('Osiągnięto limit 50 tokenów — usuń nieużywane.'));
        $scopes = array_values(array_unique($data['scopes']));
        $token = $user->createToken($data['name'], $scopes, isset($data['expires_days']) ? now()->addDays((int) $data['expires_days']) : null);
        AuditLog::record('account.token_created', $user, ['name' => $data['name'], 'scopes' => $scopes]);

        return back()->with('status', __('Token utworzony. Skopiuj go teraz — nie zobaczysz go ponownie.'))
            ->with('new_token', $token->plainTextToken);
    }

    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $model = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $model->delete();
        AuditLog::record('account.token_revoked', $request->user(), ['name' => $model->name]);

        return back()->with('status', __('Token usunięty.'));
    }

    /** @internal tylko dla widoku */
    public static function scopeLabels(array $abilities): string
    {
        return implode(', ', array_map(fn ($a) => ApiScopes::label((string) $a), $abilities));
    }
}
