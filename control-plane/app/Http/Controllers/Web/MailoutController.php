<?php

namespace App\Http\Controllers\Web;

use App\Domain\Mail\Mailouts;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\Mailout;
use App\Models\Product;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** Administracja → Mailouty, oraz wypisanie się z linku w wiadomości. */
class MailoutController extends Controller
{
    public function __construct(private readonly Mailouts $mailouts) {}

    public function index(): View
    {
        return view('panel.admin.mailouts.index', [
            'mailouts' => Mailout::query()->with('author')->latest('id')->paginate(20),
            'audiences' => Mailouts::audiences(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Mailout(['audience' => ['type' => 'all'], 'respect_opt_out' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $mailout = Mailout::create($this->validated($request) + ['created_by' => $request->user()->id]);
        AuditLog::record('mailout.created', $mailout, ['subject' => $mailout->subject]);

        return redirect()->route('panel.admin.mailouts.show', $mailout)->with('status', __('Zapisano szkic mailoutu.'));
    }

    public function show(Request $request, Mailout $mailout): View
    {
        if ($mailout->editable()) {
            return $this->form($mailout, $request->user());
        }

        return view('panel.admin.mailouts.show', [
            'mailout' => $mailout,
            'audiences' => Mailouts::audiences(),
            'recipients' => $mailout->recipients()->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")->orderBy('id')->paginate(50),
            'preview' => $this->mailouts->render($mailout, $request->user()),
        ]);
    }

    public function update(Request $request, Mailout $mailout): RedirectResponse
    {
        abort_unless($mailout->editable(), 409);
        $mailout->fill($this->validated($request))->save();

        return back()->with('status', __('Zapisano szkic mailoutu.'));
    }

    public function destroy(Mailout $mailout): RedirectResponse
    {
        abort_if($mailout->status === Mailout::SENDING, 409);
        AuditLog::record('mailout.deleted', $mailout, ['subject' => $mailout->subject]);
        $mailout->delete();

        return redirect()->route('panel.admin.mailouts')->with('status', __('Mailout usunięty.'));
    }

    public function test(Request $request, Mailout $mailout): RedirectResponse
    {
        try {
            $this->mailouts->sendTest($mailout, $request->user());
        } catch (Throwable $e) {
            return back()->withErrors(['mail' => __('Nie udało się wysłać testu: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('Wysłano test na :email.', ['email' => $request->user()->email]));
    }

    public function start(Request $request, Mailout $mailout): RedirectResponse
    {
        abort_unless($mailout->editable(), 409);
        if ($this->mailouts->count($mailout) === 0) {
            return back()->withErrors(['audience' => __('Brak odbiorców w wybranej grupie.')]);
        }
        $total = $this->mailouts->start($mailout, $request->user());

        return redirect()->route('panel.admin.mailouts.show', $mailout)
            ->with('status', trans_choice('Wysyłka rozpoczęta do :count odbiorcy.|Wysyłka rozpoczęta do :count odbiorców.|Wysyłka rozpoczęta do :count odbiorców.', $total));
    }

    public function cancel(Request $request, Mailout $mailout): RedirectResponse
    {
        $this->mailouts->cancel($mailout, $request->user());

        return back()->with('status', __('Wysyłka zatrzymana.'));
    }

    // --- wypisanie się (link w wiadomości, podpisany) -----------------------

    public function unsubscribeForm(User $user): View
    {
        return view('mailouts.unsubscribe', ['user' => $user, 'done' => false]);
    }

    public function unsubscribe(User $user): View
    {
        $user->forceFill(['mailout_opt_out' => true])->save();
        AuditLog::record('account.mailout_opt_out', $user, [], $user);

        return view('mailouts.unsubscribe', ['user' => $user, 'done' => true]);
    }

    private function form(Mailout $mailout, ?User $viewer = null): View
    {
        return view('panel.admin.mailouts.form', [
            'mailout' => $mailout,
            'audiences' => Mailouts::audiences(),
            'hypervisors' => Hypervisor::query()->orderBy('name')->get(['id', 'name']),
            'packages' => VpsPackage::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'recipients' => $mailout->exists ? $this->mailouts->count($mailout) : null,
            'preview' => $mailout->exists && $viewer ? $this->mailouts->render($mailout, $viewer) : null,
        ]);
    }

    /** @return array{subject:string, body:string, audience:array, respect_opt_out:bool} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
            'audience' => ['required', Rule::in(array_keys(Mailouts::audiences()))],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);
        $ids = in_array($data['audience'], ['hypervisors', 'packages', 'products'], true) ? array_values(array_unique(array_map('intval', $data['ids'] ?? []))) : [];
        if (in_array($data['audience'], ['hypervisors', 'packages', 'products'], true) && $ids === []) {
            throw ValidationException::withMessages(['ids' => __('Wybierz co najmniej jedną pozycję.')]);
        }

        return [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'audience' => ['type' => $data['audience'], 'ids' => $ids],
            'respect_opt_out' => ! $request->boolean('service_announcement'),
        ];
    }
}
