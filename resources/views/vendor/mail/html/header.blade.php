@props(['url'])
@php
    $company = config('company', []);
    $brandName = $company['name'] ?? config('app.name');
    $tagline = $company['tagline'] ?? null;
    $logoUrl = $company['logo_url'] ?? null;
    $logoPath = $company['logo_path'] ?? 'images/trada-mail-logo.png';
    $logoFile = public_path(ltrim((string) $logoPath, '/\\'));
    $logoSrc = $logoUrl;
    if (isset($message) && is_object($message) && method_exists($message, 'embed') && is_file($logoFile)) {
        try { $logoSrc = $message->embed($logoFile); } catch (\Throwable) { $logoSrc = $logoUrl; }
    }
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display:inline-block;text-decoration:none;">
@if($logoSrc)
<img src="{{ $logoSrc }}" class="logo" width="46" height="46" alt="{{ $brandName }}">
@else
<span class="brand-mark">{{ \Illuminate\Support\Str::of($brandName)->substr(0, 1)->upper() }}</span>
@endif
<span class="brand-name" style="margin-left:10px;">{{ $brandName }}</span>
</a>
@if($tagline)
<div class="brand-tagline">{{ $tagline }}</div>
@endif
</td>
</tr>
