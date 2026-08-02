@php
    $company = array_replace(config('company', []), array_filter($brand ?? [], static fn ($value) => filled($value)));
    $brandName = $company['name'] ?? config('app.name', 'Trada');
    $legalName = $company['legal_name'] ?? $brandName;
    $tagline = $company['tagline'] ?? null;
    $brandColor = $company['brand_color'] ?? '#08A65A';
    $accentColor = $company['accent_color'] ?? '#052E25';
    $emailBackground = $company['email_background'] ?? '#F4F8F5';
    $buttonTextColor = $company['button_text_color'] ?? '#FFFFFF';
    $logoUrl = $company['logo_url'] ?? null;
    $logoPath = $company['logo_path'] ?? 'images/trada-mail-logo.png';
    $logoFile = public_path(ltrim((string) $logoPath, '/\\'));
    $logoSrc = $logoUrl;
    if (isset($message) && is_object($message) && method_exists($message, 'embed') && is_file($logoFile)) {
        try { $logoSrc = $message->embed($logoFile); } catch (\Throwable) { $logoSrc = $logoUrl; }
    }
    $website = $company['website'] ?? null;
    $supportEmail = $company['support_email'] ?? null;
    $supportPhone = $company['support_phone'] ?? null;
    $address = $company['address'] ?? null;
    $footerNote = $company['footer_note'] ?? null;
    $title = $title ?? $subject ?? config('app.name', 'Trada');
    $preheader = $preheader ?? \Illuminate\Support\Str::limit(strip_tags((string) ($body ?? $bodyHtml ?? $title)), 130);
    $meta = collect($meta ?? [])->filter(fn ($value) => filled($value));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;background:{{ $emailBackground }};color:#172033;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $emailBackground }};margin:0;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;border-collapse:collapse;">
                    <tr>
                        <td style="background:{{ $accentColor }};border-top:4px solid {{ $brandColor }};border-radius:22px 22px 0 0;padding:22px 28px;color:#ffffff;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td width="60" style="vertical-align:middle;">
                                        @if($logoSrc)
                                            <img src="{{ $logoSrc }}" width="48" height="48" alt="{{ $brandName }}" style="width:48px;height:48px;border-radius:12px;display:block;border:0;object-fit:contain;">
                                        @else
                                            <div style="width:48px;height:48px;line-height:48px;text-align:center;border-radius:12px;background:{{ $brandColor }};font-size:22px;font-weight:900;">{{ \Illuminate\Support\Str::of($brandName)->substr(0, 1)->upper() }}</div>
                                        @endif
                                    </td>
                                    <td style="vertical-align:middle;">
                                        <div style="font-size:23px;font-weight:800;letter-spacing:.2px;">{{ $brandName }}</div>
                                        @if($tagline)
                                            <div style="font-size:12px;line-height:1.5;opacity:.78;margin-top:5px;">{{ $tagline }}</div>
                                        @endif
                                    </td>
                                    <td align="right" style="vertical-align:middle;">
                                        <span style="display:inline-block;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.1);border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700;">Official</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#ffffff;border:1px solid #dfe7f1;border-top:0;border-radius:0 0 22px 22px;overflow:hidden;">
                            @if(!empty($imageUrl))
                                <img src="{{ $imageUrl }}" alt="{{ $title }}" style="width:100%;max-height:360px;object-fit:cover;display:block;border:0;">
                            @endif

                            <div style="padding:32px 30px;">
                                <h1 style="margin:0 0 16px;color:#111827;font-size:25px;line-height:1.24;font-weight:800;">{{ $title }}</h1>

                                @if($meta->isNotEmpty())
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;">
                                        @foreach($meta as $label => $value)
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid #eef2f7;color:#64748b;font-size:13px;width:34%;">{{ \Illuminate\Support\Str::headline($label) }}</td>
                                                <td style="padding:8px 0;border-bottom:1px solid #eef2f7;color:#172033;font-size:13px;font-weight:700;">{{ $value }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                @endif

                                @if(!empty($bodyHtml))
                                    <div style="font-size:16px;line-height:1.75;color:#334155;">{!! $bodyHtml !!}</div>
                                @elseif(!empty($lines))
                                    <div style="font-size:16px;line-height:1.75;color:#334155;">
                                        @foreach($lines as $line)
                                            <p style="margin:0 0 14px;">{{ $line }}</p>
                                        @endforeach
                                    </div>
                                @else
                                    <div style="font-size:16px;line-height:1.75;color:#334155;">{!! nl2br(e((string) ($body ?? ''))) !!}</div>
                                @endif

                                @if(!empty($actionUrl))
                                    <div style="margin-top:28px;">
                                        <a href="{{ $actionUrl }}" style="display:inline-block;background:{{ $brandColor }};color:{{ $buttonTextColor }};text-decoration:none;padding:14px 22px;border-radius:12px;font-size:14px;font-weight:800;">
                                            {{ $actionText ?? 'Open' }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding:18px 24px 0;color:#6b7280;font-size:12px;line-height:1.65;">
                            @if($footerNote)
                                <div style="margin-bottom:8px;">{{ $footerNote }}</div>
                            @endif
                            <div>&copy; {{ date('Y') }} {{ $legalName }}. All rights reserved.</div>
                            <div>
                                @if($address) {{ $address }} @endif
                                @if($supportPhone) &nbsp;|&nbsp; {{ $supportPhone }} @endif
                                @if($supportEmail) &nbsp;|&nbsp; <a href="mailto:{{ $supportEmail }}" style="color:{{ $brandColor }};text-decoration:none;">{{ $supportEmail }}</a> @endif
                                @if($website) &nbsp;|&nbsp; <a href="{{ $website }}" style="color:{{ $brandColor }};text-decoration:none;">{{ parse_url($website, PHP_URL_HOST) ?: $website }}</a> @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
