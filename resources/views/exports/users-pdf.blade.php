<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 15mm 10mm;
            size: A4 landscape;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1e293b;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 2px solid #0f766e;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .institution-title {
            font-size: 16px;
            font-weight: bold;
            color: #0f766e;
            margin: 0 0 2px 0;
        }
        .report-title {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin: 0;
        }
        .meta {
            font-size: 9px;
            color: #64748b;
            margin-top: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: 700;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
        }
        td {
            border: 1px solid #e2e8f0;
            padding: 5px 6px;
            font-size: 9px;
            vertical-align: middle;
        }
        tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #f1f5f9; color: #475569; }
        .badge-locked { background: #ffe4e6; color: #9f1239; }
        .badge-role { background: #e0e7ff; color: #3730a3; }
        .footer {
            margin-top: 15px;
            border-top: 1px solid #cbd5e1;
            padding-top: 6px;
            font-size: 8px;
            color: #94a3b8;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="institution-title">Satyendra Nath Tagore Civil Services Study Centre</h1>
        <h2 class="report-title">{{ $title }} (Total: {{ $totalCount }} users)</h2>
        <div class="meta">Exported on {{ $generatedAt }} | System Generated Report</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 110px;">URN</th>
                <th style="width: 140px;">Name</th>
                <th style="width: 160px;">Email</th>
                <th style="width: 90px;">Phone</th>
                <th style="width: 80px;">Role</th>
                <th style="width: 90px;">Designation</th>
                <th style="width: 60px;">Status</th>
                <th style="width: 100px;">Last Login</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td style="font-family: monospace; font-size: 8.5px;">{{ $user->urn ?? '—' }}</td>
                    <td><strong>{{ $user->name }}</strong></td>
                    <td style="color: #0f766e;">{{ $user->email }}</td>
                    <td>{{ $user->phone ?? ($user->whatsapp_no ?? '—') }}</td>
                    <td>
                        <span class="badge badge-role">{{ $user->roles->pluck('name')->first() ?? 'Staff' }}</span>
                    </td>
                    <td>{{ $user->designation ?? '—' }}</td>
                    <td>
                        @php($statusClass = match(strtolower((string)$user->status)) { 'active' => 'badge-active', 'locked' => 'badge-locked', default => 'badge-inactive' })
                        <span class="badge {{ $statusClass }}">{{ $user->status }}</span>
                    </td>
                    <td style="font-size: 8px; color: #64748b;">
                        {{ $user->last_login_at ? $user->last_login_at->format('d M Y H:i') : 'Never' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center; padding: 20px; color: #94a3b8;">No users found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Confidential Document &bull; SNTCSSC Management Information System &bull; Page 1
    </div>
</body>
</html>
