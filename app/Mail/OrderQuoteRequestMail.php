<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Ordine;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrderQuoteRequestMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly Ordine $ordine,
        private readonly string $pdfPath,
        private readonly string $pdfFileName,
    ) {}

    public function envelope(): Envelope
    {
        $reference = trim((string) ($this->ordine->riferimento_cliente ?? ''));
        $subjectSuffix = $reference !== ''
            ? 'Conferma '.$reference
            : 'Ordine '.$this->ordine->id;

        return new Envelope(
            subject: 'Ordine cliente - richiesta preventivo '.$subjectSuffix,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.quote-request',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('public', $this->pdfPath)
                ->as($this->pdfFileName)
                ->withMime('application/pdf'),
        ];
    }
}
