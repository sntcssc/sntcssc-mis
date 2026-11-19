<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ __('Barcode Labels') }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 6mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 0;
            font-size: 8px;
        }
        .labels-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 4mm 4mm;
        }
        .label-cell {
            width: 33.33%;
            border: 1px dashed #94a3b8;
            border-radius: 4px;
            padding: 4px 6px;
            text-align: center;
            vertical-align: middle;
            background-color: #ffffff;
            height: 32mm;
        }
        .inst-name {
            font-size: 6.5px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .book-title {
            font-size: 7.5px;
            font-weight: 600;
            color: #334155;
            margin: 2px 0;
            height: 18px;
            overflow: hidden;
            line-height: 1.2;
        }
        .barcode-container {
            margin: 2px 0;
        }
        .barcode-img {
            max-width: 140px;
            height: 28px;
        }
        .meta-line {
            font-size: 6.5px;
            color: #64748b;
            margin-top: 1px;
        }
        .call-no {
            font-weight: bold;
            color: #0f172a;
            font-family: monospace;
        }
    </style>
</head>
<body>

    <table class="labels-grid">
        @foreach($chunks as $row)
            <tr>
                @foreach($row as $label)
                    <td class="label-cell">
                        <div class="inst-name">{{ $institution_name }}</div>
                        <div class="book-title">{{ \Illuminate\Support\Str::limit($label['title'], 38) }}</div>
                        <div class="barcode-container">
                            <img src="{{ $label['barcode_uri'] }}" class="barcode-img" alt="Barcode" />
                        </div>
                        <div class="meta-line">
                            <span class="call-no">{{ $label['accession_no'] }}</span>
                            @if(!empty($label['classification_number']))
                                &bull; {{ $label['classification_number'] }}
                            @endif
                        </div>
                        @if(!empty($label['shelf_location']))
                            <div class="meta-line" style="font-size: 6px; color: #475569;">
                                {{ $label['shelf_location'] }}
                            </div>
                        @endif
                    </td>
                @endforeach
                {{-- Fill empty columns if last row has fewer than 3 --}}
                @for($i = count($row); $i < 3; $i++)
                    <td style="width: 33.33%; border: none;"></td>
                @endfor
            </tr>
        @endforeach
    </table>

</body>
</html>
