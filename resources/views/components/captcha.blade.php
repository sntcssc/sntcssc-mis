@props([
    'action' => 'general',
    'options' => [],
])

@if (\App\Services\Captcha\CaptchaService::isEnabled($action))
    {!! \App\Services\Captcha\CaptchaService::render($action, $options) !!}
@endif

