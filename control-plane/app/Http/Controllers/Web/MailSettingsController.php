<?php

namespace App\Http\Controllers\Web;

use App\Domain\Settings\MailSettings;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Administracja → Poczta (SMTP): serwer poczty wychodzącej bez grzebania w .env. */
class MailSettingsController extends Controller
{
    public function show(): View
    {
        return view('panel.admin.mail', [
            'mail' => MailSettings::current(),
            'encryptions' => MailSettings::ENCRYPTIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $data = $request->validate([
            'host' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:255'],
            'port' => [Rule::requiredIf($enabled), 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(array_keys(MailSettings::ENCRYPTIONS))],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:100'],
        ]);

        MailSettings::save(['enabled' => $enabled] + $data);
        AuditLog::record('settings.mail', null, ['enabled' => $enabled, 'host' => $data['host'] ?? null], $request->user());

        return redirect()->route('panel.admin.mail')->with('status', $enabled
            ? __('Ustawienia poczty zapisane. Wyślij testowy e-mail, żeby sprawdzić połączenie.')
            : __('Wysyłka poczty wyłączona — wiadomości trafiają tylko do logu panelu.'));
    }

    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:255']]);

        try {
            Mail::raw(
                __('To jest testowa wiadomość z panelu :brand. Skoro ją czytasz, poczta działa.', ['brand' => config('virthub.brand')]),
                fn ($message) => $message->to($data['to'])->subject(__('Test poczty — :brand', ['brand' => config('virthub.brand')])),
            );
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['to' => __('Nie udało się wysłać: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', MailSettings::configured()
            ? __('Wysłano testową wiadomość na :to.', ['to' => $data['to']])
            : __('Wysyłka jest wyłączona — wiadomość trafiła tylko do logu panelu.'));
    }
}
