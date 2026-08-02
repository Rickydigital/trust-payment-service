@php
    $company = config('company', []);
    $brandName = $company['legal_name'] ?? $company['name'] ?? config('app.name');
    $brandColor = $company['brand_color'] ?? '#08A65A';
    $website = $company['website'] ?? null;
    $supportEmail = $company['support_email'] ?? null;
    $supportPhone = $company['support_phone'] ?? null;
    $address = $company['address'] ?? null;
    $footerNote = $company['footer_note'] ?? null;
@endphp
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
@if($footerNote)
<p>{{ $footerNote }}</p>
@endif
<p>&copy; {{ date('Y') }} {{ $brandName }}. All rights reserved.</p>
<p>
@if($address) {{ $address }} @endif
@if($supportPhone) &nbsp;|&nbsp; {{ $supportPhone }} @endif
@if($supportEmail) &nbsp;|&nbsp; <a href="mailto:{{ $supportEmail }}" style="color:{{ $brandColor }};">{{ $supportEmail }}</a> @endif
@if($website) &nbsp;|&nbsp; <a href="{{ $website }}" style="color:{{ $brandColor }};">{{ parse_url($website, PHP_URL_HOST) ?: $website }}</a> @endif
</p>
</td>
</tr>
</table>
</td>
</tr>
