<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal operator-only alert; no backup object names, secrets, URLs,
 * exception messages, or tenant/owner records are included.
 */
final class BackupHealthAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $state) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Aqari Pro: backup monitoring alert');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.backup-health-alert',
            with: ['state' => $this->state]
        );
    }
}
