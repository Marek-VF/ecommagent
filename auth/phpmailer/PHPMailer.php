<?php
declare(strict_types=1);

namespace PHPMailer\PHPMailer;

use function filter_var;
use function implode;
use function mail;
use function sprintf;
use const FILTER_VALIDATE_EMAIL;

class PHPMailer
{
    public const ENCRYPTION_STARTTLS = 'tls';
    public const ENCRYPTION_SMTPS = 'ssl';

    public bool $SMTPAuth = true;
    public string $Host = 'localhost';
    public int $Port = 25;
    public string $SMTPSecure = self::ENCRYPTION_STARTTLS;
    public string $Username = '';
    public string $Password = '';
    public string $Subject = '';
    public string $Body = '';
    public string $AltBody = '';

    /** @var array<int, array{address:string,name:string}> */
    private array $recipients = [];
    private bool $isHtml = false;
    private array $from = ['address' => '', 'name' => ''];

    public function __construct(private bool $exceptions = false)
    {
    }

    public function isSMTP(): void
    {
        // Flag is kept for compatibility. The simplified implementation relies on PHP's mail() function.
    }

    public function setFrom(string $address, string $name = ''): void
    {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new Exception('Ungültige Absenderadresse.');
        }

        $this->from = ['address' => $address, 'name' => $name];
    }

    public function addAddress(string $address, string $name = ''): void
    {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new Exception('Ungültige Empfängeradresse.');
        }

        $this->recipients[] = ['address' => $address, 'name' => $name];
    }

    public function isHTML(bool $isHtml = true): void
    {
        $this->isHtml = $isHtml;
    }

    public function send(): bool
    {
        if (empty($this->recipients)) {
            throw new Exception('Kein Empfänger für den Versand angegeben.');
        }

        $message = $this->Body !== '' ? $this->Body : $this->AltBody;
        if ($message === '') {
            throw new Exception('Nachrichteninhalt fehlt.');
        }

        $headers = $this->buildHeaders();
        $compiledHeaders = implode("\r\n", $headers);

        $success = true;
        foreach ($this->recipients as $recipient) {
            $success = mail($recipient['address'], $this->Subject, $message, $compiledHeaders) && $success;
        }

        if (!$success) {
            throw new Exception('Versand fehlgeschlagen.');
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function buildHeaders(): array
    {
        $headers = [];

        if ($this->from['address'] !== '') {
            $headers[] = 'From: ' . $this->formatAddress($this->from);
        }

        $headers[] = 'Reply-To: ' . ($this->from['address'] !== '' ? $this->formatAddress($this->from) : 'no-reply@example.com');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = $this->isHtml
            ? 'Content-Type: text/html; charset=UTF-8'
            : 'Content-Type: text/plain; charset=UTF-8';

        return $headers;
    }

    /**
     * @param array{address:string,name:string} $address
     */
    private function formatAddress(array $address): string
    {
        $name = trim($address['name']);
        $email = $address['address'];

        if ($name === '') {
            return $email;
        }

        return sprintf('%s <%s>', $name, $email);
    }
}
