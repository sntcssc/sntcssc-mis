<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Roles & Permissions Matrix</title>
    <style>
        @page {
            margin: 15mm 10mm;
            size: A4 landscape;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9px;
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
            font-size: 8.5px;
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
            padding: 4px 5px;
            text-align: left;
        }
        td {
            border: 1px solid #e2e8f0;
            padding: 4px 5px;
            font-size: 8.5px;
            vertical-align: middle;
        }
        tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .check {
            color: #059669;
            font-weight: bold;
            text-align: center;
        }
        .cross {
            color: #cbd5e1;
            text-align: center;
        }
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
        <h2 class="report-title">Role-Based Access Control (RBAC) Permissions Matrix</h2>
        <div class="meta">Exported on {{ $generatedAt }} | System Generated Matrix</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 80px;">Module</th>
                <th style="width: 130px;">Permission</th>
                <th style="width: 180px;">Description</th>
                @foreach ($roles as $role)
                    <th style="text-align: center; font-size: 7.5px;">{{ $role->name }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($permissions as $module => $modulePerms)
                @foreach ($modulePerms as $index => $perm)
                    <tr>
                        @if ($index === 0)
                            <td rowspan="{{ $modulePerms->count() }}" style="font-weight: bold; background: #f8fafc; vertical-align: top;">
                                {{ $module }}
                            </td>
                        @endif
                        <td style="font-family: monospace; font-size: 8px; color: #0f766e;">{{ $perm->name }}</td>
                        <td style="color: #64748b;">{{ $perm->description }}</td>
                        @foreach ($roles as $role)
                            <td style="text-align: center;">
                                @if ($role->hasPermissionTo($perm->name, 'web'))
                                    <span class="check">&#10004;</span>
                                @else
                                    <span class="cross">&mdash;</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Confidential Document &bull; SNTCSSC Management Information System &bull; RBAC Audit Report
    </div>
</body>
</html>
