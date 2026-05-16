<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #334155; background: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .header { background: #0f172a; padding: 28px 32px; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; }
        .header p { color: #94a3b8; margin: 6px 0 0; font-size: 13px; }
        .content { padding: 28px 32px; }
        .summary { background: #f1f5f9; border-radius: 8px; padding: 20px 24px; margin: 20px 0; }
        .summary .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
        .summary .row.total { padding-top: 12px; border-top: 1px solid #cbd5e1; margin-top: 10px; font-size: 16px; font-weight: 700; color: #0f172a; }
        .summary .label { color: #64748b; }
        .summary .value { color: #0f172a; font-weight: 500; }
        .meta { font-size: 13px; color: #64748b; margin: 16px 0; }
        .meta strong { color: #0f172a; }
        .footer { padding: 20px 32px; background: #f8fafc; text-align: center; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Invoice {{ $invoiceNumber }}</h1>
            <p>{{ $agencyName }}</p>
        </div>
        <div class="content">
            <p>Hi {{ $clientName }},</p>
            <p>Please find the details of your invoice below.</p>

            <div class="meta">
                @if($contractNumber)<div>Contract: <strong>{{ $contractNumber }}</strong></div>@endif
                @if($poNumber)<div>PO Number: <strong>{{ $poNumber }}</strong></div>@endif
                <div>Issue date: <strong>{{ $issueDate }}</strong></div>
                @if($dueDate)<div>Due date: <strong>{{ $dueDate }}</strong></div>@endif
            </div>

            <div class="summary">
                <div class="row"><span class="label">Subtotal</span><span class="value">{{ $currency }} {{ $amount }}</span></div>
                <div class="row"><span class="label">Tax</span><span class="value">{{ $currency }} {{ $tax }}</span></div>
                <div class="row total"><span>Total</span><span>{{ $currency }} {{ $total }}</span></div>
            </div>

            @if($notes)
                <p style="font-size:13px;color:#64748b;"><strong>Notes:</strong> {{ $notes }}</p>
            @endif

            <p style="margin-top:24px;font-size:13px;color:#64748b;">
                If you have questions about this invoice, please reply to this email.
            </p>
        </div>
        <div class="footer">
            Sent by {{ $agencyName }} via Anka
        </div>
    </div>
</body>
</html>
