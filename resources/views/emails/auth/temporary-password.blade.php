<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Temporary Password</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:24px;border:1px solid #e5e7eb;">
                <tr>
                    <td style="font-size:20px;font-weight:700;padding-bottom:12px;">
                        Password reset
                    </td>
                </tr>
                <tr>
                    <td style="font-size:15px;line-height:1.6;padding-bottom:16px;">
                        Hi {{ $name }},<br>
                        Your password was reset. Use this temporary password to sign in:
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:8px 0 18px;">
                        <div style="display:inline-block;padding:12px 20px;border-radius:10px;background:#111827;color:#ffffff;font-size:20px;letter-spacing:1px;font-weight:700;">
                            {{ $temporaryPassword }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:14px;line-height:1.6;color:#374151;padding-bottom:10px;">
                        For security, all active sessions were logged out.
                    </td>
                </tr>
                <tr>
                    <td style="font-size:13px;line-height:1.6;color:#6b7280;">
                        Please sign in and change this temporary password in your profile settings.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
