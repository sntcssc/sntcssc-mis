<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? \App\Models\Setting::appName() }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 24px 12px; color: #1e293b;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
        <!-- Header -->
        <tr>
            <td style="background-color: #0f172a; padding: 24px 32px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.025em;">
                    {{ \App\Models\Setting::appName() }}
                </h1>
            </td>
        </tr>

        <!-- Content -->
        <tr>
            <td style="padding: 32px 32px 24px 32px;">
                <h2 style="margin: 0 0 16px 0; color: #0f172a; font-size: 18px; font-weight: 600;">
                    {{ $title }}
                </h2>

                <div style="font-size: 15px; line-height: 1.6; color: #334155; margin-bottom: 24px;">
                    {!! nl2br(e($body)) !!}
                </div>

                @if(!empty($actionUrl))
                    <div style="margin: 28px 0; text-align: center;">
                        <a href="{{ $actionUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);">
                            {{ $actionLabel ?? __('View in Portal') }}
                        </a>
                    </div>
                @endif
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background-color: #f8fafc; padding: 20px 32px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #64748b;">
                <p style="margin: 0 0 6px 0;">
                    {{ \App\Models\Setting::copyrightText() }}
                </p>
                <p style="margin: 0;">
                    {{ __('You received this automated notification from :app.', ['app' => \App\Models\Setting::appName()]) }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
