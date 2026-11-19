<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $material->title }} - Study Material Notification</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 24px; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: #ffffff; padding: 24px 32px; text-align: left; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
        .header p { margin: 4px 0 0; font-size: 13px; opacity: 0.9; }
        .content { padding: 32px; }
        .badge { display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; border-radius: 9999px; background-color: #eff6ff; color: #1d4ed8; margin-bottom: 12px; }
        .material-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .meta-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; }
        .meta-label { color: #64748b; font-weight: 500; }
        .meta-value { color: #0f172a; font-weight: 600; }
        .note-box { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; margin: 16px 0; font-size: 13px; color: #92400e; border-radius: 4px; }
        .btn-container { margin: 28px 0 16px; text-align: center; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff !important; padding: 12px 28px; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2); }
        .footer { background-color: #f1f5f9; padding: 20px 32px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
        .footer p { margin: 4px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Satyendranath Tagore Civil Services Study Centre</h1>
            <p>Academic Study Materials & Resources Portal</p>
        </div>

        <div class="content">
            <span class="badge">{{ $material->material_type_label }}</span>
            <h2 style="margin: 0 0 12px; font-size: 22px; color: #0f172a;">{{ $material->title }}</h2>

            <p style="font-size: 14px; color: #334155; margin: 0 0 16px;">
                Dear <strong>{{ $user->name }}</strong>,
            </p>
            <p style="font-size: 14px; color: #475569; margin: 0 0 16px;">
                New study material has been published for your enrolled course curriculum at SNTCSSC.
            </p>

            <div class="material-box">
                <div class="meta-row">
                    <span class="meta-label">Course:</span>
                    <span class="meta-value">{{ $material->course?->name }}</span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Batches:</span>
                    <span class="meta-value">{{ $material->batches_summary }}</span>
                </div>
                @if($material->subject)
                    <div class="meta-row">
                        <span class="meta-label">Subject:</span>
                        <span class="meta-value">{{ $material->subject }}</span>
                    </div>
                @endif
                @if($material->topic)
                    <div class="meta-row">
                        <span class="meta-label">Topic / Module:</span>
                        <span class="meta-value">{{ $material->topic }} {{ $material->module_code ? "({$material->module_code})" : '' }}</span>
                    </div>
                @endif
                @if($material->total_files_count > 0)
                    <div class="meta-row">
                        <span class="meta-label">Attached Files:</span>
                        <span class="meta-value">{{ $material->total_files_count }} file(s) ({{ $material->formatted_total_size }})</span>
                    </div>
                @endif
            </div>

            @if(!empty($customMessage))
                <div class="note-box">
                    <strong>Note from Faculty / Administrator:</strong><br>
                    {{ $customMessage }}
                </div>
            @endif

            @if($material->description)
                <div style="font-size: 13px; color: #475569; margin: 16px 0; background: #fafafa; padding: 12px; border-radius: 6px;">
                    <strong>Overview:</strong><br>
                    {!! nl2br(e($material->description)) !!}
                </div>
            @endif

            <div class="btn-container">
                <a href="{{ $downloadUrl }}" class="btn">View & Download Study Material</a>
            </div>

            @if($material->external_url)
                <p style="font-size: 13px; text-align: center; color: #64748b; margin-top: 12px;">
                    External Class/Lecture Link: <a href="{{ $material->external_url }}" style="color: #2563eb;">{{ $material->external_url }}</a>
                </p>
            @endif
        </div>

        <div class="footer">
            <p>This is an automated notification from the SNTCSSC Academic Management Information System.</p>
            <p>&copy; {{ date('Y') }} Satyendranath Tagore Civil Services Study Centre. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
