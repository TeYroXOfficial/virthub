<?php

namespace App\Http\Controllers\Web;

use App\Domain\Tickets\TicketService;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Zgłoszenia od strony klienta: tylko własne, bez notatek wewnętrznych personelu. */
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $query = Ticket::query()->where('user_id', $request->user()->id)->with('department')->latest('last_reply_at');
        if ($status === 'open') {
            $query->where('status', '!=', Ticket::STATUS_CLOSED);
        } elseif ($status === 'closed') {
            $query->where('status', Ticket::STATUS_CLOSED);
        }

        return view('panel.tickets.index', [
            'tickets' => $query->paginate(20)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        return view('panel.tickets.create', [
            'departments' => TicketDepartment::query()->where('is_active', true)->ordered()->get(),
            'services' => TicketService::serviceOptions($request->user()),
            'selected' => $request->query('service'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('ticket_departments', 'id')->where('is_active', true)],
            'subject' => ['required', 'string', 'max:150'],
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'service' => ['nullable', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:20000'],
        ] + TicketService::attachmentRules());

        $ticket = $this->tickets->open($request->user(), $data, $request->file('attachments', []));

        return redirect()->route('panel.tickets.show', $ticket)->with('status', __('Zgłoszenie #:id zostało wysłane.', ['id' => $ticket->id]));
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorizeOwner($request, $ticket);
        $ticket->load(['department', 'messages' => fn ($q) => $q->where('is_internal', false)->with('author', 'attachments')]);

        return view('panel.tickets.show', ['ticket' => $ticket, 'service' => $ticket->service()]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeOwner($request, $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']] + TicketService::attachmentRules());

        $this->tickets->customerReply($ticket, $request->user(), $data['body'], $request->file('attachments', []));

        return redirect()->route('panel.tickets.show', $ticket)->with('status', __('Odpowiedź wysłana.'));
    }

    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeOwner($request, $ticket);
        $this->tickets->close($ticket, $request->user());

        return back()->with('status', __('Zgłoszenie zamknięte.'));
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeOwner($request, $ticket);
        $this->tickets->reopen($ticket, $request->user());

        return back()->with('status', __('Zgłoszenie otwarte ponownie.'));
    }

    /** Pobranie załącznika — klient (właściciel) albo personel z dostępem do zgłoszeń. */
    public function attachment(Request $request, TicketAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message;
        $ticket = $message?->ticket;
        abort_if($ticket === null, 404);

        $user = $request->user();
        $staff = $user->hasPermission('admin.tickets');
        abort_unless($staff || ($ticket->user_id === $user->id && ! $message->is_internal), 404);
        abort_unless(Storage::disk(TicketService::DISK)->exists($attachment->path), 404);

        $inline = in_array($attachment->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);

        // Obrazy podglądamy w przeglądarce, resztę zawsze pobieramy jako plik binarny.
        return Storage::disk(TicketService::DISK)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    private function authorizeOwner(Request $request, Ticket $ticket): void
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
    }
}
