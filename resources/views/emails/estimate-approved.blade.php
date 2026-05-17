<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Estimate — {{ $dealName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f7;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                <tr>
                    <td style="padding:32px 36px 0;">
                        <div style="font-size:14px;font-weight:600;color:#4a4a4a;letter-spacing:0.5px;text-transform:uppercase;">
                            {{ $providerName }}
                        </div>
                        <h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;color:#171717;">
                            Estimate for {{ $dealName }}
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 36px 0;font-size:15px;line-height:1.55;color:#333;">
                        <p style="margin:0 0 14px;">
                            Dear {{ $contactName ?: 'team' }},
                        </p>
                        <p style="margin:0 0 14px;">
                            Please find attached the estimate for
                            <strong>{{ $dealName }}</strong> prepared by
                            <strong>{{ $providerName }}</strong>
                            @if($clientName) for <strong>{{ $clientName }}</strong>@endif.
                        </p>

                        @if($personalMessage)
                            <div style="background:#fafafa;border-left:3px solid #6366f1;padding:12px 16px;margin:16px 0;font-size:14px;color:#444;">
                                {!! nl2br(e($personalMessage)) !!}
                            </div>
                        @endif

                        @if($monthlyFee || $months)
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin:14px 0;font-size:14px;color:#1e293b;">
                                <strong>Summary:</strong>
                                @if($monthlyFee && $currency)
                                    {{ number_format($monthlyFee) }} {{ $currency }} / month
                                @endif
                                @if($months) over {{ $months }} months @endif
                            </div>
                        @endif

                        <p style="margin:14px 0;">
                            The attached spreadsheet breaks the estimate into
                            (1) overall cost summary, (2) feature list, (3) man-hour
                            detail per phase, (4) milestones, and (5) general team
                            structure. Please review and let us know if anything
                            needs revising.
                        </p>

                        @if($senderName || $senderEmail)
                            <p style="margin:18px 0 8px;">
                                Replies to this email reach
                                {{ $senderName ?: $senderEmail }} directly.
                            </p>
                        @endif

                        <p style="margin:14px 0 0;">
                            Thank you,<br>
                            {{ $providerName }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 36px;font-size:12px;color:#8a8a8a;border-top:1px solid #eee;margin-top:24px;">
                        Estimate version {{ $versionNumber }} · Sent via ANKA
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
