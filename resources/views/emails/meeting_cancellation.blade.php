<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Meeting Cancelled') }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f5; margin: 0; padding: 24px; color: #18181b;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #e4e4e7; overflow: hidden; padding: 32px;">
        <h2 style="font-size: 20px; font-weight: 700; color: #e11d48; margin-top: 0; margin-bottom: 8px;">
            {{ __('Meeting Cancelled') }}
        </h2>
        <p style="font-size: 14px; color: #71717a; margin-top: 0; margin-bottom: 24px;">
            {{ __('The following online meeting has been cancelled by :actor.', ['actor' => $actor->name]) }}
        </p>

        <div style="background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 12px; padding: 16px; margin-bottom: 24px; font-size: 13px; line-height: 1.6;">
            <p style="margin: 0 0 6px 0;"><strong>{{ __('Topic:') }}</strong> {{ $meeting->title }}</p>
            <p style="margin: 0 0 6px 0;"><strong>{{ __('Scheduled Time:') }}</strong> {{ $meeting->formattedScheduledAt() }}</p>
            @if (!empty($reason))
                <p style="margin: 6px 0 0 0; color: #9f1239;"><strong>{{ __('Reason for Cancellation:') }}</strong> {{ $reason }}</p>
            @endif
        </div>

        <p style="font-size: 13px; color: #71717a; text-align: center; margin-bottom: 0;">
            {{ __('For any questions or new schedule updates, please check the system portal.') }}
        </p>
    </div>
</body>
</html>
