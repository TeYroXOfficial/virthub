<?php

namespace App\Http\Controllers\Web;

use App\Domain\Webhooks\Webhooks;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administracja → Webhooki. */
class WebhookController extends Controller
{
    public function __construct(private readonly Webhooks $webhooks) {}

    public function index(): View
    {
        return view('panel.admin.webhooks.index', [
            'webhooks' => Webhook::query()->withCount([
                'deliveries as failed_count' => fn ($q) => $q->where('status', WebhookDelivery::FAILED),
                'deliveries as pending_count' => fn ($q) => $q->where('status', WebhookDelivery::PENDING),
            ])->orderBy('name')->get(),
            'events' => Webhooks::events(),
        ]);
    }

    public function show(Webhook $webhook): View
    {
        return view('panel.admin.webhooks.show', [
            'webhook' => $webhook,
            'events' => Webhooks::events(),
            'deliveries' => $webhook->deliveries()->latest('id')->paginate(30),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $secret = Webhooks::newSecret();
        $webhook = Webhook::create($data + ['secret' => $secret, 'is_active' => true]);
        $this->webhooks->forget();
        AuditLog::record('webhook.created', $webhook, ['url' => $webhook->url]);

        return redirect()->route('panel.admin.webhooks.show', $webhook)
            ->with('status', __('Webhook dodany. Skopiuj sekret podpisu — nie zobaczysz go ponownie.'))
            ->with('webhook_secret', $secret);
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $data = $this->validated($request);
        $active = $request->boolean('is_active');
        $webhook->fill($data + ['is_active' => $active]);
        if ($active) {
            $webhook->consecutive_failures = 0;
        }
        $webhook->save();
        $this->webhooks->forget();
        AuditLog::record('webhook.updated', $webhook, ['url' => $webhook->url, 'active' => $active]);

        return back()->with('status', __('Zapisano webhook.'));
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        AuditLog::record('webhook.deleted', $webhook, ['url' => $webhook->url]);
        $webhook->delete();
        $this->webhooks->forget();

        return redirect()->route('panel.admin.webhooks')->with('status', __('Webhook usunięty.'));
    }

    public function ping(Webhook $webhook): RedirectResponse
    {
        $delivery = $this->webhooks->ping($webhook);

        return back()->with('status', $delivery->status === WebhookDelivery::SUCCESS
            ? __('Test dostarczony (HTTP :code, :ms ms).', ['code' => $delivery->response_code, 'ms' => $delivery->duration_ms])
            : __('Test nieudany: :error', ['error' => $delivery->error]));
    }

    public function rotateSecret(Webhook $webhook): RedirectResponse
    {
        $secret = Webhooks::newSecret();
        $webhook->forceFill(['secret' => $secret])->save();
        AuditLog::record('webhook.secret_rotated', $webhook);

        return back()->with('status', __('Nowy sekret podpisu — zaktualizuj go u odbiorcy.'))->with('webhook_secret', $secret);
    }

    public function redeliver(WebhookDelivery $delivery): RedirectResponse
    {
        $copy = $this->webhooks->redeliver($delivery);

        return back()->with('status', $copy->status === WebhookDelivery::SUCCESS
            ? __('Wysłano ponownie (HTTP :code).', ['code' => $copy->response_code])
            : __('Ponowne wysłanie nieudane: :error', ['error' => $copy->error]));
    }

    /** @return array{name:string, url:string, events:list<string>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(['*', ...array_keys(Webhooks::events())])],
        ]);
        $events = array_values(array_unique($data['events']));

        return ['name' => $data['name'], 'url' => $data['url'], 'events' => in_array('*', $events, true) ? ['*'] : $events];
    }
}
