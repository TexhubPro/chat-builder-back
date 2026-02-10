<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Verification Code</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:24px;border:1px solid #e5e7eb;">
                <tr>
                    <td style="font-size:20px;font-weight:700;padding-bottom:12px;">
                        Email verification
                    </td>
                </tr>
                <tr>
                    <td style="font-size:15px;line-height:1.6;padding-bottom:16px;">
                        Hi {{ $name }},<br>
                        Use this 6-digit verification code to complete your registration:
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:8px 0 18px;">
                        <div style="display:inline-block;padding:12px 20px;border-radius:10px;background:#111827;color:#ffffff;font-size:28px;letter-spacing:6px;font-weight:700;">
                            {{ $code }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:14px;line-height:1.6;color:#374151;padding-bottom:10px;">
                        The code expires in {{ $expiresInMinutes }} minutes.
                    </td>
                </tr>
                <tr>
                    <td style="font-size:13px;line-height:1.6;color:#6b7280;">
                        If you did not request this code, you can safely ignore this email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
