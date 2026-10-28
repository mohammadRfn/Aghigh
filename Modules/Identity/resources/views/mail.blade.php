<!doctype html>
<html lang="en">
<body style="font-family: sans-serif; line-height: 1.5;">
    <h1>{{ $title }}</h1>
    <p>{{ $text }}</p>
    @if ($url)
        <p><a href="{{ $url }}">{{ $url }}</a></p>
    @endif
</body>
</html>
