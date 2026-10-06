<?php

namespace App\Http\Controllers\Web;

use App\Domain\Mail\EmailTemplates;
use App\Domain\Mail\TemplateMailer;
use App\Domain\Mail\TemplateRenderer;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** System → Szablony e-mail: treść powiadomień w każdym języku panelu, podgląd i wysyłka testowa. */
class EmailTemplateController extends Controller
{
    public function index(): View
    {
        $overrides = EmailTemplate::query()->get()->groupBy('key');

        return view('panel.admin.emails.index', [
            'groups' => collect(EmailTemplates::definitions())->map(fn ($d, $key) => $d + ['key' => $key])->groupBy('group'),
            'overrides' => $overrides,
        ]);
    }

    public function edit(string $key, string $locale): View
    {
        $this->assertKnown($key, $locale);
        $template = EmailTemplates::resolve($key, $locale);
        $definition = EmailTemplates::definitions()[$key];
        $vars = ['brand' => config('virthub.brand'), 'panel_url' => url('/panel'), 'user' => ['name' => 'Jan Kowalski', 'email' => 'jan@example.com']] + EmailTemplates::sample();

        return view('panel.admin.emails.edit', [
            'key' => $key,
            'locale' => $locale,
            'definition' => $definition,
            'template' => $template,
            'variables' => EmailTemplates::variables($key),
            'previewSubject' => TemplateRenderer::subject(old('subject', $template['subject']), $vars),
            'previewHtml' => view('emails.templated', ['body' => TemplateRenderer::html(old('body', $template['body']), $vars), 'brand' => config('virthub.brand')])->render(),
        ]);
    }

    public function update(Request $request, string $key, string $locale): RedirectResponse
    {
        $this->assertKnown($key, $locale);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        EmailTemplate::query()->updateOrCreate(['key' => $key, 'locale' => $locale], $data + ['enabled' => $request->boolean('enabled')]);
        AuditLog::record('email_template.updated', null, ['key' => $key, 'locale' => $locale, 'enabled' => $request->boolean('enabled')], $request->user());

        return redirect()->route('panel.admin.emails.edit', [$key, $locale])->with('status', __('Szablon zapisany.'));
    }

    public function reset(Request $request, string $key, string $locale): RedirectResponse
    {
        $this->assertKnown($key, $locale);
        EmailTemplate::query()->where('key', $key)->where('locale', $locale)->delete();
        AuditLog::record('email_template.reset', null, ['key' => $key, 'locale' => $locale], $request->user());

        return redirect()->route('panel.admin.emails.edit', [$key, $locale])->with('status', __('Przywrócono domyślną treść szablonu.'));
    }

    public function test(Request $request, string $key, string $locale, TemplateMailer $mailer): RedirectResponse
    {
        $this->assertKnown($key, $locale);
        $sent = $mailer->send($request->user(), $key, EmailTemplates::sample(), $locale);

        return back()->with('status', $sent
            ? __('Wysłano wiadomość testową na :email.', ['email' => $request->user()->email])
            : __('Szablon jest wyłączony albo poczta nie działa — wiadomość nie została wysłana.'));
    }

    private function assertKnown(string $key, string $locale): void
    {
        abort_unless(EmailTemplates::exists($key) && in_array($locale, EmailTemplates::locales(), true), 404);
    }
}
