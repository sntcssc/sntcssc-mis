<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Online Meeting Invitation') }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f5; margin: 0; padding: 24px; color: #18181b;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #e4e4e7; overflow: hidden; padding: 32px;">
        <h2 style="font-size: 20px; font-weight: 700; color: #09090b; margin-top: 0; margin-bottom: 8px;">
            {{ __('Online Meeting Invitation') }}
        </h2>
        <p style="font-size: 14px; color: #71717a; margin-top: 0; margin-bottom: 24px;">
            {{ __(':host has invited you to an online meeting.', ['host' => $host->name]) }}
        </p>

        <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 24px; font-size: 13px; line-height: 1.6;">
            <p style="margin: 0 0 6px 0;"><strong>{{ __('Topic:') }}</strong> {{ $meeting->title }}</p>
            <p style="margin: 0 0 6px 0;"><strong>{{ __('Host:') }}</strong> {{ $host->name }}</p>
            <p style="margin: 0 0 6px 0;"><strong>{{ __('Scheduled:') }}</strong> {{ $meeting->formattedScheduledAt() }}</p>
            <p style="margin: 0 0 6px 0;"><strong>{{ __('Duration:') }}</strong> {{ $meeting->formattedDuration() }}</p>
            @if ($meeting->repeat_type && $meeting->repeat_type !== 'none')
                <p style="margin: 0 0 6px 0;"><strong>{{ __('Recurrence:') }}</strong> {{ $meeting->repeatLabel() }}</p>
            @endif
            @if ($meeting->passcode)
                <p style="margin: 0 0 6px 0;"><strong>{{ __('Passcode:') }}</strong> <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{{ $meeting->passcode }}</code></p>
            @endif
            @if ($meeting->description)
                <p style="margin: 8px 0 0 0; color: #64748b;">{{ $meeting->description }}</p>
            @endif
        </div>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $joinUrl }}" style="display: inline-block; background-color: #0284c7; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 12px 28px; border-radius: 10px;">
                {{ __('Join Meeting') }}
            </a>
        </div>

        <p style="font-size: 12px; color: #a1a1aa; text-align: center; margin-bottom: 0;">
            {{ __('Or paste this URL in your browser:') }}<br>
            <a href="{{ $joinUrl }}" style="color: #0284c7; word-break: break-all;">{{ $joinUrl }}</a>
        </p>
    </div>
</body>
</html>
