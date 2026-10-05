<?php

namespace App\Http\Controllers\Web;

use App\Domain\Tickets\TicketService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCannedResponse;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administracja → Zgłoszenia: kolejka personelu, działy, gotowe odpowiedzi. */
class TicketAdminController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...Ticket::STATUSES, 'awaiting', 'active', 'all'])],
            'department' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(Ticket::PRIORITIES)],
            'mine' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'active';

        $query = Ticket::query()->with('user', 'department', 'assignee');
        match ($status) {
            'all' => null,
            'active' => $query->where('status', '!=', Ticket::STATUS_CLOSED),
            'awaiting' => $query->whereIn('status', Ticket::AWAITING_STAFF),
            default => $query->where('status', $status),
        };
        if (! empty($filters['department'])) {
            $query->where('ticket_department_id', $filters['department']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['mine'])) {
            $query->where('assigned_to', $request->user()->id);
        }
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', '%'.addcslashes($q, '%_\\').'%')
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.addcslashes($q, '%_\\').'%'));
                if (ctype_digit(ltrim($q, '#'))) {
                    $w->orWhere('id', (int) ltrim($q, '#'));
                }
            });
        }
        // Najpierw pilne i czekające najdłużej.
        $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('last_reply_at');

        return view('panel.admin.tickets.index', [
            'tickets' => $query->paginate(30)->withQueryString(),
            'filters' => $filters + ['status' => $status],
            'departments' => TicketDepartment::query()->ordered()->get(),
            'counts' => [
                'awaiting' => Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->count(),
                'mine' => Ticket::query()->where('assigned_to', $request->user()->id)->where('status', '!=', Ticket::STATUS_CLOSED)->count(),
                'urgent' => Ticket::query()->where('priority', 'urgent')->where('status', '!=', Ticket::STATUS_CLOSED)->count(),
                'on_hold' => Ticket::query()->where('status', Ticket::STATUS_ON_HOLD)->count(),
            ],
        ]);
    }

    public function show(Ticket $ticket): View
    {
        $ticket->load(['user', 'department', 'assignee', 'messages' => fn ($q) => $q->with('author', 'attachments')]);

        return view('panel.admin.tickets.show', [
            'ticket' => $ticket,
            'service' => $ticket->service(),
            'departments' => TicketDepartment::query()->ordered()->get(),
            'staff' => $this->staff(),
            'canned' => TicketCannedResponse::query()->orderBy('title')->get(),
            'otherTickets' => Ticket::query()->where('user_id', $ticket->user_id)->whereKeyNot($ticket->id)->latest()->limit(5)->get(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'internal' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in([Ticket::STATUS_ANSWERED, Ticket::STATUS_ON_HOLD, Ticket::STATUS_CLOSED])],
        ] + TicketService::attachmentRules());

        $internal = $request->boolean('internal');
        $this->tickets->staffReply($ticket, $request->user(), $data['body'], $internal, $request->file('attachments', []), $data['status'] ?? null);

        return redirect()->route('panel.admin.tickets.show', $ticket)
            ->with('status', $internal ? __('Notatka dodana — klient jej nie widzi.') : __('Odpowiedź wysłana do klienta.'));
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Ticket::STATUSES)],
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'ticket_department_id' => ['nullable', 'integer', 'exists:ticket_departments,id'],
            'assigned_to' => ['nullable', 'integer', Rule::in($this->staff()->pluck('id')->all())],
        ]);

        $this->tickets->update($ticket, $request->user(), $data);

        return back()->with('status', __('Zgłoszenie zaktualizowane.'));
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->tickets->deleteTicket($ticket, $request->user());

        return redirect()->route('panel.admin.tickets')->with('status', __('Zgłoszenie usunięte.'));
    }

    // --- ustawienia: działy, gotowe odpowiedzi, automatyczne zamykanie -----------------

    public function settings(): View
    {
        return view('panel.admin.tickets.settings', [
            'departments' => TicketDepartment::query()->withCount('tickets')->ordered()->get(),
            'canned' => TicketCannedResponse::query()->orderBy('title')->get(),
            'autoclose' => (int) Setting::get('tickets.autoclose_days', 7),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['autoclose_days' => ['required', 'integer', 'between:0,365']]);
        Setting::put(['tickets.autoclose_days' => (int) $data['autoclose_days']]);
        AuditLog::record('settings.tickets', null, $data, $request->user());

        return back()->with('status', __('Ustawienia zgłoszeń zapisane.'));
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        TicketDepartment::create($this->departmentData($request));

        return back()->with('status', __('Dział dodany.'));
    }

    public function updateDepartment(Request $request, TicketDepartment $department): RedirectResponse
    {
        $department->update($this->departmentData($request));

        return back()->with('status', __('Dział zapisany.'));
    }

    public function destroyDepartment(TicketDepartment $department): RedirectResponse
    {
        if ($department->tickets()->exists()) {
            return back()->withErrors(['department' => __('Dział ma zgłoszenia — wyłącz go zamiast usuwać.')]);
        }
        $department->delete();

        return back()->with('status', __('Dział usunięty.'));
    }

    public function storeCanned(Request $request): RedirectResponse
    {
        TicketCannedResponse::create($this->cannedData($request));

        return back()->with('status', __('Gotowa odpowiedź dodana.'));
    }

    public function updateCanned(Request $request, TicketCannedResponse $canned): RedirectResponse
    {
        $canned->update($this->cannedData($request));

        return back()->with('status', __('Gotowa odpowiedź zapisana.'));
    }

    public function destroyCanned(TicketCannedResponse $canned): RedirectResponse
    {
        $canned->delete();

        return back()->with('status', __('Gotowa odpowiedź usunięta.'));
    }

    private function departmentData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ]);

        return $data + ['is_active' => $request->boolean('is_active'), 'sort_order' => $data['sort_order'] ?? 0];
    }

    private function cannedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
    }

    /** @return Collection<int, User> */
    private function staff(): Collection
    {
        return User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPPORT])->whereNull('suspended_at')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('admin.tickets'))->values();
    }
}
