@props([
    'class' => 'h-8',
    'href' => null,
])

@php
    $href = $href ?? route('home');
@endphp

<a href="{{ $href }}" {{ $attributes->except('class')->merge(['class' => 'inline-flex items-center flex-shrink-0']) }} aria-label="{{ config('app.name', 'IdeaTub') }}">
    <img src="{{ asset('images/ideatub_logo_exact.svg') }}" alt="" class="{{ $class }}" width="203" height="206" />
</a>
