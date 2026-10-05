<?php

namespace App\Domain\Mail;

use App\Mail\TemplatedMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wysyłka e-maili z szablonów. Mail idzie przez kolejkę; błąd poczty nigdy
 * nie przerywa operacji, która go wywołała (instalacja maszyny, płatność…).
 */
class TemplateMailer
{
    /**
     * @param  User|string  $to  użytkownik (jego język i dane w zmiennych) albo adres
     * @param  array<string, mixed>  $vars
     */
    public function send(User|string $to, string $key, array $vars = [], ?string $locale = null): bool
    {
        try {
            $user = $to instanceof User ? $to : null;
            $locale ??= $user?->locale ?: config('virthub.default_locale', 'pl');
            $template = EmailTemplates::resolve($key, $locale);
            if (! $template['enabled']) {
                return false;
            }

            $vars = $this->commonVars($user) + $vars;
            $mail = new TemplatedMail(
                TemplateRenderer::subject($template['subject'], $vars),
                TemplateRenderer::html($template['body'], $vars),
                $key,
            );
            Mail::to($user?->email ?? $to)->locale($locale)->queue($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning('Nie udało się wysłać e-maila z szablonu', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Wiadomość do personelu z uprawnieniem (np. nowe zgłoszenie do działu). */
    public function sendToStaff(string $permission, string $key, array $vars = []): int
    {
        $sent = 0;
        User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPPORT])->whereNull('suspended_at')->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))
            ->each(function (User $u) use ($key, $vars, &$sent) {
                $sent += (int) $this->send($u, $key, $vars);
            });

        return $sent;
    }

    /** @return array<string, mixed> */
    private function commonVars(?User $user): array
    {
        return [
            'brand' => config('virthub.brand'),
            'panel_url' => url('/panel'),
            'user' => ['name' => $user?->name ?: $user?->email, 'email' => $user?->email],
        ];
    }
}
