<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .meta { color: #6b7280; font-size: 9px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #ecfdf5; color: #047857; text-align: left; padding: 6px 8px; border: 1px solid #d1d5db; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        td { padding: 6px 8px; border: 1px solid #e5e7eb; word-break: break-word; }
        tr:nth-child(even) td { background: #f9fafb; }
        .empty { padding: 24px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ __('Generated') }}: {{ $generatedAt->format('d M Y, h:i A') }}</p>

    @if (count($rows) === 0)
        <p class="empty">{{ __('No records found.') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
