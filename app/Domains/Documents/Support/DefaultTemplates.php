<?php

declare(strict_types=1);

namespace App\Domains\Documents\Support;

/**
 * Professional starting templates every provider receives. Providers edit the
 * body in the template studio; the legal block is always appended.
 */
final class DefaultTemplates
{
    public static function header(): string
    {
        return '<div class="head" style="border-bottom:3px solid {{ practice.colour }}"><div class="name">{{ practice.name }}</div>'
            .'<div class="muted">{{ practice.address }} · {{ practice.phone }} · BHF {{ practice.bhf }}</div></div>';
    }

    public static function body(DocumentType $type): string
    {
        return self::header().match ($type) {
            DocumentType::Invoice => '<h1>Invoice {{ invoice.number }}</h1><p>Patient: {{ patient.name }} · {{ patient.medical_aid }}</p>'
                .'<table><tr><th>Code</th><th>Item</th><th>Qty</th><th class="r">Amount</th></tr>'
                .'{{#lines}}<tr><td>{{ item.code }}</td><td>{{ item.description }}</td><td>{{ item.quantity }}</td><td class="r">{{ item.total }}</td></tr>{{/lines}}'
                .'</table><p class="r"><b>Total {{ invoice.total }}</b> · Paid {{ invoice.paid }} · Due {{ invoice.balance }}</p>',
            DocumentType::Prescription => '<h1>Rx</h1><p>{{ patient.name }} · {{ patient.age }} years</p>'
                .'<table><tr><th>Medicine</th><th>Dose</th><th>Qty</th><th>Repeats</th></tr>'
                .'{{#lines}}<tr><td>{{ item.description }}</td><td>{{ item.dose }}</td><td>{{ item.quantity }}</td><td>{{ item.repeats }}</td></tr>{{/lines}}</table>',
            DocumentType::SickNote => '<h1>Medical certificate</h1><p>This is to certify that <b>{{ patient.name }}</b> was examined on {{ document.date }} '
                .'and is unfit for work or school for <b>{{ document.days }} days</b>.</p><p>Reason: {{ document.reason }}</p>',
            DocumentType::Referral => '<h1>Referral</h1><p>To: {{ document.to }}</p><p>Re: <b>{{ patient.name }}</b>, {{ patient.age }} years</p>'
                .'<p>{{ document.reason }}</p>',
        };
    }

    public static function css(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#12232E}.head{padding-bottom:8px;margin-bottom:14px}'
            .'.name{font-size:16px;font-weight:bold}.muted{color:#5E6E76}h1{font-size:15px}table{width:100%;border-collapse:collapse;margin:8px 0}'
            .'th,td{text-align:left;padding:4px;border-bottom:1px solid #DCE3E1}.r{text-align:right}'
            .'.legal{margin-top:24px;padding:8px;border:1px solid #F3C7C7;background:#FFF8F8;font-size:9.5px}';
    }
}
