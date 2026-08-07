<?php

namespace Tests\Feature;

use App\Http\Controllers\ContactController;
use App\Mail\ContactInquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    public function test_contact_controller_sends_mailable(): void
    {
        Mail::fake();

        $request = Request::create('/contact', 'POST', [
            'company' => 'UTB Transports',
            'email' => 'client@exemple.ci',
            'phone' => '+225 07 00 00 00 00',
            'message' => 'Je souhaite une démonstration.',
        ]);

        $response = app(ContactController::class)->send($request);

        Mail::assertSent(ContactInquiry::class, function (ContactInquiry $mail) {
            return $mail->hasTo('contact@tiketi.ci')
                && $mail->envelope()->subject === 'Contact from TIKETI'
                && $mail->envelope()->replyTo[0]->address === 'client@exemple.ci'
                && $mail->company === 'UTB Transports'
                && $mail->content === 'Je souhaite une démonstration.';
        });
    }

    public function test_contact_form_http_redirects_cleanly(): void
    {
        $response = $this->post(route('contact.send'), [
            'company' => 'UTB Transports',
            'email' => 'client@exemple.ci',
            'phone' => '+225 07 00 00 00 00',
            'message' => 'Je souhaite une démonstration.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_contact_form_requires_company_and_email(): void
    {
        $response = $this->post(route('contact.send'), []);

        $response->assertSessionHasErrors(['company', 'email']);
    }
}
