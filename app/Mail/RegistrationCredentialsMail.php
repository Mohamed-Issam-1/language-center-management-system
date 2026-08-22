<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;

class RegistrationCredentialsMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $accountLoginIdentifier,
        public readonly string $temporaryPassword,
        public readonly string $loginUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LCMS account is ready'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.registration.credentials'
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
