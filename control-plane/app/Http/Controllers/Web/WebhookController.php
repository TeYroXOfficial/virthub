<?php

namespace App\Http\Controllers\Web;

use App\Domain\Webhooks\WebhookFormats;
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
        $data = $this->validated($request, $webhook);
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

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Webhook $existing = null): array
    {
        $format = (string) ($request->input('format') ?: 'virthub');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(['*', ...array_keys(Webhooks::events())])],
            'format' => ['nullable', Rule::in(WebhookFormats::FORMATS)],
            'bearer_token' => ['nullable', 'string', 'max:500', 'regex:/^[\x21-\x7e]+$/'],
            'clear_bearer' => ['nullable', 'boolean'],
            'options.chat_id' => [$format === 'telegram' ? 'required' : 'nullable', 'string', 'max:64', 'regex:/^-?[0-9]+$|^@[A-Za-z0-9_]{5,64}$/'],
            'options.user_key' => [$format === 'pushover' ? 'required' : 'nullable', 'string', 'max:64', 'alpha_num'],
            'options.app_token' => [$format === 'pushover' && ! ($existing?->options['app_token'] ?? null) ? 'required' : 'nullable', 'string', 'max:64', 'alpha_num'],
        ], ['bearer_token.regex' => __('Token może zawierać tylko widoczne znaki ASCII, bez spacji.')]);
        $events = array_values(array_unique($data['events']));
        $old = (array) $existing?->options;
        $options = match ($format) {
            'telegram' => ['chat_id' => $data['options']['chat_id']],
            'pushover' => ['user_key' => $data['options']['user_key'], 'app_token' => ($data['options']['app_token'] ?? null) ?: ($old['app_token'] ?? null)],
            default => null,
        };
        $bearer = $request->boolean('clear_bearer') ? null : (($data['bearer_token'] ?? null) ?: $existing?->bearer_token);

        return ['name' => $data['name'], 'url' => $data['url'], 'events' => in_array('*', $events, true) ? ['*'] : $events,
            'format' => $format, 'options' => $options, 'bearer_token' => $bearer];
    }
}
