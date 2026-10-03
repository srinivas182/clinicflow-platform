<?php

declare(strict_types=1);

namespace App\Domains\Lab\Support;

use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Branded results report for the patient: values, units, the range used, flags,
 * the doctor's note, verification details.
 */
final class LabReportPdf
{
    public static function render(LabOrder $order, string $practice): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $rows = $order->results()->get()->map(function (LabResult $r) use ($e): string {
            $value = $r->getAttribute('result_text') ?? $r->value;
            $flag = $r->flag === null || $r->flag === 'normal' ? '' : strtoupper(str_replace('_', ' ', (string) $r->flag));

            return '<tr><td>'.$e($r->name).'</td><td><b>'.$e((string) $value).'</b> '.$e($r->unit).'</td><td>'.$e($r->reference).'</td><td style="color:#b42318">'.$e($flag).'</td></tr>';
        })->implode('');

        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px; color:#1f2937">'
            .'<h2 style="color:#0f766e;margin:0">'.$e($practice).'</h2><p style="margin:2px 0 12px">Laboratory results</p>'
            .'<p><b>Patient:</b> '.$e($order->patient->fullName()).' &nbsp; <b>Sample:</b> '.$e($order->sample_barcode).' &nbsp; <b>Verified:</b> '.$e($order->verified_at?->format('j M Y H:i')).'</p>'
            .'<table width="100%" cellpadding="6" style="border-collapse:collapse"><tr style="background:#f0fdfa"><th align="left">Test</th><th align="left">Result</th><th align="left">Reference</th><th align="left">Flag</th></tr>'.$rows.'</table>'
            .($order->getAttribute('doctor_note') ? '<p style="margin-top:14px"><b>Doctor\'s note:</b> '.$e((string) $order->getAttribute('doctor_note')).'</p>' : '')
            .($order->doctor_comment ? '<p><b>Comment:</b> '.$e($order->doctor_comment).'</p>' : '')
            .'<p style="margin-top:18px;color:#6b7280">Reference ranges are those in use when the results were verified. Discuss any questions with your doctor.</p></body></html>';

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
