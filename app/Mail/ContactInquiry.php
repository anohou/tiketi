<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactInquiry extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $company,
        public string $email,
        public ?string $phone,
        public ?string $content,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contact from TIKETI',
            replyTo: [new Address($this->email, $this->company)],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.contact-inquiry',
        );
    }
}
