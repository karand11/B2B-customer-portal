<?php

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Support\AttachmentName;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake('quotes');
    $this->w = quoteWorld();
    $this->quote = makeQuote($this->w, 'submitted');
    $this->n = $this->quote->quote_number;
});

afterEach(fn () => $this->travelBack());

/** Small, unambiguous fixtures built in code (no binary files in the repository). */
function fixture(string $kind): string
{
    return match ($kind) {
        'pdf' => pdfBytes(),
        'png' => pngBytes(),
        'jpeg' => jpegBytes(),
        'text' => "Please quote 100 pieces.\nThanks\n",
        'csv' => "part,qty\n6205,10\n6305,4\n",
        'csv-formula' => "=cmd|' /C calc'!A0,1\n",
        'utf8' => "Müller 軸受\n",
        'exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00\xB8\x00\x00\x00\x00\x00\x00\x00@\x00\x00\x00PE\x00\x00",
        'html' => '<!DOCTYPE html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>',
        'svg' => '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
        'zip' => "PK\x03\x04\x14\x00\x00\x00\x08\x00\x00\x00!\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00PK\x05\x06".str_repeat("\x00", 18),
        'php' => '<?php echo shell_exec($_GET["c"]);',
        'js' => 'alert(document.cookie);',
        'latin1' => "a,b\n\xE9\xFF,c\n",
        'nul' => "a\0b\0c",
    };
}

function openFiles(): array
{
    return Storage::disk('quotes')->allFiles();
}

