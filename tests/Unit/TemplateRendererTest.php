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
