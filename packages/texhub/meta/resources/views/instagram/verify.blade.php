<!doctype html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instagram Verify</title>
</head>

<body>
    @php
        $host = request()->getSchemeAndHttpHost();
        $webhookPath = '/' . ltrim((string) config('meta.instagram.webhook_path', '/instagram-main-webhook'), '/');
        $redirectPath = '/' . ltrim((string) config('meta.instagram.redirect_path', '/callback'), '/');
        $token = trim((string) ($verify_token ?? config('meta.instagram.verify_token', '')));
    @endphp

    Домен: {{ $host }} <br>
    Token: {{ $token !== '' ? $token : '(пусто)' }} <br>
    Webhook: {{ $host . $webhookPath }} <br>
    Callback: {{ $host . $redirectPath }} <br>
</body>

</html>
