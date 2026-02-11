<!doctype html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instagram Verify</title>
</head>

<body>
    Домен: {{ request()->getSchemeAndHttpHost() }} <br>
    Имя сайта: {{ config('app.name') }} <br>
    Webhook: {{ request()->getSchemeAndHttpHost() }}/instagram-main-webhook <br>
    Callback: {{ request()->getSchemeAndHttpHost() }}/callback <br>
</body>

</html>
