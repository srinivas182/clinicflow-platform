<?php

use App\Domains\Documents\Support\TemplateRenderer;

it('fills merge fields and escapes HTML', function (): void {
    expect(TemplateRenderer::render('Hi {{ patient.name }}!', ['patient' => ['name' => '<b>Thandi</b>']]))
        ->toBe('Hi &lt;b&gt;Thandi&lt;/b&gt;!');
});

it('repeats list blocks and leaves unknown fields empty', function (): void {
    $out = TemplateRenderer::render('{{#lines}}[{{ item.description }}:{{ item.total }}]{{/lines}}{{ nope.field }}', [
        'lines' => [['description' => 'Consult', 'total' => 'R520'], ['description' => 'Sick note', 'total' => 'R52']],
    ]);

    expect($out)->toBe('[Consult:R520][Sick note:R52]');
});

it('never executes template code', function (): void {
    expect(TemplateRenderer::render('{{ app.key }} <?php echo 1; ?> @php echo 2; @endphp', []))
        ->toBe(' <?php echo 1; ?> @php echo 2; @endphp');
});

it('strips scripts, event handlers and remote resources', function (): void {
    $clean = TemplateRenderer::sanitise('<p onclick="x()">Hi</p><script>alert(1)</script><img src="https://evil.test/x.png"><a href="javascript:x()">y</a>');

    expect($clean)->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('evil.test')
        ->not->toContain('javascript:');
});

it('removes attack patterns that slip past simple filters', function (string $html, string $mustNotContain): void {
    expect(strtolower(TemplateRenderer::sanitise($html)))->not->toContain($mustNotContain);
})->with([
    'handler without a space' => ['<img/onerror=alert(1) src=x>', 'onerror'],
    'unquoted javascript link' => ['<a href=javascript:alert(1)>x</a>', 'javascript:'],
    'encoded javascript link' => ['<a href="jav&#x09;ascript:alert(1)">x</a>', 'script:'],
    'uppercase javascript link' => ['<a href=" JAVASCRIPT:alert(1)">x</a>', 'javascript'],
    'data link' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:'],
    'svg onload' => ['<svg onload=alert(1)><circle/></svg>', 'onload'],
    'math' => ['<math><mtext><img src=x onerror=alert(1)></mtext></math>', 'onerror'],
    'nested script' => ['<scr<script>ipt>alert(1)</script>', '<script'],
    'style tag' => ['<style>body{background:url(https://evil.test)}</style>', 'evil.test'],
    'style attribute' => ['<p style="background:url(https://evil.test)">x</p>', 'evil.test'],
    'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>', 'srcdoc'],
    'base tag' => ['<base href="https://evil.test/">', 'evil.test'],
    'form action' => ['<form action="https://evil.test"><button>Go</button></form>', 'evil.test'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', 'evil.test'],
    'remote image' => ['<img src="https://evil.test/track.gif">', 'evil.test'],
    'protocol-relative image' => ['<img src="//evil.test/track.gif">', 'evil.test'],
]);

it('keeps safe content, links and list blocks inside tables', function (): void {
    $clean = TemplateRenderer::sanitise('<h1>Invoice {{ invoice.number }}</h1><table><tr><th>Item</th></tr>{{#lines}}<tr><td>{{ item.description }}</td></tr>{{/lines}}</table>'
        .'<p class="r"><a href="https://clinicflow.co.za" target="new">Site</a> <a href="mailto:care@sunrise.test">Email</a> <a href="/book">Book</a></p>');
    expect($clean)->toContain('{{#lines}}<tr><td>{{ item.description }}</td></tr>{{/lines}}')
        ->toContain('<h1>Invoice {{ invoice.number }}</h1>')->toContain('href="https://clinicflow.co.za" target="_blank" rel="noopener noreferrer"')
        ->toContain('href="mailto:care@sunrise.test"')->toContain('href="/book"')->toContain('class="r"');
});