it('accepts pdf, png, jpg, jpeg, txt and csv, stores them privately under a random name and records the facts', function (string $name, string $kind, string $mime) {
    $content = fixture($kind);
    $response = loginAs($this->w->a1)->post("/api/v1/quotes/{$this->n}/attachments", ['file' => fakeUpload($name, $content)])->assertCreated();

    $row = QuoteAttachment::sole();
    expect(array_keys($response->json('data')))->toBe(['id', 'name', 'mime', 'size_bytes', 'uploaded_by', 'created_at'])
        ->and($response->json('data.id'))->toBe($row->public_id)->and($row->public_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D')
        ->and($response->json('data.mime'))->toBe($mime)->and($response->json('data.name'))->toBe($name)->and($response->json('data.size_bytes'))->toBe(strlen($content))
        ->and($response->json('data.uploaded_by'))->toBe(['name' => $this->w->a1->name, 'role' => 'customer'])
        ->and($row->storage_path)->toMatch('/^quote-attachments\/[a-f0-9]{32}$/D')->and($row->mime)->toBe($mime)
        ->and($row->size_bytes)->toBe(strlen($content))->and($row->sha256)->toBe(hash('sha256', $content))
        ->and($row->uploaded_by_user_id)->toBe($this->w->a1->id)->and($row->uploaded_by_role)->toBe('customer');

    Storage::disk('quotes')->assertExists($row->storage_path);
    Storage::disk('public')->assertMissing($row->storage_path);
    expect(Storage::disk('quotes')->get($row->storage_path))->toBe($content)->and(openFiles())->toBe([$row->storage_path]);

    // The attachment is part of the quote detail.
    $this->getJson("/api/v1/quotes/{$this->n}")->assertJsonPath('data.attachments.0.id', $row->public_id);
})->with([
    'pdf' => ['plan.pdf', 'pdf', 'application/pdf'],
    'png' => ['photo.png', 'png', 'image/png'],
    'jpg' => ['photo.jpg', 'jpeg', 'image/jpeg'],
    'jpeg in capitals' => ['PHOTO.JPEG', 'jpeg', 'image/jpeg'],
    'txt' => ['notes.txt', 'text', 'text/plain'],
    'csv' => ['list.csv', 'csv', 'text/csv'],
    'csv with a formula' => ['=cmd.csv', 'csv-formula', 'text/csv'],
    'utf8 txt' => ['umlaut.txt', 'utf8', 'text/plain'],
    'double extension with a real pdf' => ['a.php.pdf', 'pdf', 'application/pdf'],
]);

it('rejects files whose content or type is not allowed with 422 on file and stores nothing', function (string $name, string $kind) {
    loginAs($this->w->a1);

    $response = uploadTo($this->n, fakeUpload($name, fixture($kind)))->assertStatus(422)->assertJsonValidationErrors('file');

    expect(array_keys($response->json('errors')))->toBe(['file'])->and(QuoteAttachment::count())->toBe(0)->and(openFiles())->toBe([]);
})->with([
    'exe renamed pdf' => ['evil.pdf', 'exe'], 'html renamed pdf' => ['page.pdf', 'html'], 'svg renamed pdf' => ['logo.pdf', 'svg'], 'zip renamed pdf' => ['archive.pdf', 'zip'],
    'exe' => ['a.exe', 'exe'], 'html' => ['a.html', 'html'], 'svg' => ['a.svg', 'svg'], 'php' => ['a.php', 'php'], 'docx' => ['a.docx', 'zip'],
    'zip' => ['a.zip', 'zip'], 'js' => ['a.js', 'js'], 'svg renamed png' => ['logo.png', 'svg'], 'pdf renamed png' => ['scan.png', 'pdf'],
    'png renamed jpg' => ['scan.jpg', 'png'], 'text renamed pdf' => ['a.pdf', 'text'], 'html renamed txt' => ['a.txt', 'html'],
    'no extension' => ['noext', 'pdf'], 'trailing dot only' => ['name.', 'pdf'], 'only dots' => ['....', 'pdf'], 'exe behind a pdf name' => ['a.pdf.exe', 'pdf'],
    'NUL in txt' => ['a.txt', 'nul'], 'latin-1 txt' => ['a.txt', 'latin1'], 'latin-1 csv' => ['a.csv', 'latin1'], 'NUL injected extension' => ["x.pdf\0.exe", 'pdf'],
]);

it('ignores the client supplied content type', function () {
    loginAs($this->w->a1);

    uploadTo($this->n, fakeUpload('x.pdf', fixture('exe'))->mimeType('application/pdf'))->assertStatus(422)->assertJsonValidationErrors('file');
    uploadTo($this->n, fakeUpload('x.exe', fixture('pdf'))->mimeType('application/pdf'))->assertStatus(422)->assertJsonValidationErrors('file');
    expect(QuoteAttachment::count())->toBe(0);
});

it('stores the canonical type, not what the client claims', function () {
    loginAs($this->w->a1);

    uploadTo($this->n, fakeUpload('x.pdf', fixture('pdf'))->mimeType('text/html'))->assertCreated()->assertJsonPath('data.mime', 'application/pdf');
});

it('enforces the 10 MiB per-file limit on bytes: exactly 10 MiB passes, one more byte fails, empty fails', function () {
    loginAs($this->w->a1);

    uploadTo($this->n, fakeUpload('big.pdf', pdfOfSize(10485761)))->assertStatus(422)->assertJsonValidationErrors('file');
    uploadTo($this->n, fakeUpload('empty.pdf', ''))->assertStatus(422)->assertJsonValidationErrors('file');
    expect(QuoteAttachment::count())->toBe(0);

    uploadTo($this->n, fakeUpload('exact.pdf', pdfOfSize(10485760)))->assertCreated()->assertJsonPath('data.size_bytes', 10485760);
    expect(QuoteAttachment::sole()->size_bytes)->toBe(10485760);
});

it('rejects a missing, non-file or multiple file field', function (callable $data) {
    loginAs($this->w->a1);

    $this->post("/api/v1/quotes/{$this->n}/attachments", $data())->assertStatus(422)->assertJsonValidationErrors('file');
    expect(QuoteAttachment::count())->toBe(0);
})->with([
    'nothing' => [fn () => []],
    'a string' => [fn () => ['file' => 'plan.pdf']],
    'an array of files' => [fn () => ['file' => [fakeUpload('a.pdf', pdfBytes()), fakeUpload('b.pdf', pdfBytes())]]],
    'wrong field name' => [fn () => ['document' => fakeUpload('a.pdf', pdfBytes())]],
]);

it('reports a failed upload (UPLOAD_ERR_*) as 422 file', function () {
    loginAs($this->w->a1);
    $broken = new UploadedFile(tempnam(sys_get_temp_dir(), 'q'), 'a.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    $this->post("/api/v1/quotes/{$this->n}/attachments", ['file' => $broken])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('limits a quote to 10 files and 25 MiB in total', function () {
    loginAs($this->w->a1);
    QuoteAttachment::factory()->count(9)->create(['quote_request_id' => $this->quote->id]);

    uploadTo($this->n, fakeUpload('tenth.pdf', pdfBytes()))->assertCreated();
    uploadTo($this->n, fakeUpload('eleventh.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('file');
    expect(QuoteAttachment::count())->toBe(10);

    $other = makeQuote($this->w, 'submitted');
    QuoteAttachment::factory()->count(2)->create(['quote_request_id' => $other->id, 'size_bytes' => 10485760]);
    $five = 5 * 1024 * 1024;

    uploadTo($other->quote_number, fakeUpload('over.pdf', pdfOfSize($five + 1)))->assertStatus(422)->assertJsonValidationErrors('file');
    uploadTo($other->quote_number, fakeUpload('exact.pdf', pdfOfSize($five)))->assertCreated();
    uploadTo($other->quote_number, fakeUpload('one-more.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('file');
    expect($other->attachments()->sum('size_bytes'))->toEqual(25 * 1024 * 1024);
});

it('checks the quote before the file: foreign is 404 and a closed quote is 422 status, whatever the file is', function () {
    $w = $this->w;
    $bad = fakeUpload('evil.exe', fixture('exe'));

    loginAs($w->b1);
    uploadTo($this->n, $bad)->assertNotFound()->assertExactJson(NOT_FOUND);

    foreach (['quoted', 'accepted', 'declined', 'expired'] as $state) {
        $closed = makeQuote($w, $state);
        loginAs($w->a1);
        uploadTo($closed->quote_number, $bad)->assertStatus(422)->assertJsonValidationErrors('status');
        uploadTo($closed->quote_number, fakeUpload('ok.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('status');
        loginAs($w->staff);
        uploadTo($closed->quote_number, fakeUpload('ok.pdf', pdfBytes()))->assertStatus(422)->assertJsonValidationErrors('status');
    }
    expect(QuoteAttachment::count())->toBe(0)->and(openFiles())->toBe([]);
});

it('lets staff upload to a quote in review and records them as staff', function () {
    $w = $this->w;
    loginAs($w->staff)->postJson("/api/v1/quotes/{$this->n}/review")->assertOk();

    foreach ([$w->staff, $w->admin] as $user) {
        loginAs($user);
        uploadTo($this->n, fakeUpload('quotation.pdf', pdfBytes()))->assertCreated()->assertJsonPath('data.uploaded_by.role', 'staff')->assertJsonPath('data.uploaded_by.name', $user->name);
    }
    expect(QuoteAttachment::pluck('uploaded_by_role')->all())->toBe(['staff', 'staff']);
});

it('serves the file only to authorized users, as a forced download with safe headers', function () {
    $w = $this->w;
    $content = fixture('pdf');
    loginAs($w->a1);
    $id = uploadTo($this->n, fakeUpload('Drawing rev2.pdf', $content))->assertCreated()->json('data.id');
    $uri = "/api/v1/quotes/{$this->n}/attachments/{$id}";

    foreach ([$w->a1, $w->a2, $w->staff, $w->admin] as $user) {
        $response = loginAs($user)->get($uri)->assertOk();
        expect($response->streamedContent())->toBe($content)
            ->and($response->headers->get('Content-Type'))->toStartWith('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Drawing rev2.pdf')->not->toContain("\n")
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($content));
    }

    loginAs($w->b1)->get($uri)->assertNotFound()->assertExactJson(NOT_FOUND);
    // The storage path and the app path are not routes.
    $path = QuoteAttachment::sole()->storage_path;
    loginAs($w->a1)->get("/storage/{$path}")->assertNotFound();
    $this->get("/{$path}")->assertNotFound();
});

it('serves a text attachment as an attachment with its canonical type, never inline', function () {
    loginAs($this->w->a1);
    $id = uploadTo($this->n, fakeUpload('list.csv', fixture('csv-formula')))->assertCreated()->json('data.id');

    $response = $this->get("/api/v1/quotes/{$this->n}/attachments/{$id}")->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('hides staff files from the customer until the quote has been sent, for download and delete too', function () {
    $w = $this->w;
    $quote = QuoteRequest::factory()->forCompany($w->a, $w->a1)->inReview()->withLines([[$w->priced, 1, 100]])->create(['valid_until' => '2027-06-30']);
    $n = $quote->quote_number;
    $staffFile = QuoteAttachment::factory()->byStaff($w->staff)->create(['quote_request_id' => $quote->id]);
    $uri = "/api/v1/quotes/{$n}/attachments/{$staffFile->public_id}";

    loginAs($w->a1);
    $this->getJson($uri)->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->deleteJson($uri)->assertNotFound()->assertExactJson(NOT_FOUND);
    loginAs($w->staff)->get($uri)->assertOk();

    $this->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    loginAs($w->a1)->get($uri)->assertOk();
    // Visible but still not deletable by the customer (and the quote is closed anyway).
    $this->deleteJson($uri)->assertNotFound();

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/reopen")->assertOk();
    loginAs($w->a1)->getJson($uri)->assertNotFound();
});

it('answers 404, never 500, when the row exists but the file is gone', function () {
    loginAs($this->w->a1);
    $id = uploadTo($this->n, fakeUpload('a.pdf', pdfBytes()))->assertCreated()->json('data.id');
    Storage::disk('quotes')->delete(QuoteAttachment::sole()->storage_path);
    Log::spy();

    $this->getJson("/api/v1/quotes/{$this->n}/attachments/{$id}")->assertNotFound()->assertExactJson(NOT_FOUND);

    Log::shouldHaveReceived('error')->once();
});

it('deletes the row and the file; customers only their own files; staff any', function () {
    $w = $this->w;
    $mine = QuoteAttachment::factory()->create(['quote_request_id' => $this->quote->id]);
    $colleague = QuoteAttachment::factory()->create(['quote_request_id' => $this->quote->id, 'uploaded_by_user_id' => $w->a2->id]);
    $staffs = QuoteAttachment::factory()->byStaff($w->staff)->create(['quote_request_id' => $this->quote->id]);
    $base = "/api/v1/quotes/{$this->n}/attachments/";

    loginAs($w->a1);
    $this->deleteJson($base.$staffs->public_id)->assertNotFound()->assertExactJson(NOT_FOUND);
    $this->deleteJson($base.$mine->public_id)->assertNoContent();
    expect(QuoteAttachment::whereKey($mine->id)->exists())->toBeFalse();
    Storage::disk('quotes')->assertMissing($mine->storage_path);
    Storage::disk('quotes')->assertExists($staffs->storage_path);

    // A colleague of the same company may delete a customer file; a stranger may not.
    loginAs($w->b1)->deleteJson($base.$colleague->public_id)->assertNotFound();
    loginAs($w->a2)->deleteJson($base.$colleague->public_id)->assertNoContent();
    $this->deleteJson($base.$colleague->public_id)->assertNotFound();

    loginAs($w->staff)->deleteJson($base.$staffs->public_id)->assertNoContent();
    expect(QuoteAttachment::count())->toBe(0)->and(openFiles())->toBe([]);
});

it('refuses a CUSTOMER to delete from a quote that is no longer open, while staff may purge it (Phase 7, audited: see Security/AttachmentPurgeTest)', function (string $state) {
    $quote = makeQuote($this->w, $state);
    $file = QuoteAttachment::factory()->create(['quote_request_id' => $quote->id]);

    loginAs($this->w->a1)->deleteJson("/api/v1/quotes/{$quote->quote_number}/attachments/{$file->public_id}")->assertStatus(422)->assertJsonValidationErrors('status');
    expect(QuoteAttachment::whereKey($file->id)->exists())->toBeTrue();
    Storage::disk('quotes')->assertExists($file->storage_path);

    loginAs($this->w->staff)->deleteJson("/api/v1/quotes/{$quote->quote_number}/attachments/{$file->public_id}")->assertNoContent();
    expect(QuoteAttachment::whereKey($file->id)->exists())->toBeFalse();
    Storage::disk('quotes')->assertMissing($file->storage_path);
})->with(['quoted', 'accepted', 'declined', 'expired']);

it('logs a failing file removal and still answers 204 without a dangling row', function () {
    $file = QuoteAttachment::factory()->create(['quote_request_id' => $this->quote->id]);
    $disk = Mockery::mock();
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk gone'));
    Storage::shouldReceive('disk')->with('quotes')->andReturn($disk);
    Log::spy();

    loginAs($this->w->a1)->deleteJson("/api/v1/quotes/{$this->n}/attachments/{$file->public_id}")->assertNoContent();

    Log::shouldHaveReceived('error')->once();
    expect(QuoteAttachment::count())->toBe(0);
});

it('removes the stored file when the database insert fails after the write', function () {
    QuoteAttachment::creating(fn () => throw new RuntimeException('boom'));

    try {
        loginAs($this->w->a1);
        uploadTo($this->n, fakeUpload('a.pdf', pdfBytes()))->assertStatus(500);
    } finally {
        QuoteAttachment::flushEventListeners();
    }

    expect(QuoteAttachment::count())->toBe(0)->and(openFiles())->toBe([]);
});

it('sanitises display names and never uses them as paths', function (string $client, string $expected) {
    expect(AttachmentName::clean($client))->toBe($expected);
})->with([
    'unix traversal' => ['../../x.pdf', 'x.pdf'],
    'windows traversal' => ['..\\..\\x.pdf', 'x.pdf'],
    'windows drive' => ['C:\\Users\\x\\a.pdf', 'a.pdf'],
    'directories' => ['a/b/c.pdf', 'c.pdf'],
    'NUL' => ["x.pdf\0.exe", 'x.pdf.exe'],
    'CRLF' => ["a\r\nb.pdf", 'ab.pdf'],
    'bidi override' => ["\u{202E}fdp.exe", 'fdp.exe'],
    'zero width space' => ["a\u{200B}b.pdf", 'ab.pdf'],
    'spaces collapsed' => ['  spaced   name .pdf ', 'spaced name .pdf'],
    'only dots' => ['....', 'file'],
    'empty' => ['', 'file'],
    'trailing dot' => ['name.pdf.', 'name.pdf'],
    'invalid utf-8' => ["bad\xFFname.pdf", 'bad?name.pdf'],
    'quotes and semicolon' => ['";filename=evil.exe', '";filename=evil.exe'],
    'emoji' => ['📄.pdf', '📄.pdf'],
    'reserved device name' => ['CON.pdf', 'CON.pdf'],
    'overlong keeps the extension' => [str_repeat('A', 300).'.pdf', str_repeat('A', 96).'.pdf'],
]);

it('puts only a clean name into the response and the Content-Disposition header', function (string $client) {
    loginAs($this->w->a1);
    $created = uploadTo($this->n, fakeUpload($client, pdfBytes()))->assertCreated();
    $name = $created->json('data.name');

    expect($name)->not->toMatch('/[\x00-\x1F\x7F\/\\\\]/')->and(mb_strlen($name))->toBeLessThanOrEqual(100)->and($name)->toEndWith('.pdf');

    $header = $this->get("/api/v1/quotes/{$this->n}/attachments/".$created->json('data.id'))->assertOk()->headers->get('Content-Disposition');
    expect($header)->toStartWith('attachment;')->not->toContain("\r")->not->toContain("\n")->not->toContain('../');
    expect(QuoteAttachment::sole()->storage_path)->toMatch('/^quote-attachments\/[a-f0-9]{32}$/D');
})->with([
    'traversal' => ['../../etc/passwd.pdf'], 'backslash traversal' => ['..\\..\\boot.pdf'], 'quote injection' => ['a";b.pdf'], 'header injection' => ["a\r\nX-Evil: 1.pdf"],
    'bidi' => ["gnp\u{202E}.pdf"], 'long' => [str_repeat('x', 300).'.pdf'], 'emoji' => ['📄 plan.pdf'], 'percent' => ['100%.pdf'], 'reserved' => ['NUL.pdf'],
]);

it('renders a request above post_max_size as 413 with the contract body on api routes', function () {
    $handler = app(Handler::class);
    $response = $handler->render(Request::create('/api/v1/quotes/QUO-AAAAA-AAAAA/attachments', 'POST', server: ['HTTP_ACCEPT' => 'application/json']), new PostTooLargeException);

    expect($response->getStatusCode())->toBe(413)->and($response->getContent())->toBe('{"message":"The upload is too large."}');
});

it('limits uploads to 20 per minute per user, counting rejected attempts, without touching other users', function () {
    $w = $this->w;
    loginAs($w->a1);

    foreach (range(1, 20) as $i) {
        $this->post("/api/v1/quotes/{$this->n}/attachments", [])->assertStatus(422);
    }
    $this->post("/api/v1/quotes/{$this->n}/attachments", ['file' => fakeUpload('a.pdf', pdfBytes())])->assertStatus(429)->assertHeader('Retry-After');
    expect(QuoteAttachment::count())->toBe(0);

    loginAs($w->a2);
    uploadTo($this->n, fakeUpload('a.pdf', pdfBytes()))->assertCreated();

    $this->travel(61)->seconds();
    loginAs($w->a1);
    uploadTo($this->n, fakeUpload('b.pdf', pdfBytes()))->assertCreated();
});
