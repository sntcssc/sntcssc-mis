<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Soft Copies Documents Register' }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 10mm 12mm 10mm;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: #1e293b;
            line-height: 1.35;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header table {
            width: 100%;
            border-collapse: collapse;
        }
        .institute-title {
            font-size: 14px;
            font-weight: bold;
            color: #1e3a8a;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sub-title {
            font-size: 9px;
            color: #475569;
            margin: 2px 0 0 0;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 5px;
        }
        .meta-box {
            text-align: right;
            font-size: 8px;
            color: #64748b;
        }
        .meta-box strong {
            color: #1e293b;
        }
        .badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-pdf { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-word { background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-excel { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-ppt { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-image { background-color: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-zip { background-color: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .badge-version { background-color: #1e3a8a; color: #ffffff; padding: 2px 6px; border-radius: 9999px; }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.data-table th {
            background-color: #0f172a;
            color: #ffffff;
            font-size: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 6px 5px;
            text-align: left;
            border: 1px solid #0f172a;
        }
        table.data-table td {
            padding: 5px 5px;
            border: 1px solid #cbd5e1;
            font-size: 8px;
            vertical-align: top;
        }
        table.data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 7.5px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
        }
        .footer table {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width: 70%; vertical-align: top;">
                    <div class="institute-title">Satyendranath Tagore Civil Services Study Centre</div>
                    <div class="sub-title">Government of West Bengal &bull; Netaji Subhash Administrative Training Institute Complex, Salt Lake, Kolkata</div>
                    <div class="report-title">{{ $title }}</div>
                </td>
                <td style="width: 30%; vertical-align: top;" class="meta-box">
                    <div><strong>Generated On:</strong> {{ $generatedAt }}</div>
                    <div><strong>Total Records:</strong> {{ $documents->count() }} Documents</div>
                    <div><strong>Classification:</strong> Official Institutional Archive</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 3%; text-align: center;">#</th>
                <th style="width: 11%;">Doc Number</th>
                <th style="width: 26%;">Title & Purpose</th>
                <th style="width: 14%;">Category</th>
                <th style="width: 9%;">Department</th>
                <th style="width: 7%; text-align: center;">Active Ver.</th>
                <th style="width: 9%; text-align: center;">Type & Size</th>
                <th style="width: 6%; text-align: center;">Revisions</th>
                <th style="width: 5%; text-align: center;">Downloads</th>
                <th style="width: 10%;">Created / Updated</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $index => $doc)
                @php
                    $curVer = $doc->currentVersion;
                    $ext = strtolower($curVer?->file_type ?? '');
                    $badgeClass = match ($ext) {
                        'pdf' => 'badge-pdf',
                        'doc', 'docx' => 'badge-word',
                        'xls', 'xlsx', 'csv' => 'badge-excel',
                        'ppt', 'pptx' => 'badge-ppt',
                        'jpg', 'jpeg', 'png', 'webp' => 'badge-image',
                        'zip', 'rar', '7z' => 'badge-zip',
                        default => 'badge-word',
                    };
                @endphp
                <tr>
                    <td style="text-align: center; font-weight: bold; color: #64748b;">{{ $index + 1 }}</td>
                    <td>
                        <strong style="color: #0f172a; font-family: monospace;">{{ $doc->document_number }}</strong>
                        @if ($doc->access_level !== 'internal')
                            <div style="font-size: 7px; color: #475569; text-transform: uppercase;">[{{ $doc->access_level }}]</div>
                        @endif
                    </td>
                    <td>
                        <div style="font-weight: bold; color: #0f172a;">{{ $doc->title }}</div>
                        @if ($doc->purpose)
                            <div style="color: #475569; font-size: 7.5px; margin-top: 2px;">
                                <em>Purpose:</em> {{ $doc->purpose }}
                            </div>
                        @endif
                        @if ($curVer && $curVer->original_file_name)
                            <div style="color: #64748b; font-size: 7px; margin-top: 1px; font-family: monospace;">
                                File: {{ Str::limit($curVer->original_file_name, 40) }}
                            </div>
                        @endif
                    </td>
                    <td>
                        <div style="color: #1e3a8a; font-weight: 600;">{{ $doc->category?->name ?? 'Uncategorized' }}</div>
                    </td>
                    <td>
                        <span style="color: #334155;">{{ $doc->department ?? 'General' }}</span>
                    </td>
                    <td style="text-align: center;">
                        @if ($curVer)
                            <span class="badge badge-version">v{{ $curVer->version_number }}</span>
                        @else
                            <span style="color: #94a3b8;">&mdash;</span>
                        @endif
                    </td>
                    <td style="text-align: center;">
                        @if ($curVer)
                            <span class="badge {{ $badgeClass }}">{{ strtoupper($curVer->file_type) }}</span>
                            <div style="font-size: 7px; color: #64748b; margin-top: 2px;">{{ $curVer->formatted_file_size }}</div>
                        @else
                            <span style="color: #94a3b8;">&mdash;</span>
                        @endif
                    </td>
                    <td style="text-align: center; font-weight: 600; color: #0f172a;">
                        {{ $doc->total_versions_count ?: $doc->versions()->count() }} rev.
                    </td>
                    <td style="text-align: center; font-weight: 600; color: #15803d;">
                        {{ $doc->total_downloads_count }}
                    </td>
                    <td>
                        <div style="color: #0f172a;">{{ $doc->created_at?->format('d M Y') }}</div>
                        <div style="font-size: 7px; color: #64748b;">by {{ $doc->creator?->name ?? 'System' }}</div>
                        @if ($doc->updated_at && $doc->updated_at->ne($doc->created_at))
                            <div style="font-size: 6.5px; color: #94a3b8; margin-top: 1px;">
                                Rev: {{ $doc->updated_at->format('d/m/Y') }}
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="text-align: center; padding: 20px; color: #64748b;">
                        No soft copy documents found matching the designated criteria.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <table>
            <tr>
                <td style="width: 50%;">
                    SNTCSSC Institutional Soft Copy Document Vault &bull; Official Computer-Generated Certified Register
                </td>
                <td style="width: 50%; text-align: right;">
                    Confidential &amp; Proprietary &bull; Government of West Bengal
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

