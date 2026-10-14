<?php

namespace App\Domain\Mail;

use App\Domain\Webhooks\Webhooks;
use App\Mail\TemplatedMail;
use App\Models\AuditLog;
use App\Models\BillingService;
use App\Models\Mailout;
use App\Models\MailoutRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Mailouty: masowe wiadomości do grup użytkowników (ogłoszenia, prace serwisowe).
 *
 * Start zamraża listę odbiorców (tabela mailout_recipients), a wysyłka idzie
 * partiami z harmonogramu — panel nie zatyka poczty i wznawia po restarcie.
 * Każdy odbiorca dostaje własny e-mail ze swoimi zmiennymi.
 */
class Mailouts
{
    /** Wiadomości wysyłane w jednym przebiegu (co minutę). */
    public const BATCH = 100;

    /** @return array<string, string> rodzaj odbiorców → opis */
    public static function audiences(): array
    {
        return [
            'all' => __('Wszyscy użytkownicy'),
            'customers' => __('Klienci'),
            'staff' => __('Personel (administratorzy i wsparcie)'),
            'with_servers' => __('Klienci z maszynami'),
            'hypervisors' => __('Klienci z maszynami na wybranych hypervisorach'),
            'packages' => __('Klienci z maszynami w wybranych pakietach'),
            'products' => __('Klienci z aktywną usługą wybranych produktów'),
        ];
    }

    /** Zapytanie o odbiorców (bez zablokowanych kont i, gdy trzeba, bez wypisanych). */
    public function query(array $audience, bool $respectOptOut): Builder
    {
        $type = (string) ($audience['type'] ?? 'all');
        $ids = array_map('intval', (array) ($audience['ids'] ?? []));

        return User::query()
            ->whereNull('suspended_at')
            ->when($respectOptOut, fn ($q) => $q->where('mailout_opt_out', false))
            ->when($type === 'customers', fn ($q) => $q->where('role', User::ROLE_CUSTOMER))
            ->when($type === 'staff', fn ($q) => $q->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPPORT]))
            ->when($type === 'with_servers', fn ($q) => $q->whereHas('servers'))
            ->when($type === 'hypervisors', fn ($q) => $q->whereHas('servers', fn ($s) => $s->whereIn('hypervisor_id', $ids)))
            ->when($type === 'packages', fn ($q) => $q->whereHas('servers', fn ($s) => $s->whereIn('vps_package_id', $ids)))
            ->when($type === 'products', fn ($q) => $q->whereHas('billingServices', fn ($s) => $s->whereIn('product_id', $ids)->whereIn('status', BillingService::LIVE)));
    }

    public function count(Mailout $mailout): int
    {
        return $this->query((array) $mailout->audience, $mailout->respect_opt_out)->count();
    }

    /** @return array{subject:string, html:string} */
    public function render(Mailout $mailout, User $user): array
    {
        $vars = [
            'brand' => config('virthub.brand'),
            'panel_url' => url('/panel'),
            'user' => ['name' => $user->name ?: $user->email, 'email' => $user->email],
            'servers' => ['count' => $user->servers()->count()],
            'unsubscribe_url' => URL::signedRoute('mailouts.unsubscribe', ['user' => $user->id]),
        ];
        $html = TemplateRenderer::html($mailout->body, $vars);
        if ($mailout->respect_opt_out) {
            $html .= '<p style="font-size:12px;color:#677789">'.e(__('Nie chcesz takich wiadomości?')).' <a href="'.e($vars['unsubscribe_url']).'">'.e(__('Wypisz się')).'</a></p>';
        }

        return ['subject' => TemplateRenderer::subject($mailout->subject, $vars), 'html' => $html];
    }

    /** Zamraża listę odbiorców i zaczyna wysyłkę. */
    public function start(Mailout $mailout, User $actor): int
    {
        if (! $mailout->editable()) {
            throw new \DomainException(__('Ten mailout został już wysłany.'));
        }
        $total = 0;
        DB::transaction(function () use ($mailout, &$total) {
            $now = now();
            $this->query((array) $mailout->audience, $mailout->respect_opt_out)
                ->select(['id', 'email'])
                ->chunkById(500, function ($users) use ($mailout, $now, &$total) {
                    MailoutRecipient::query()->insertOrIgnore($users->map(fn ($u) => [
                        'mailout_id' => $mailout->id, 'user_id' => $u->id, 'email' => $u->email,
                        'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                    ])->all());
                    $total += $users->count();
                });
            $mailout->forceFill(['status' => Mailout::SENDING, 'total' => $total, 'started_at' => $now])->save();
        });
        AuditLog::record('mailout.started', $mailout, ['subject' => $mailout->subject, 'recipients' => $total], $actor);
        $this->process();

        return $total;
    }

    public function cancel(Mailout $mailout, User $actor): void
    {
        if ($mailout->status !== Mailout::SENDING) {
            return;
        }
        $mailout->recipients()->where('status', 'pending')->update(['status' => 'skipped']);
        $mailout->forceFill(['status' => Mailout::CANCELLED, 'finished_at' => now()])->save();
        AuditLog::record('mailout.cancelled', $mailout, ['sent' => $mailout->sent_count], $actor);
    }

    /** Wysyła kolejną partię (harmonogram co minutę). */
    public function process(): int
    {
        $sent = 0;
        foreach (Mailout::query()->where('status', Mailout::SENDING)->orderBy('id')->get() as $mailout) {
            $left = self::BATCH - $sent;
            if ($left <= 0) {
                break;
            }
            $batch = $mailout->recipients()->with('user')->where('status', 'pending')->orderBy('id')->limit($left)->get();
            foreach ($batch as $recipient) {
                $sent++;
                $this->sendOne($mailout, $recipient);
            }
            if (! $mailout->recipients()->where('status', 'pending')->exists()) {
                $this->finish($mailout);
            }
        }

        return $sent;
    }

    /** Podgląd na własną skrzynkę. */
    public function sendTest(Mailout $mailout, User $to): void
    {
        $rendered = $this->render($mailout, $to);
        Mail::to($to->email)->locale($to->locale ?: app()->getLocale())
            ->sendNow(new TemplatedMail('['.__('TEST').'] '.$rendered['subject'], $rendered['html'], 'mailout'));
    }

    private function sendOne(Mailout $mailout, MailoutRecipient $recipient): void
    {
        $user = $recipient->user;
        // Zgoda mogła się zmienić od startu (wypisanie, blokada konta).
        if ($user === null || $user->isSuspended() || ($mailout->respect_opt_out && $user->mailout_opt_out)) {
            $recipient->forceFill(['status' => 'skipped'])->save();

            return;
        }
        try {
            $rendered = $this->render($mailout, $user);
            Mail::to($user->email)->locale($user->locale ?: config('virthub.default_locale', 'pl'))
                ->sendNow(new TemplatedMail($rendered['subject'], $rendered['html'], 'mailout'));
            $recipient->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
            $mailout->increment('sent_count');
        } catch (Throwable $e) {
            $recipient->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            $mailout->increment('failed_count');
        }
    }

    private function finish(Mailout $mailout): void
    {
        $mailout->forceFill(['status' => Mailout::SENT, 'finished_at' => now()])->save();
        app(Webhooks::class)->dispatch('mailout.sent', [
            'id' => $mailout->id, 'subject' => $mailout->subject,
            'total' => $mailout->total, 'sent' => $mailout->sent_count, 'failed' => $mailout->failed_count,
        ]);
    }
}
