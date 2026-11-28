<?php

use App\Mail\QuoteSentMail;
use App\Mail\QuoteSubmittedMail;
use App\Mail\UserInvitationMail;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\HtmlString;

beforeEach(function () {
    config(['app.url' => 'https://api-host.example', 'app.web_url' => 'https://portal-host.example', 'app.name' => 'Portal']);
});

/** @return array<string, Closure(): string> every mail the application sends, rendered to HTML */
function secMails(): array
{
    return [
        'quote submitted' => fn () => (new QuoteSubmittedMail('QUO-AAAAA-AAAAA', 'Acme', 'Alice', 2, 'PO-1', 'https://portal-host.example/admin/quotes/QUO-AAAAA-AAAAA'))->render(),
        'quote sent' => fn () => (new QuoteSentMail('QUO-AAAAA-AAAAA', '$100.00', '2026-12-31', 2, 'https://portal-host.example/quotes/QUO-AAAAA-AAAAA'))->render(),
        'invitation' => fn () => (new UserInvitationMail('https://portal-host.example/accept-invitation?token=t&email=a%40b.test', 7))->render(),
        'password reset' => function () {
            $user = makeUser('customer');

            return (string) (new ResetPasswordNotification('tok'))->toMail($user)->render();
        },
    ];
}

it('links the header of every email to the web app and never mentions the API host', function (string $name) {
    $html = secMails()[$name]();

    expect($html)->toContain('https://portal-host.example')->not->toContain('api-host.example')->not->toContain('laravel.com');
})->with(array_keys(secMails()));

it('uses the app name as plain text in the header (no logo image)', function () {
    $html = secMails()['quote sent']();

    expect($html)->toContain('<a href="https://portal-host.example"')->not->toContain('<img');
});

it('keeps the plain-text header on the web app too', function () {
    $text = view('vendor.mail.text.header', ['url' => 'https://api-host.example', 'slot' => new HtmlString('Portal')])->render();

    expect($text)->toContain('Portal: https://portal-host.example')->not->toContain('api-host.example');
});
