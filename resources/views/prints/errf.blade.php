@php
    // Values print as entered, with a dash where the legacy form printed one.
    $dash = fn ($value) => filled($value) ? $value : '-';
    $date = fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Verified Request Form</title>
    <link href="https://fonts.bunny.net/css?family=inter:400,600,700,800&display=swap" rel="stylesheet">
    <style>
        /* Reduced margins to maximize printable area */
        @page { size: A4 portrait; margin: 10mm; }

        body {
            font-family: 'Inter', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.2;
            color: #0f172a;
            margin: 0;
            padding: 0;
            background: #f8fafc;
        }

        .print-container { background: #ffffff; padding: 5px 15px; }

        .text-red { color: #dc2626 !important; }

        .form-header { text-align: center; border-bottom: 2px solid #1e293b; padding-bottom: 8px; margin-bottom: 10px; }
        .form-title { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #0f172a; }

        .top-summary { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px; }
        .doc-types { display: flex; gap: 12px; align-items: center; margin-top: 2px; }
        .receipt-box { background-color: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 4px; text-align: right; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .receipt-box .value-row { display: flex; align-items: baseline; gap: 6px; margin-top: 1px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        th, td { border: 1px solid #cbd5e1; padding: 3px 5px; vertical-align: top; }

        .section-header { background-color: #f1f5f9 !important; font-weight: 700; text-align: left; text-transform: uppercase; font-size: 9px; padding: 4px 6px; color: #334155; letter-spacing: 0.5px; border-bottom: 1px solid #cbd5e1; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .label { display: block; font-size: 7.5px; color: #64748b; margin-bottom: 1px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .value { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; min-height: 12px; color: #0f172a; }

        .tall-cell { height: 35px; vertical-align: bottom; text-align: center; font-size: 8.5px; font-weight: 600; color: #475569; }
        .thumb-cell { height: 55px; text-align: center; vertical-align: bottom; font-size: 8.5px; font-weight: 600; color: #475569; }

        .checkbox-group { display: flex; flex-direction: column; gap: 4px; margin-top: 2px; }
        .checkbox-item { display: flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 700; color: #0f172a; }
        .checkbox-box { font-size: 14px; line-height: 1; }

        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff; }
            .print-container { padding: 0; }
        }
        .print-actions { position: fixed; top: 15px; right: 15px; background: #fff; padding: 12px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; display: flex; gap: 8px; }
        .btn { padding: 8px 16px; cursor: pointer; font-weight: 700; font-family: 'Inter', sans-serif; font-size: 12px; background: #1e293b; color: #fff; border: none; border-radius: 6px; }
        .btn:hover { background: #334155; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
    </style>
</head>
<body>
    <div class="print-actions no-print">
        <button class="btn" onclick="window.print()">Print Form</button>
        <button class="btn btn-danger" onclick="window.close()">Close</button>
    </div>

    <div class="print-container">
        <div class="form-header">
            <div class="form-title">Eligibility/Exam Records Request Form (ERRF)</div>
        </div>

        <div class="top-summary">
            <div>
                <span class="label">Type of requested document:</span>
                <div class="doc-types">
                    <div class="checkbox-item"><span class="checkbox-box">{{ $isCert ? '☑' : '☐' }}</span> Certificate of Eligibility</div>
                    <div class="checkbox-item"><span class="checkbox-box">{{ $isAuth ? '☑' : '☐' }}</span> Authentication of Eligibility</div>
                </div>
            </div>
            <div class="receipt-box">
                <span class="label" style="margin-bottom: 0;">Official Receipt</span>
                <div class="value-row">
                    <span class="value" style="font-size: 13px;">{{ $t->official_receipt }}</span>
                    <span style="font-size: 9px; color: #475569; font-weight: 600;">({{ $date($t->or_date) }})</span>
                </div>
            </div>
        </div>

        <table>
            <tr><td colspan="12" class="section-header">Personal Information</td></tr>
            <tr><td colspan="12"><span class="label">Current name (Last name, First name, Middle name, Extension name)</span></td></tr>
            <tr>
                <td colspan="4"><span class="value">{{ $t->lname }}</span></td>
                <td colspan="4"><span class="value">{{ $t->fname }}</span></td>
                <td colspan="3"><span class="value">{{ $t->mname }}</span></td>
                <td colspan="1"><span class="value">{{ $dash($t->extn) }}</span></td>
            </tr>
            <tr>
                <td colspan="12"><span class="label">Current address</span><span class="value">{{ $r?->current_address }}</span></td>
            </tr>
            <tr>
                <td colspan="4"><span class="label">Sex</span><span class="value">{{ $r?->sex ?? $t->sex }}</span></td>
                <td colspan="4"><span class="label">Civil Status</span><span class="value">{{ $r?->civil_status }}</span></td>
                <td colspan="4"><span class="label">Employment Status</span><span class="value">{{ $r?->employment_status }}</span></td>
            </tr>
            <tr>
                <td colspan="6"><span class="label">Agency / Employer</span><span class="value">{{ $r?->company_name }}</span></td>
                <td colspan="6"><span class="label">Agency Address</span><span class="value">{{ $r?->company_address }}</span></td>
            </tr>
            <tr>
                <td colspan="4"><span class="label">Date of birth</span><span class="value">{{ $date($r?->date_of_birth) }}</span></td>
                <td colspan="4"><span class="label">Place of birth</span><span class="value">{{ $r?->place_of_birth }}</span></td>
                <td colspan="4"><span class="label">Purpose/s of request</span><span class="value">{{ $r?->purpose_of_request }}</span></td>
            </tr>
        </table><br>

        <table>
            <tr><td colspan="12" class="section-header">Eligibility/Examination Data</td></tr>
            <tr><td colspan="12"><span class="label">Name used at the time of Issuance/Exam</span></td></tr>
            <tr>
                <td colspan="4"><span class="value text-red">{{ $r?->used_lastname }}</span></td>
                <td colspan="3"><span class="value text-red">{{ $r?->used_firstname }}</span></td>
                <td colspan="3"><span class="value text-red">{{ $r?->used_middlename }}</span></td>
                <td colspan="2"><span class="value text-red">{{ $dash($r?->used_extn) }}</span></td>
            </tr>
            <tr>
                <td colspan="12"><span class="label">Address used at the time of Issuance/Exam</span><span class="value text-red">{{ $r?->current_address }}</span></td>
            </tr>
            <tr>
                <td colspan="5"><span class="label">Title of Eligibility Exam</span><span class="value text-red">{{ $r?->eligibility }}</span></td>
                <td colspan="3"><span class="label">Date of Issuance/Exam/Grant</span><span class="value text-red">{{ $date($r?->date_of_exam_issuance) }}</span></td>
                <td colspan="4"><span class="label">Place of Issuance/Exam</span><span class="value text-red">{{ $r?->place_of_exam_issuance }}</span></td>
            </tr>
        </table><br>

        <table>
            <tr><td colspan="12" class="section-header">Master List / ICSEVS Verification</td></tr>
            <tr>
                <td colspan="4"><span class="label">Book number</span><span class="value text-red">{{ $dash($r?->book) }}</span></td>
                <td colspan="5"><span class="label">Page number</span><span class="value text-red">{{ $dash($r?->page) }}</span></td>
                <td colspan="3"><span class="label">Seq/Line number</span><span class="value text-red">{{ $dash($r?->sequence) }}</span></td>
            </tr>
            <tr>
                <td colspan="4"><span class="label">Batch number</span><span class="value text-red">-</span></td>
                <td colspan="5"><span class="label">Examinee number</span><span class="value text-red">{{ $dash($r?->examinee_no) }}</span></td>
                <td colspan="3"><span class="label">Rating</span><span class="value text-red">{{ $dash($r?->rating) }}</span></td>
            </tr>
            <tr>
                <td colspan="4"><span class="label">Registration/Certificate/Resolution No.</span><span class="value text-red">{{ $dash($r?->cert_no) }}</span></td>
                <td colspan="5"><span class="label">Release/Registration Date</span><span class="value text-red">{{ $dash($date($r?->release_date)) }}</span></td>
                <td colspan="3"><span class="label">Date of Effectivity</span><span class="value text-red">{{ $dash($date($r?->effectivity_date)) }}</span></td>
            </tr>
        </table><br>

        <table>
            <tr>
                <td colspan="5" class="section-header" style="border-right: none;"></td>
                <td colspan="7" class="section-header" style="border-left: none;">Manual PSP Evaluation</td>
            </tr>
            <tr>
                <td colspan="5" rowspan="4" style="vertical-align: middle;">
                    <div class="checkbox-group">
                        <div style="font-size: 8.5px; font-weight: 700; color: #334155;"><span class="checkbox-box">☐</span> PSP AVAILABLE THROUGH VCAS/ICSEVS</div>
                        <div style="font-size: 8.5px; font-weight: 700; color: #334155;"><span class="checkbox-box">☐</span> NO PSP / PSP NOT AVAILABLE</div>
                        <div style="font-size: 8.5px; font-weight: 700; color: #334155;"><span class="checkbox-box">☐</span> CHECKED DATA AGAINST PSP REGIONAL FILE</div>
                    </div>
                </td>
                <td colspan="3" style="background: #f8fafc;"></td>
                <td colspan="2" style="font-size: 8px; font-weight: 700; text-align: center; text-transform: uppercase; background: #f8fafc;">In Order</td>
                <td colspan="2" style="font-size: 8px; font-weight: 700; text-align: center; text-transform: uppercase; background: #f8fafc;">Not In Order</td>
            </tr>
            <tr>
                <td colspan="3"><span class="label">Picture/Photo</span></td>
                <td colspan="2"></td>
                <td colspan="2"></td>
            </tr>
            <tr>
                <td colspan="3"><span class="label">Signature</span></td>
                <td colspan="2"></td>
                <td colspan="2"></td>
            </tr>
            <tr>
                <td colspan="3"><span class="label">Date of Birth</span></td>
                <td colspan="2"></td>
                <td colspan="2"></td>
            </tr>
        </table><br>

        <table>
            <tr><td colspan="3" class="section-header">Verifiers</td></tr>
            <tr>
                <td style="width:33.3%;"><span class="label">1st Verifier</span><div class="value" style="min-height: 18px;"></div></td>
                <td style="width:33.3%;"><span class="label">2nd Verifier</span><div class="value" style="min-height: 18px;"></div></td>
                <td style="width:33.3%;"><span class="label">3rd Verifier</span><div class="value" style="min-height: 18px;"></div></td>
            </tr>
            <tr>
                <td colspan="3"><span class="label">Remarks</span><div class="value" style="min-height: 15px;"></div></td>
            </tr>
        </table><br>

        <table>
            <tr>
                <td class="tall-cell" style="width: 25%;">Signature of Eligible</td>
                <td class="tall-cell" style="width: 25%;">Signature at time of Exam</td>
                <td class="thumb-cell" style="width: 20%;">Right Thumb Print<br>(Examinee/Eligible)</td>
                <td class="tall-cell" style="width: 30%;">Authorized Representative<br>(Signature over printed name)</td>
            </tr>
        </table><br>

        <table style="border: none; margin-bottom: 0;">
            <tr>
                <td style="width: 25%; border: none; padding: 2px 0;"><span class="label">Contact Number</span><span class="value">{{ $t->mobile }}</span></td>
                <td style="width: 25%; border: none; padding: 2px 0;"><span class="label">COE Copies</span><span class="value text-red">{{ $coeCopies }}</span></td>
                <td style="width: 25%; border: none; padding: 2px 0;"><span class="label">Auth Copies</span><span class="value text-red">{{ $authCopies }}</span></td>
                <td style="width: 25%; border: none; padding: 2px 0;"><span class="label">Rep Contact</span><span class="value"></span></td>
            </tr>
        </table>
    </div>

    <script>
        window.onload = function () { setTimeout(function () { window.print(); }, 500); };
    </script>
</body>
</html>
