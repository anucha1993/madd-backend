<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain-text report email — used both for the SMTP "send test email" action and the actual
 * scheduled report delivery (with an .xlsx attachment) in SendScheduledReports.
 */
class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $bodyText,
        public ?string $attachmentContent = null,
        public ?string $attachmentName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->bodyText)));
    }

    public function attachments(): array
    {
        if (! $this->attachmentContent || ! $this->attachmentName) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->attachmentContent, $this->attachmentName)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
