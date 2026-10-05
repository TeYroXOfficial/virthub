<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color:#0f1a26;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">
            <tr><td style="padding:0 4px 16px; font-size:18px; font-weight:700; color:#0e8f80;">{{ $brand }}</td></tr>
            <tr><td style="background:#ffffff; border:1px solid #e1e6eb; border-radius:12px; padding:28px; font-size:15px; line-height:1.6;">
                {{-- Treść wyrenderowana przez TemplateRenderer: Markdown z escapowanymi zmiennymi i surowym HTML. --}}
                <div class="vh-mail">{!! $body !!}</div>
            </td></tr>
            <tr><td style="padding:16px 4px; font-size:12px; color:#677789;">
                {{ __('Wiadomość wysłana automatycznie przez panel :brand.', ['brand' => $brand]) }}
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
