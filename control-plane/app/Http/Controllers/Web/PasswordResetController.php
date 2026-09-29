<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/** „Nie pamiętasz hasła?” — link resetujący wysyłany e-mailem (ważny 60 minut). */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->where('email', mb_strtolower($request->input('email')))->first();
        // Zawieszone konto nie odzyska dostępu przez reset.
        if ($user && ! $user->isSuspended()) {
            Password::sendResetLink(['email' => $user->email]);
        }

        // Ta sama odpowiedź niezależnie od tego, czy konto istnieje — bez zgadywania adresów.
        return back()->with('status', __('Jeśli konto z tym adresem istnieje, wysłaliśmy na nie link do ustawienia nowego hasła.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
                AuditLog::record('account.password_reset', $user, [], $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('Link do zmiany hasła jest nieprawidłowy albo wygasł. Poproś o nowy.')]);
        }

        return redirect()->route('login')->with('status', __('Hasło ustawione — zaloguj się nowym hasłem.'));
    }
}
