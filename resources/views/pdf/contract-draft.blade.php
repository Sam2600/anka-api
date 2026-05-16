<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $draft->template?->name ?? 'Service Agreement' }} — {{ $deal->name }}</title>
    <style>
        /* Dompdf supports a subset of CSS2 + a few CSS3 features. Keep it simple. */
        @page { margin: 24mm 18mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5pt;
            color: #1a1a1a;
            line-height: 1.45;
        }
        .header {
            border-bottom: 1.5pt solid #333;
            padding-bottom: 10pt;
            margin-bottom: 14pt;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; padding: 0; }
        .header .logo-cell { width: 30%; }
        .header .logo-cell img { max-height: 60pt; max-width: 100%; }
        .header .issuer { text-align: right; }
        .header .issuer .name { font-size: 13pt; font-weight: bold; letter-spacing: 0.3pt; }
        .header .issuer .line { font-size: 9pt; color: #555; }

        h1.contract-title {
            text-align: center;
            font-size: 15pt;
            margin: 18pt 0 4pt;
            text-transform: uppercase;
            letter-spacing: 0.8pt;
        }
        .subtitle {
            text-align: center;
            font-size: 10pt;
            color: #555;
            margin-bottom: 18pt;
        }

        .parties {
            margin-bottom: 14pt;
            background: #f7f7f9;
            border: 1pt solid #e2e2e6;
            padding: 8pt 12pt;
        }
        .parties .label {
            font-weight: bold;
            color: #333;
            display: inline-block;
            min-width: 70pt;
        }
        .parties .row { margin: 2pt 0; }

        section.clause {
            margin: 14pt 0;
            page-break-inside: avoid;
        }
        section.clause h2 {
            font-size: 11.5pt;
            margin: 0 0 6pt;
            padding-bottom: 3pt;
            border-bottom: 0.5pt solid #999;
            text-transform: uppercase;
            letter-spacing: 0.4pt;
        }
        section.clause p { margin: 4pt 0; text-align: justify; }
        section.clause ul { margin: 4pt 0 4pt 14pt; padding: 0; }
        section.clause li { margin: 2pt 0; }
        section.clause .muted { color: #888; font-style: italic; }

        table.pair-table, table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6pt 0;
            font-size: 10pt;
        }
        table.pair-table th, table.data-table th {
            background: #ececf0;
            text-align: left;
            padding: 4pt 6pt;
            border: 0.5pt solid #bbb;
        }
        table.pair-table td, table.data-table td {
            border: 0.5pt solid #bbb;
            padding: 4pt 6pt;
            vertical-align: top;
        }
        table.pair-table td ul { margin: 0 0 0 14pt; }

        pre.raw {
            background: #f4f4f7;
            padding: 6pt;
            border: 0.5pt solid #ccc;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9pt;
            white-space: pre-wrap;
        }

        .signatures {
            margin-top: 24pt;
            page-break-inside: avoid;
        }
        .signatures table { width: 100%; border-collapse: collapse; }
        .signatures td {
            width: 50%;
            padding: 14pt 12pt 0;
            vertical-align: top;
        }
        .signatures .label { font-weight: bold; }
        .signatures .line {
            border-top: 0.7pt solid #555;
            margin-top: 30pt;
            padding-top: 3pt;
            font-size: 10pt;
            color: #222;
            font-weight: 600;
        }
        .signatures .signer-field {
            margin-top: 6pt;
            font-size: 9.5pt;
            color: #333;
        }
        .signatures .signer-label {
            display: inline-block;
            min-width: 56pt;
            color: #666;
        }
        .signatures .signer-value {
            color: #1a1a1a;
        }

        .footer {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #999;
        }
    </style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td class="logo-cell">
                @if($logoDataUri)
                    <img src="{{ $logoDataUri }}" alt="Logo">
                @else
                    <div style="font-size: 9pt; color: #999;">[logo]</div>
                @endif
            </td>
            <td class="issuer">
                <div class="name">{{ $provider['name'] ?? 'Brycen Myanmar Ltd.' }}</div>
                @if(!empty($provider['address']))
                    <div class="line">{{ $provider['address'] }}</div>
                @endif
                @if(!empty($provider['phone']))
                    <div class="line">Tel: {{ $provider['phone'] }}</div>
                @endif
                @if(!empty($provider['email']))
                    <div class="line">{{ $provider['email'] }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>

<h1 class="contract-title">{{ $draft->template?->name ?? 'Service Agreement' }}</h1>
<div class="subtitle">
    Draft v{{ $draft->version }}
    @if($draft->template?->umbrella)
        · {{ $draft->template->umbrella }}
    @endif
    · Generated {{ $generatedAt }}
</div>

<div class="parties">
    <div class="row"><span class="label">Provider:</span>{{ $provider['name'] ?? 'Brycen Myanmar Ltd.' }}</div>
    <div class="row"><span class="label">User:</span>{{ $deal->client ?: $deal->name }}</div>
    @if($deal->contact_name)
        <div class="row"><span class="label">Contact:</span>{{ $deal->contact_name }}@if($deal->contact_email) &lt;{{ $deal->contact_email }}&gt;@endif</div>
    @endif
    @if($deal->contact_phone)
        <div class="row"><span class="label">Phone:</span>{{ $deal->contact_phone }}</div>
    @endif
</div>

@foreach($sections as $section)
    <section class="clause">
        <h2>{{ $section['title'] }}</h2>
        {!! $section['html'] !!}
    </section>
@endforeach

<div class="signatures">
    <table>
        <tr>
            <td>
                <div class="label">Provider</div>
                <div class="line">{{ $provider['name'] ?? 'Provider' }}</div>
                <div class="signer-field"><span class="signer-label">Signed by:</span>
                    <span class="signer-value">{{ $provider['signatory_name'] ?? '____________________' }}</span>
                </div>
                <div class="signer-field"><span class="signer-label">Title:</span>
                    <span class="signer-value">{{ $provider['signatory_title'] ?? '____________________' }}</span>
                </div>
                <div class="signer-field"><span class="signer-label">Date:</span>
                    <span class="signer-value">{{ $providerSignDate }}</span>
                </div>
                <div style="margin-top: 8pt; font-size: 9pt; color: #888;">Signature</div>
            </td>
            <td>
                <div class="label">User</div>
                <div class="line">{{ $deal->client ?: '—' }}</div>
                <div class="signer-field"><span class="signer-label">Signed by:</span>
                    <span class="signer-value">{{ $customerSignerName ?? '____________________' }}</span>
                </div>
                <div class="signer-field"><span class="signer-label">Title:</span>
                    <span class="signer-value">{{ $customerSignerTitle ?? '____________________' }}</span>
                </div>
                <div class="signer-field"><span class="signer-label">Date:</span>
                    <span class="signer-value">____________________</span>
                </div>
                <div style="margin-top: 8pt; font-size: 9pt; color: #888;">Signature</div>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    {{ $provider['name'] ?? 'Brycen Myanmar Ltd.' }} · Draft v{{ $draft->version }} · Confidential
</div>

</body>
</html>
