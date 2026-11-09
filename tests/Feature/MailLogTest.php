<?php

use App\Jobs\SendCareEmail;
use App\Models\CareGroup;
use App\Models\EmailDelivery;
use App\Models\MailLog;
use App\Models\ParentContact;
use App\Models\User;
use App\Notifications\CareMessage;
use App\Services\CareCommunicationService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;

it('archives sent HTML with all recipients and encrypted content', function () {
    $this->travelTo(now()->startOfSecond());

    $sent = Mail::mailer('array')->html('<h1>Betreuung</h1><p>Persönlicher Inhalt.</p>', function (Message $message): void {
        $message->from('sender@example.com', 'Elternbeirat')
            ->to('parent@example.com', 'Familie Beispiel')
            ->cc('copy@example.com', 'Kopie')
            ->bcc('hidden@example.com', 'Blindkopie')
            ->subject('Betreuung am Montag');
    });

    $this->assertDatabaseCount('mail_logs', 1);
    $log = MailLog::sole();
    expect($log->from)->toBe([['address' => 'sender@example.com', 'name' => 'Elternbeirat']]);
    expect($log->to)->toBe([['address' => 'parent@example.com', 'name' => 'Familie Beispiel']]);
    expect($log->cc)->toBe([['address' => 'copy@example.com', 'name' => 'Kopie']]);
    expect($log->bcc)->toBe([['address' => 'hidden@example.com', 'name' => 'Blindkopie']]);
    expect($log->subject)->toBe('Betreuung am Montag');
    expect($log->html)->toBe('<h1>Betreuung</h1><p>Persönlicher Inhalt.</p>');
    expect($log->message_id)->toBe($sent->getMessageId());
    expect($log->sent_at->equalTo(now()))->toBeTrue();
    foreach ([
        'from' => 'sender@example.com',
        'to' => 'parent@example.com',
        'cc' => 'copy@example.com',
        'bcc' => 'hidden@example.com',
        'subject' => 'Betreuung am Montag',
        'html' => 'Persönlicher Inhalt',
    ] as $attribute => $plaintext) {
        expect($log->getRawOriginal($attribute))->not->toContain($plaintext);
    }
});

it('archives the rendered care notification through the normal mail channel', function () {
    Notification::route('mail', 'family@example.com')->notify(new CareMessage('request', [
        'date' => '09.11.2026',
        'child_name' => 'Anna Beispiel',
        'deadline' => null,
        'token' => 'personal-parent-link',
    ]));

    $this->assertDatabaseCount('mail_logs', 1);
    $log = MailLog::sole();
    expect($log->subject)->toBe('Rückmeldung zur Notbetreuung');
    expect($log->to)->toBe([['address' => 'family@example.com', 'name' => '']]);
    expect($log->html)->toContain('Anna Beispiel', 'Rückmeldung abgeben', route('parent.show', 'personal-parent-link'));
});

it('archives plain text as escaped HTML', function () {
    Mail::mailer('array')->raw('<script>alert("mail")</script> & Betreuung', function (Message $message): void {
        $message->to('parent@example.com')->subject('Textnachricht');
    });

    expect(MailLog::sole()->html)
        ->toContain('<pre>', '&lt;script&gt;', '&amp; Betreuung', '</pre>')
        ->not->toContain('<script>');
});

it('archives queued care mail once when the delivery job runs again', function () {
    [$year, $children, $allocation] = careScenario(['never']);
    $contact = ParentContact::factory()->for($children[0]->family)->create(['email' => 'queued-parent@example.com']);
    app(CareCommunicationService::class)->requestResponses($allocation);
    $delivery = EmailDelivery::sole();
    $job = new SendCareEmail($delivery->id);

    $job->handle();
    $job->handle();

    $this->assertDatabaseCount('mail_logs', 1);
    expect(MailLog::sole()->to)->toBe([['address' => $contact->email, 'name' => '']]);
    expect(MailLog::sole()->html)->toContain($children[0]->first_name, 'Rückmeldung abgeben');
    expect($delivery->fresh()->sent_at)->not->toBeNull();
});

it('archives streamed message bodies as HTML', function (string $format, string $expected) {
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, '<p>Inhalt aus Stream</p>');

    try {
        Mail::mailer('array')->send([], [], function (Message $message) use ($stream, $format): void {
            $message->to('parent@example.com')->subject('Stream');
            $message->getSymfonyMessage()->{$format}($stream);
        });

        expect(MailLog::sole()->html)->toBe($expected);
    } finally {
        fclose($stream);
    }
})->with([
    'HTML stream' => ['html', '<p>Inhalt aus Stream</p>'],
    'text stream' => ['text', '<pre>&lt;p&gt;Inhalt aus Stream&lt;/p&gt;</pre>'],
]);

it('does not archive mail cancelled before sending', function () {
    Event::listen(MessageSending::class, fn (): bool => false);

    Mail::mailer('array')->html('<p>Abgebrochen</p>', function (Message $message): void {
        $message->to('parent@example.com')->subject('Abbruch');
    });

    $this->assertDatabaseCount('mail_logs', 0);
});

it('does not record failed delivery as a sent message', function () {
    $transport = Mockery::mock(TransportInterface::class);
    $transport->shouldReceive('send')->once()->andThrow(new TransportException('SMTP unavailable'));
    Mail::mailer('array')->setSymfonyTransport($transport);

    expect(fn () => Mail::mailer('array')->html('<p>Fehler</p>', function (Message $message): void {
        $message->to('parent@example.com')->subject('Fehlgeschlagen');
    }))->toThrow(TransportException::class);

    $this->assertDatabaseCount('mail_logs', 0);
});

