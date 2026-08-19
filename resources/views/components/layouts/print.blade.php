@props(['title'])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="print-preview">
    {{ $slot }}
</body>
</html>
