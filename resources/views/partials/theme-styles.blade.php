@php
    $themeCss = \App\Support\ThemePresets::generateCss();
@endphp
<style id="app-dynamic-theme">
{!! $themeCss !!}
</style>