it('redirects guests from every mail log endpoint to login', function (string $routeName) {
    $log = MailLog::factory()->create();

    $this->get(route($routeName, $routeName === 'mail-logs.index' ? [] : $log))
        ->assertRedirect(route('login'));
})->with(['mail-logs.index', 'mail-logs.show', 'mail-logs.content']);

it('denies mail log access to users outside the overall administration', function (bool $isAdmin, bool $hasGroup) {
    $user = User::factory()->create([
        'is_admin' => $isAdmin,
        'group_id' => $hasGroup ? CareGroup::factory()->create()->id : null,
    ]);
    $log = MailLog::factory()->create();
    $this->actingAs($user);

    expect(Gate::allows('view-mail-logs'))->toBeFalse();
    $this->get(route('mail-logs.index'))->assertForbidden();
    $this->get(route('mail-logs.show', $log))->assertForbidden();
    $this->get(route('mail-logs.content', $log))->assertForbidden();
})->with([
    'group administrator' => [true, true],
    'group member' => [false, true],
    'user without a group' => [false, false],
]);

it('lists newest messages first with pagination for the overall administrator', function () {
    $admin = User::factory()->administrator()->create();
    $oldest = MailLog::factory()->create(['subject' => 'Älteste Mail']);
    MailLog::factory()->count(29)->create();
    $newest = MailLog::factory()->create(['subject' => 'Neueste Mail', 'html' => '<p>Vertraulicher Mailinhalt</p>']);
    $this->actingAs($admin);

    $this->get(route('mail-logs.index'))->assertOk()
        ->assertSee('Neueste Mail')
        ->assertSee(route('mail-logs.show', $newest))
        ->assertDontSee('Älteste Mail')
        ->assertDontSee('Vertraulicher Mailinhalt')
        ->assertViewHas('logs', fn ($logs): bool => $logs->first()->is($newest) && $logs->count() === 30 && $logs->total() === 31);
    $this->get(route('mail-logs.index', ['page' => 2]))->assertOk()
        ->assertSee($oldest->subject)
        ->assertDontSee('Neueste Mail');
});

it('shows an empty state before any mail is sent', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->get(route('mail-logs.index'))->assertOk()
        ->assertSee('Noch keine E-Mails protokolliert.');
});

it('escapes mail metadata and embeds the HTML in a sandboxed preview', function () {
    $admin = User::factory()->administrator()->create();
    $log = MailLog::factory()->create([
        'subject' => '<script>subjectAttack()</script>',
        'from' => [['address' => 'sender@example.com', 'name' => '<script>senderAttack()</script>']],
        'to' => [['address' => 'parent@example.com', 'name' => '<script>recipientAttack()</script>']],
        'cc' => [['address' => 'copy@example.com', 'name' => 'Kopie']],
        'bcc' => [['address' => 'hidden@example.com', 'name' => 'Blindkopie']],
        'html' => '<script>bodyAttack()</script><h1>Vertrauliche Nachricht</h1>',
    ]);
    $this->actingAs($admin);

    $this->get(route('mail-logs.show', $log))->assertOk()
        ->assertSee($log->subject)
        ->assertSee('<script>senderAttack()</script>')
        ->assertSee('<script>recipientAttack()</script>')
        ->assertSee('copy@example.com')
        ->assertSee('hidden@example.com')
        ->assertSee(route('mail-logs.content', $log))
        ->assertSee('sandbox', false)
        ->assertDontSee('<script>subjectAttack()</script>', false)
        ->assertDontSee('<script>senderAttack()</script>', false)
        ->assertDontSee('<script>recipientAttack()</script>', false)
        ->assertDontSee('bodyAttack()', false);
    $this->get(route('mail-logs.index'))->assertOk()
        ->assertSee($log->subject)
        ->assertDontSee('<script>subjectAttack()</script>', false);
});

it('serves the original HTML with sandbox and private response headers', function () {
    $admin = User::factory()->administrator()->create();
    $log = MailLog::factory()->create(['html' => '<h1>Original</h1><script>alert("untrusted")</script>']);

    $this->actingAs($admin)->get(route('mail-logs.content', $log))->assertOk()
        ->assertContent($log->html)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('does not expose the mail log navigation to group administrators', function () {
    $admin = User::factory()->administrator()->create();
    $groupAdmin = User::factory()->administrator()->create(['group_id' => CareGroup::factory()->create()->id]);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee(route('mail-logs.index'));
    $this->actingAs($groupAdmin)->get(route('dashboard'))->assertOk()->assertDontSee(route('mail-logs.index'));
});

it('denies the overall administrator mail log access while impersonating a group administrator', function () {
    $admin = User::factory()->administrator()->create();
    $groupAdmin = User::factory()->administrator()->create(['group_id' => CareGroup::factory()->create()->id]);
    $log = MailLog::factory()->create();

    $this->actingAs($admin)->post(route('impersonation.store', $groupAdmin))->assertRedirect();

    $this->get(route('mail-logs.index'))->assertForbidden();
    $this->get(route('mail-logs.show', $log))->assertForbidden();
    $this->get(route('mail-logs.content', $log))->assertForbidden();
});

it('returns not found for a missing mail log', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->get(route('mail-logs.show', 123))->assertNotFound();
    $this->get(route('mail-logs.content', 123))->assertNotFound();
});
