<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contract draft — {{ $dealName }}</title>
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
                            Contract draft for {{ $dealName }}
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 36px 0;font-size:15px;line-height:1.55;color:#333;">
                        <p style="margin:0 0 14px;">
                            Dear {{ $contactName ?: 'team' }},
                        </p>
                        <p style="margin:0 0 14px;">
                            Please find attached the draft service agreement between
                            <strong>{{ $providerName }}</strong> and <strong>{{ $clientName ?: $dealName }}</strong>
                            for your review.
                        </p>

                        @if($personalMessage)
                            <div style="background:#fafafa;border-left:3px solid #6366f1;padding:12px 16px;margin:16px 0;font-size:14px;color:#444;">
                                {!! nl2br(e($personalMessage)) !!}
                            </div>
                        @endif

                        <div style="background:#f7f7f9;border:1px solid #e5e5ea;border-radius:6px;padding:14px 18px;margin:16px 0;">
                            <table cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:14px;">
                                @if($monthlyFee)
                                    <tr>
                                        <td style="padding:3px 0;color:#666;width:160px;">Monthly fee</td>
                                        <td style="padding:3px 0;color:#171717;font-weight:600;">
                                            @if($currency){{ $currency }} @endif{{ number_format((float) $monthlyFee, 2) }}
                                        </td>
                                    </tr>
                                @endif
                                @if($months)
                                    <tr>
                                        <td style="padding:3px 0;color:#666;">Contract term</td>
                                        <td style="padding:3px 0;color:#171717;font-weight:600;">{{ $months }} months</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:3px 0;color:#666;">Draft version</td>
                                    <td style="padding:3px 0;color:#171717;">v{{ $draftVersion }}</td>
                                </tr>
                            </table>
                        </div>

                        <p style="margin:14px 0;">
                            <strong>Next step:</strong> review the attached PDF and reply to this email
                            with the counter-signed copy attached. Once we receive it, we'll
                            counter-sign on our side and the agreement will be active.
                        </p>

                        <p style="margin:14px 0;">
                            If you have any questions or want adjustments to specific clauses, just
                            reply to this email — it goes straight to
                            @if($senderEmail)
                                {{ $senderName ?: $senderEmail }}.
                            @else
                                our team.
                            @endif
                        </p>

                        <p style="margin:22px 0 0;">
                            Best regards,<br>
                            <strong>{{ $senderName ?: $providerName }}</strong>
                            @if($senderName)
                                <br><span style="color:#777;font-size:13px;">{{ $providerName }}</span>
                            @endif
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 36px 32px;">
                        <div style="border-top:1px solid #ececf0;padding-top:14px;font-size:12px;color:#999;text-align:center;">
                            This message and its attachment are confidential and intended for the
                            named recipient. If you received this in error, please notify the sender
                            and delete the message.
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
