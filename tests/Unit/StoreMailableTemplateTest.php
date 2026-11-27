<?php

namespace Tests\Unit;

use App\Mail\StoreMailable;
use App\Services\TransactionalEmailService;
use Tests\TestCase;

class StoreMailableTemplateTest extends TestCase
{
    private function mailable(): StoreMailable
    {
        return new class extends StoreMailable {
            public bool $usedTemplate = false;

            public function build()
            {
                if ($this->buildFromStoreTemplate(null, 7, 'order_email', fn() => ['x' => 1])) {
                    $this->usedTemplate = true;
                    return $this;
                }

                return $this->subject('Blade subject')->html('<p>blade</p>');
            }
        };
    }

    private function fakeService(?array $result): void
    {
        $this->app->instance(TransactionalEmailService::class, new class($result) extends TransactionalEmailService {
            public function __construct(private ?array $result) {}

            public function renderForMail($storeId, string $key, callable $context): ?array
            {
                return $this->result;
            }
        });
    }

    public function test_uses_store_template_when_available(): void
    {
        $this->fakeService([
            'subject' => 'Template subject',
            'html'    => '<p>from template</p>',
            'text'    => 'from template',
            'headers' => ['from_name' => 'NTS Team', 'from_email' => '', 'reply_to' => 'help@nts.test', 'cc' => '', 'bcc' => 'a@nts.test; bad-address'],
        ]);

        $mail = $this->mailable();
        $mail->build();

        $this->assertTrue($mail->usedTemplate);
        $this->assertSame('Template subject', $mail->subject);
        $this->assertTrue($mail->hasReplyTo('help@nts.test'));
        $this->assertTrue($mail->hasBcc('a@nts.test'));
        $this->assertCount(1, $mail->bcc, 'invalid addresses are dropped');
        $this->assertSame('NTS Team', $mail->from[0]['name']);
        $this->assertStringContainsString('from template', $mail->render());
    }

    public function test_falls_back_to_default_email_without_template(): void
    {
        $this->fakeService(null);

        $mail = $this->mailable();
        $mail->build();

        $this->assertFalse($mail->usedTemplate);
        $this->assertSame('Blade subject', $mail->subject);
        $this->assertStringContainsString('blade', $mail->render());
    }
}
