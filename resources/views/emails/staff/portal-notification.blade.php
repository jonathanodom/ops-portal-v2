<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif">
    <div style="margin:0 auto;max-width:640px;padding:32px 16px">
        <div style="border-top:4px solid #1D80F7;background:#ffffff;padding:28px">
            <p style="margin:0;color:#1D80F7;font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">NewDay Tech</p>
            <p style="margin:4px 0 24px;color:#475569;font-size:14px;font-weight:700">Ops Portal</p>
            <p style="margin:0 0 8px;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase">{{ $category }}</p>
            <h1 style="margin:0;font-size:24px;line-height:1.25">{{ $title }}</h1>
            <p style="margin:18px 0 0;font-size:16px;line-height:1.6">{{ $notificationMessage }}</p>
            @if($actionUrl)
                <p style="margin:26px 0 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#1D80F7;color:#ffffff;padding:13px 18px;text-decoration:none;font-size:14px;font-weight:700">{{ $actionLabel }}</a></p>
            @endif
        </div>
        <p style="margin:16px 0 0;color:#64748b;font-size:12px;line-height:1.5">This is an automated Ops Portal notification for {{ $recipientName }}.</p>
    </div>
</body>
</html>
