<?php

namespace SynergiTech\Postal;

use Postal\Client;
use Postal\ApiException;
use Postal\Send\Message as SendMessage;
use Postal\Send\Result as SendResult;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

class PostalTransport extends AbstractTransport
{
    public function __construct(
        protected Client $client
    )
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $sentMessage): void
    {
        /** @var Message $originalMessage */
        $originalMessage = $sentMessage->getOriginalMessage();
        $symfonyMessage = MessageConverter::toEmail($originalMessage);

        $postalmessage = $this->symfonyToPostal($symfonyMessage);

        try {
            $response = $this->client->send->message($postalmessage);
        } catch (ApiException $error) {
            throw new TransportException($error->getMessage(), $error->getCode(), $error);
        }

        $headers = $symfonyMessage->getHeaders();

        // send known header back for laravel to match emails coming out of Postal
        // - doesn't seem we can replace Message-ID
        $headers->addTextHeader('Postal-Message-ID', $response->message_id);

        if (config('postal.enable.emaillogging') !== true) {
            return;
        }

        $this->recordEmailsFromResponse($symfonyMessage, $response);

        $emailable_type = $headers->get('notifiable_class')?->getBody();
        $emailable_id = $headers->get('notifiable_id')?->getBody();

        // headers only set if using PostalNotificationChannel
        if ($emailable_type != '' && $emailable_id != '') {
            $emailmodel = config('postal.models.email');
            \DB::table((new $emailmodel)->getTable())
                ->where('postal_email_id', $response->message_id)
                ->update([
                    'emailable_type' => $emailable_type,
                    'emailable_id' => $emailable_id,
                ]);
        }
    }

    /**
     * Convert symfony message into a Postal sendmessage
     */
    private function symfonyToPostal(Email $symfonyMessage): SendMessage
    {
        // SendMessage cannot be reset so must be instantiated for each use
        $postalMessage = $this->getNewSendMessage();

        $recipients = [];
        foreach (['to', 'cc', 'bcc'] as $type) {
            foreach ((array) $symfonyMessage->{'get' . ucwords($type)}() as $symfonyAddress) {
                // dedup recipients
                if (! in_array($symfonyAddress->getAddress(), $recipients)) {
                    $recipients[] = $symfonyAddress->getAddress();
                    $postalMessage->{$type}($this->stringifyAddress($symfonyAddress));
                }
            }
        }

        foreach ($symfonyMessage->getFrom() as $symfonyAddress) {
            $postalMessage->from($this->stringifyAddress($symfonyAddress));
        }

        foreach ($symfonyMessage->getReplyTo() as $symfonyAddress) {
            $postalMessage->replyTo($this->stringifyAddress($symfonyAddress));
        }

        if ($symfonyMessage->getSubject()) {
            $postalMessage->subject($symfonyMessage->getSubject());
        }

        if ($symfonyMessage->getHeaders()) {
            foreach ($this->parseHeadersToKeyValue($symfonyMessage->getHeaders()->toArray()) as $key => $value) {
                $postalMessage->header($key, $value);
            }
        }

        if ($symfonyMessage->getTextBody()) {
            $postalMessage->plainBody($symfonyMessage->getTextBody());
        }
        if ($symfonyMessage->getHtmlBody()) {
            $postalMessage->htmlBody($symfonyMessage->getHtmlBody());
        }

        foreach ($symfonyMessage->getAttachments() as $index => $symfonyPart) {
            $filename = $symfonyPart
                ->getPreparedHeaders()
                ->getHeaderParameter('content-disposition', 'filename');

            $postalMessage->attach(
                $filename ?? "attached_file_$index",
                $symfonyPart->getMediaType() . '/' . $symfonyPart->getMediaSubtype(),
                $symfonyPart->getBody()
            );
        }

        return $postalMessage;
    }

    /**
     * Preserve emails within database for later accounting with webhooks
     */
    private function recordEmailsFromResponse(Email $symfonyMessage, SendResult $response): void
    {
        $recipients = [];

        // postals native libraries lowercase the email address but we still have the cased versions
        // in the swift message so rearrange what we have to get the best data out
        foreach (['to', 'cc', 'bcc'] as $type) {
            foreach ((array) $symfonyMessage->{'get' . ucwords($type)}() as $symfonyAddress) {
                $recipients[strtolower($symfonyAddress->getAddress())] = [
                    'email' => $symfonyAddress->getAddress(),
                    'name' => $symfonyAddress->getName(),
                ];
            }
        }

        $senderAddress = $symfonyMessage->getFrom();
        $senderAddress = reset($senderAddress);

        $emailModel = config('postal.models.email');

        foreach ($response->recipients() as $address => $message) {
            $email = new $emailModel;

            $email->to_email = $recipients[$address]['email'];
            $email->to_name = $recipients[$address]['name'];

            $email->from_email = $senderAddress ? $senderAddress->getAddress() : '';
            $email->from_name = $senderAddress ? $senderAddress->getName() : '';

            $email->subject = $symfonyMessage->getSubject();

            if ($symfonyMessage->getTextBody()) {
                $email->body = $symfonyMessage->getTextBody();
            } elseif ($symfonyMessage->getHtmlBody()) {
                $email->body = $symfonyMessage->getHtmlBody();
            }

            $email->postal_email_id = $response->message_id;
            $email->postal_id = $message->id;
            $email->postal_token = $message->token;

            $email->save();
        }
    }

    private function stringifyAddress(Address $address): string
    {
        if ($address->getName() != null) {
            return $address->getName() . ' <' . $address->getAddress() . '>';
        }

        return $address->getAddress();
    }

    private function getNewSendMessage(): SendMessage
    {
        return new SendMessage();
    }

    function parseHeadersToKeyValue(array $headers): array
    {
        $parsedHeaders = [];

        foreach ($headers as $header) {
            $colonPos = strpos($header, ':');

            if ($colonPos !== false) {
                $key = trim(substr($header, 0, $colonPos));
                $value = trim(substr($header, $colonPos + 1));

                if (isset($parsedHeaders[$key])) {
                    $parsedHeaders[$key] .= ' ' . $value;
                } else {
                    $parsedHeaders[$key] = $value;
                }
            }
        }

        return $parsedHeaders;
    }


    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'postal';
    }
}
