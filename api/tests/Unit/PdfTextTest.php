<?php

use App\Support\PdfText;

it('removes control and bidi format characters but keeps ordinary text', function () {
    $dirty = "A\u{202E}B\u{200B}C\u{FEFF}D\u{00AD}E\u{2066}F\u{0007}G\u{0000}H";

    expect(PdfText::clean($dirty))->toBe('ABCDEFGH')
        ->and(PdfText::line('Müller & Söhne GmbH Zażółć gęślą jaźń Привет Ωμέγα € ≥'))->toBe('Müller & Söhne GmbH Zażółć gęślą jaźń Привет Ωμέγα € ≥');
});

it('replaces emoji and other supplementary-plane characters with the replacement character', function () {
    expect(PdfText::line("Bolt \u{1F600} nut \u{1F9E0}"))->toBe("Bolt \u{FFFD} nut \u{FFFD}");
});

it('replaces invalid UTF-8 with the replacement character and restores the global substitute character', function () {
    $before = mb_substitute_character();

    expect(PdfText::line("ab\xC3\x28cd"))->toBe("ab\u{FFFD}(cd")->and(mb_substitute_character())->toBe($before);
});

it('keeps line breaks in clean() and lines(), turns them into spaces in line()', function () {
    expect(PdfText::lines("A\r\nB\n\n  C \rD"))->toBe(['A', 'B', 'C', 'D'])
        ->and(PdfText::line("A\nB\tC"))->toBe('A B C');
});

it('breaks long unbroken runs so nothing overflows a column', function () {
    $out = PdfText::line(str_repeat('W', 300));

    expect(collect(preg_split('/\s+/', $out))->max(fn ($w) => mb_strlen($w)))->toBe(28)->and(str_replace(' ', '', $out))->toBe(str_repeat('W', 300))
        ->and(PdfText::line(str_repeat('é', 40), 16))->toBe(str_repeat('é', 16).' '.str_repeat('é', 16).' '.str_repeat('é', 8));
});

it('leaves HTML-looking text alone (escaping is the template job)', function () {
    expect(PdfText::line('<script>alert(1)</script> & "x"'))->toBe('<script>alert(1)</script> & "x"');
});

it('treats null as empty', function () {
    expect(PdfText::line(null))->toBe('')->and(PdfText::lines(null))->toBe([]);
});
