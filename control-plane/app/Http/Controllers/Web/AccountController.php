<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Moje konto: dane, avatar, hasło i sesje na innych urządzeniach. */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('panel.account', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [Rule::requiredIf(fn () => mb_strtolower((string) $request->input('email')) !== mb_strtolower($user->email)), 'nullable', 'current_password'],
        ], ['current_password.required' => __('Zmiana adresu e-mail wymaga podania obecnego hasła.')]);

        $changedEmail = mb_strtolower($data['email']) !== mb_strtolower($user->email);
        $user->forceFill(['name' => $data['name'], 'email' => mb_strtolower($data['email'])])->save();
        AuditLog::record('account.profile', $user, ['email_changed' => $changedEmail]);

        return back()->with('status', __('Zapisano dane konta.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10), 'different:current_password'],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $data['password']])->save();
        // Inne zalogowane urządzenia tracą sesję — nowe hasło ma działać od razu wszędzie.
        $this->endOtherSessions($request);
        AuditLog::record('account.password', $user);
        app(\App\Domain\Mail\TemplateMailer::class)->send($user, 'account.password_changed', ['ip' => $request->ip()]);

        return back()->with('status', __('Hasło zmienione. Pozostałe urządzenia zostały wylogowane.'));
    }

    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validateWithBag('devices', ['current_password' => ['required', 'current_password']]);
        $this->endOtherSessions($request);
        AuditLog::record('account.logout_others', $request->user());

        return back()->with('status', __('Wylogowano wszystkie inne urządzenia.'));
    }

    /** Sesje na innych urządzeniach i ciasteczka „zapamiętaj mnie” przestają działać. */
    private function endOtherSessions(Request $request): void
    {
        $user = $request->user();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
        // Obecna sesja zostaje, z nowym identyfikatorem.
        $request->session()->migrate(true);
        Auth::login($user);
    }

    public function uploadAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ]);
        $user = $request->user();
        $file = $request->file('avatar');
        $path = $file->storeAs('avatars', $user->id.'-'.Str::random(8).'.'.$file->extension(), 'local');

        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
        }
        $user->forceFill(['avatar_path' => $path])->save();

        return back()->with('status', __('Avatar zmieniony.'));
    }

    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return back()->with('status', __('Avatar usunięty.'));
    }

    /** Avatar z prywatnego dysku — widoczny dla zalogowanych (lista użytkowników, pasek boczny). */
    public function avatar(User $user): Response
    {
        abort_if(! $user->avatar_path || ! Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
