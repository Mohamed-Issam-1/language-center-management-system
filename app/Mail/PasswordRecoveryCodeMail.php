<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordRecoveryCodeMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $verificationCode
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LCMS password recovery code'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.password-recovery-code'
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
