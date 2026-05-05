<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Anka</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #334155; background: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .header { background: #0f172a; padding: 32px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; }
        .header p { color: #94a3b8; margin: 8px 0 0; font-size: 14px; }
        .content { padding: 32px; }
        .credentials { background: #f1f5f9; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .credentials p { margin: 6px 0; font-size: 14px; }
        .credentials strong { color: #0f172a; }
        .btn { display: inline-block; background: #0f172a; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 8px; }
        .footer { padding: 24px 32px; background: #f8fafc; text-align: center; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Welcome to Anka</h1>
            <p>Your agency management platform</p>
        </div>
        <div class="content">
            <p>Hi {{ $name }},</p>
            <p>Your account for <strong>{{ $tenantName }}</strong> has been created. You can now log in and start managing your organization.</p>

            <div class="credentials">
                <p><strong>Email:</strong> {{ $email }}</p>
                <p><strong>Password:</strong> {{ $password }}</p>
            </div>

            <p style="text-align: center;">
                <a href="{{ $loginUrl }}" class="btn">Log In to Anka</a>
            </p>

            <p style="font-size: 13px; color: #64748b; margin-top: 24px;">
                For security, we recommend changing your password after your first login.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Anka Platform. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
