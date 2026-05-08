@props([
    'markdown' => '',
])

@php
    $html = \App\Support\SafeCommonMarkConverter::toHtml($markdown);
@endphp

<div {{ $attributes }}>
    {!! $html !!}
</div>
