<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class BrevoTransport extends AbstractTransport
{
    protected function doSend(
        SentMessage $message
    ): void {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new \RuntimeException('Unsupported email message.');
        }

        $sender = $email->getFrom()[0] ?? new Address(
            config('mail.from.address'),
            config('mail.from.name') ?? ''
        );

        $recipients = fn (array $addresses) => array_map(
            fn (Address $address) => [
                'email' => $address->getAddress(),
                'name' => $address->getName() ?: $address->getAddress(),
            ],
            $addresses
        );

        $payload = [
            'sender' => [
                'email' => $sender->getAddress(),
                'name' => $sender->getName(),
            ],
            'to' => $recipients($email->getTo()),
            'subject' => $email->getSubject(),
        ];

        if ($email->getHtmlBody() !== null) {
            $payload['htmlContent'] = $email->getHtmlBody();
        }

        if ($email->getTextBody() !== null) {
            $payload['textContent'] = $email->getTextBody();
        }

        if ($email->getCc()) {
            $payload['cc'] = $recipients($email->getCc());
        }

        if ($email->getBcc()) {
            $payload['bcc'] = $recipients($email->getBcc());
        }

        if ($email->getReplyTo()) {
            $payload['replyTo'] = $recipients($email->getReplyTo())[0];
        }

        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => $attachment->getPreparedHeaders()
                    ->getHeaderParameter('Content-Disposition', 'filename')
                    ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
            ];
        }

        if ($attachments) {
            $payload['attachment'] = $attachments;
        }

        Http::withHeaders([
            'api-key' => config('services.brevo.api_key'),
            'accept' => 'application/json',
        ])
            ->timeout(30)
            ->post('https://api.brevo.com/v3/smtp/email', $payload)
            ->throw();
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}