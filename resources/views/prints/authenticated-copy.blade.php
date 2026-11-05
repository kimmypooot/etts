<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Print - Authenticated Copy</title>
    @if ($isImage)
        {{-- The same QR library the legacy print loaded, pinned to the version it used. --}}
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    @endif
    <style>
        /* A4 page setup */
        @page { size: A4 portrait; margin: 20mm 0; }
        body, html { margin: 0; padding: 0; background: #525659; }

        .page-container { position: relative; width: 210mm; height: 257mm; margin: 0 auto; background: white; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.5); }
        .document-image { width: 100%; height: 100%; object-fit: contain; display: block; }

        .qr-code-stamp { position: absolute; top: 3mm; right: 15mm; z-index: 10; background: rgba(255, 255, 255, 0.95); padding: 5px; border-radius: 4px; }

        .auth-stamp { position: absolute; bottom: 18mm; right: 15mm; font-family: "Times New Roman", Times, serif; text-align: center; z-index: 10; padding: 15px 25px; border-radius: 4px; color: #000; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .auth-title { font-size: 14px; font-weight: bold; margin-bottom: 20px; }
        .auth-signatory { font-size: 14px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px; }
        .auth-position { font-size: 14px; font-weight: bold; margin-bottom: 4px; }
        .auth-rsu { font-size: 14px; font-weight: bold; margin-bottom: 12px; }
        .auth-details { font-size: 14px; font-weight: bold; }

        @media print {
            .no-print { display: none !important; }
            body, html { background: white; width: 210mm; }
            .page-container { margin: 0; box-shadow: none; border: none; }
        }

        .print-actions { position: fixed; top: 15px; left: 15px; background: #fff; padding: 10px; border: 1px solid #ccc; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 5px; z-index: 1000; font-family: Arial, sans-serif; }
        .btn { padding: 8px 15px; cursor: pointer; font-weight: bold; background: #4f46e5; color: #fff; border: none; border-radius: 4px; }
        .notice { max-width: 520px; margin: 80px auto; background: #fff; padding: 28px; border-radius: 8px; font-family: Arial, sans-serif; }
    </style>
</head>
<body>
@if (! $isImage)
    {{-- As in legacy: a PDF cannot carry the stamp, so the file is opened and stamped by hand. --}}
    <div class="notice">
        <h2 style="margin-top:0">Document is a PDF</h2>
        <p>The system cannot overlay the authentication stamp directly onto a PDF. Open the file and apply a physical stamp.</p>
        <p><a class="btn" style="text-decoration:none;display:inline-block" href="{{ $imageUrl }}" target="_blank" rel="noopener">Open PDF</a></p>
    </div>
@else
    <div class="print-actions no-print">
        <button class="btn" onclick="window.print()">Print Document</button>
        <button class="btn" style="background:#dc3545; margin-left: 5px;" onclick="window.close()">Close</button>
    </div>

    <div class="page-container">
        <img src="{{ $imageUrl }}" id="doc-img" class="document-image" alt="Original document" />

        <div id="qr-container" class="qr-code-stamp"></div>

        <div class="auth-stamp">
            <div class="auth-title">CERTIFIED AUTHENTICATED COPY</div>
            <div class="auth-signatory">{{ $signatory }}</div>
            <div class="auth-position">{{ $position }}</div>
            <div class="auth-rsu">{{ $office }}</div>
            <div class="auth-details">O.R. No. {{ $orNumber }} dated {{ $orDate }}</div>
        </div>
    </div>

    <script>
        // The QR code carries the record number.
        setTimeout(function () {
            if (window.QRCode) {
                new QRCode(document.getElementById('qr-container'), {
                    text: @json((string) $t->id),
                    width: 70,
                    height: 70,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }, 100);

        // Print once the image (and the QR code) have rendered.
        var img = document.getElementById('doc-img');
        img.onload = function () { setTimeout(function () { window.print(); }, 500); };
        img.onerror = function () { alert('Failed to load the document image. Please check the attachment.'); };
    </script>
@endif
</body>
</html>
