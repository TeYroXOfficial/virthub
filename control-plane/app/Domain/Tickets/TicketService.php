<?php

namespace App\Domain\Tickets;

use App\Domain\Mail\TemplateMailer;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logika zgłoszeń: otwieranie, odpowiedzi, zmiany statusu i powiadomienia.
 * Kontrolery klienta i personelu tylko walidują wejście i wołają ten serwis.
 */
class TicketService
{
    public const MAX_FILES = 5;

    public const MAX_FILE_KB = 5120;

    public const MIMES = 'jpg,jpeg,png,gif,webp,pdf,txt,log,zip';

    public const DISK = 'local';

    public function __construct(private readonly TemplateMailer $mailer) {}

    /** Reguły walidacji załączników — wspólne dla klienta i personelu. */
    public static function attachmentRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_FILE_KB, 'mimes:'.self::MIMES],
        ];
    }

    /** Usługi klienta do wyboru w formularzu: ['server:12' => 'vps1.example.com', …]. */
    public static function serviceOptions(User $user): array
    {
        $options = [];
        foreach (Server::query()->where('user_id', $user->id)->orderBy('hostname')->get(['id', 'hostname']) as $s) {
            $options['server:'.$s->id] = __('Maszyna').': '.$s->hostname;
        }
        foreach (AppServer::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']) as $a) {
            $options['app:'.$a->id] = __('Aplikacja').': '.$a->name;
        }

        return $options;
    }

    /**
     * @param  array{department_id:int, subject:string, priority:string, service?:?string, body:string}  $data
     * @param  array<UploadedFile>  $files
     */
    public function open(User $customer, array $data, array $files = []): Ticket
    {
        [$serviceType, $serviceId] = $this->parseService($customer, $data['service'] ?? null);

        $ticket = DB::transaction(function () use ($customer, $data, $files, $serviceType, $serviceId) {
            $ticket = Ticket::create([
                'user_id' => $customer->id,
                'ticket_department_id' => $data['department_id'],
                'subject' => $data['subject'],
                'priority' => $data['priority'],
                'status' => Ticket::STATUS_OPEN,
                'service_type' => $serviceType,
                'service_id' => $serviceId,
                'last_reply_at' => now(),
                'last_reply_by' => 'customer',
            ]);
            $this->addMessage($ticket, $customer, $data['body'], false, false, $files);

            return $ticket;
        });

        AuditLog::record('ticket.opened', $ticket, ['subject' => $ticket->subject], $customer);
        $ticket->load('department', 'user');
        $this->mailer->send($customer, 'ticket.opened', $this->vars($ticket));
        $this->mailer->sendToStaff('admin.tickets', 'ticket.staff_new', $this->vars($ticket, $data['body']));

        return $ticket;
    }

    /** Odpowiedź klienta — otwiera ponownie zamknięte zgłoszenie. */
    public function customerReply(Ticket $ticket, User $customer, string $body, array $files = []): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $customer, $body, $files) {
            $message = $this->addMessage($ticket, $customer, $body, false, false, $files);
            $ticket->update([
                'status' => Ticket::STATUS_CUSTOMER_REPLY,
                'last_reply_at' => now(),
                'last_reply_by' => 'customer',
                'closed_at' => null,
            ]);

            return $message;
        });

        $vars = $this->vars($ticket, $body);
        $assignee = $ticket->assignee;
        if ($assignee !== null && $assignee->hasPermission('admin.tickets') && ! $assignee->isSuspended()) {
            $this->mailer->send($assignee, 'ticket.staff_reply', $vars);
        } else {
            $this->mailer->sendToStaff('admin.tickets', 'ticket.staff_reply', $vars);
        }

        return $message;
    }

    /** Odpowiedź personelu albo notatka wewnętrzna (bez maila i bez zmiany statusu). */
    public function staffReply(Ticket $ticket, User $staff, string $body, bool $internal = false, array $files = [], ?string $status = null): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $staff, $body, $internal, $files, $status) {
            $message = $this->addMessage($ticket, $staff, $body, true, $internal, $files);
            if (! $internal) {
                $next = in_array($status, [Ticket::STATUS_ANSWERED, Ticket::STATUS_ON_HOLD, Ticket::STATUS_CLOSED], true)
                    ? $status : Ticket::STATUS_ANSWERED;
                $ticket->update([
                    'status' => $next,
                    'last_reply_at' => now(),
                    'last_reply_by' => 'staff',
                    'assigned_to' => $ticket->assigned_to ?? $staff->id,
                    'closed_at' => $next === Ticket::STATUS_CLOSED ? now() : null,
                ]);
            }

            return $message;
        });

        if (! $internal && $ticket->user) {
            $this->mailer->send($ticket->user, 'ticket.replied', $this->vars($ticket, $body) + [
                'staff' => ['name' => $staff->name ?: __('Zespół wsparcia')],
            ]);
            if ($ticket->isClosed()) {
                $this->mailer->send($ticket->user, 'ticket.closed', $this->vars($ticket));
            }
        }

        return $message;
    }

    public function close(Ticket $ticket, ?User $actor, bool $notify = true): void
    {
        if ($ticket->isClosed()) {
            return;
        }
        $ticket->update(['status' => Ticket::STATUS_CLOSED, 'closed_at' => now()]);
        AuditLog::record('ticket.closed', $ticket, [], $actor);
        // Klient nie dostaje maila o zamknięciu, które sam kliknął.
        if ($notify && $ticket->user && $actor?->id !== $ticket->user_id) {
            $this->mailer->send($ticket->user, 'ticket.closed', $this->vars($ticket));
        }
    }

    public function reopen(Ticket $ticket, ?User $actor): void
    {
        if (! $ticket->isClosed()) {
            return;
        }
        $byCustomer = $actor?->id === $ticket->user_id;
        $ticket->update([
            'status' => $byCustomer ? Ticket::STATUS_CUSTOMER_REPLY : Ticket::STATUS_OPEN,
            'closed_at' => null,
        ]);
        AuditLog::record('ticket.reopened', $ticket, [], $actor);
    }

    /** Zmiany personelu: status, priorytet, dział, przypisanie. */
    public function update(Ticket $ticket, User $staff, array $changes): void
    {
        $wasClosed = $ticket->isClosed();
        if (isset($changes['status'])) {
            $changes['closed_at'] = $changes['status'] === Ticket::STATUS_CLOSED ? ($ticket->closed_at ?? now()) : null;
        }
        $ticket->update($changes);
        AuditLog::record('ticket.updated', $ticket, array_diff_key($changes, ['closed_at' => true]), $staff);

        if (! $wasClosed && $ticket->isClosed() && $ticket->user) {
            $this->mailer->send($ticket->user, 'ticket.closed', $this->vars($ticket));
        }
    }

    /** Zamyka zgłoszenia czekające na klienta dłużej niż ustawiona liczba dni. */
    public function autoClose(): int
    {
        $days = (int) Setting::get('tickets.autoclose_days', 7);
        if ($days <= 0) {
            return 0;
        }
        $closed = 0;
        Ticket::query()
            ->where('status', Ticket::STATUS_ANSWERED)
            ->where('last_reply_at', '<', now()->subDays($days))
            ->each(function (Ticket $ticket) use (&$closed) {
                $this->close($ticket, null);
                $closed++;
            });

        return $closed;
    }

    public function deleteTicket(Ticket $ticket, User $staff): void
    {
        Storage::disk(self::DISK)->deleteDirectory('tickets/'.$ticket->id);
        AuditLog::record('ticket.deleted', $ticket, ['subject' => $ticket->subject], $staff);
        $ticket->delete();
    }

    /** @param  array<UploadedFile>  $files */
    private function addMessage(Ticket $ticket, User $author, string $body, bool $staff, bool $internal, array $files): TicketMessage
    {
        $message = $ticket->messages()->create([
            'user_id' => $author->id,
            'body' => $body,
            'is_staff' => $staff,
            'is_internal' => $internal,
        ]);

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            // Losowa nazwa na dysku — oryginalna tylko w bazie (do nagłówka pobierania).
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $path = $file->storeAs('tickets/'.$ticket->id, Str::random(40).'.'.preg_replace('/[^a-z0-9]/', '', $ext), self::DISK);
            TicketAttachment::create([
                'ticket_message_id' => $message->id,
                'path' => $path,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        return $message;
    }

    /** @return array{0:?string, 1:?int} */
    private function parseService(User $customer, ?string $value): array
    {
        if (! $value || ! preg_match('/^(server|app):(\d+)$/', $value, $m)) {
            return [null, null];
        }
        $model = $m[1] === 'server' ? Server::class : AppServer::class;
        // Tylko własna usługa klienta — inaczej powiązanie jest pomijane.
        $owned = $model::query()->whereKey((int) $m[2])->where('user_id', $customer->id)->exists();

        return $owned ? [$m[1], (int) $m[2]] : [null, null];
    }

    /** @return array<string, mixed> */
    private function vars(Ticket $ticket, ?string $message = null): array
    {
        return [
            'ticket' => [
                'number' => (string) $ticket->id,
                'subject' => $ticket->subject,
                'department' => $ticket->department?->name ?? '—',
                'priority' => Ticket::priorityLabel($ticket->priority),
                'url' => route('panel.tickets.show', $ticket),
                'admin_url' => route('panel.admin.tickets.show', $ticket),
            ],
            'customer' => ['email' => $ticket->user?->email ?? '—'],
            'message' => $message ?? '',
        ];
    }
}
