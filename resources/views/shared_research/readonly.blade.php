<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ Str::limit($root->content, 50) }}</title>
</head>
<body>
    <pre>{{ e($root->content) }}</pre>
</body>
</html>
