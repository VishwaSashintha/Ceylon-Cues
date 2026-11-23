<?php

declare(strict_types=1);

namespace CeylonCues\Services\Mail;

use CeylonCues\Core\Logger;

/**
 * SMTP Mail Service (Mailtrap-compatible for local dev)
 *
 * Uses PHP's mail() with SMTP or a socket-based send for Mailtrap.
 * In local/sandbox mode (APP_ENV=local|development) always uses Mailtrap credentials.
 *
 * Required .env variables:
 *   MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
 *   MAIL_FROM_ADDRESS, MAIL_FROM_NAME
 *
 * For Mailtrap sandbox: set MAIL_HOST=sandbox.smtp.mailtrap.io, MAIL_PORT=2525
 */
final class MailService
{
    private string $host;
    private int    $port;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $fromName;
    private bool   $enabled;

    public function __construct()
    {
        $this->host        = $_ENV['MAIL_HOST']         ?? '';
        $this->port        = (int)($_ENV['MAIL_PORT']   ?? 587);
        $this->username    = $_ENV['MAIL_USERNAME']     ?? '';
        $this->password    = $_ENV['MAIL_PASSWORD']     ?? '';
        $this->fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@ceyloncues.lk';
        $this->fromName    = $_ENV['MAIL_FROM_NAME']    ?? 'Ceylon Cues';
        $this->enabled     = !empty($this->host) && !empty($this->username);
    }

    /**
     * Send a plain-text + HTML email.
     *
     * @param string      $toAddress  Recipient email
     * @param string      $toName     Recipient display name
     * @param string      $subject    Email subject
     * @param string      $htmlBody   HTML body content
     * @param string|null $textBody   Plain-text fallback (auto-stripped from HTML if null)
     */
    public function send(
        string $toAddress,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $textBody = null
    ): bool {
        if (!$this->enabled) {
            Logger::logInfo('MailService: SMTP not configured — email logged only', [
                'to'      => $toAddress,
                'subject' => $subject,
            ]);
            return false;
        }

        $textBody ??= strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $boundary  = '==_Part_' . bin2hex(random_bytes(8));
        $messageId = '<' . bin2hex(random_bytes(16)) . '@ceyloncues.lk>';

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "From: {$this->fromName} <{$this->fromAddress}>\r\n";
        $headers .= "To: {$toName} <{$toAddress}>\r\n";
        $headers .= "Message-ID: {$messageId}\r\n";
        $headers .= "X-Mailer: CeylonCues/1.0\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // Use socket-based SMTP so Mailtrap sandbox credentials work on localhost
        return $this->sendViaSMTP($toAddress, $toName, $subject, $headers, $body);
    }

    private function sendViaSMTP(
        string $toAddress,
        string $toName,
        string $subject,
        string $headers,
        string $body
    ): bool {
        $errno  = 0;
        $errstr = '';
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$socket) {
            Logger::logError("MailService: Cannot connect to {$this->host}:{$this->port} — {$errstr}");
            return false;
        }

        try {
            $this->smtpRead($socket);                                           // 220 greeting
            $this->smtpWrite($socket, "EHLO ceyloncues.lk\r\n");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, "AUTH LOGIN\r\n");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, base64_encode($this->username) . "\r\n");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, base64_encode($this->password) . "\r\n");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, "MAIL FROM:<{$this->fromAddress}>\r\n");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, "RCPT TO:<{$toAddress}>\r\n");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, "DATA\r\n");
            $this->smtpRead($socket);

            $message  = "Subject: {$subject}\r\n";
            $message .= $headers;
            $message .= "\r\n" . $body . "\r\n.\r\n";
            $this->smtpWrite($socket, $message);
            $this->smtpRead($socket);

            $this->smtpWrite($socket, "QUIT\r\n");
            fclose($socket);

            Logger::logInfo("MailService: Email sent to {$toAddress}", ['subject' => $subject]);
            return true;
        } catch (\Throwable $e) {
            fclose($socket);
            Logger::logError("MailService: Send failed — " . $e->getMessage());
            return false;
        }
    }

    private function smtpWrite($socket, string $data): void
    {
        fwrite($socket, $data);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    // ── Pre-built email templates ─────────────────────────────────────────

    /**
     * Send order confirmation email to customer.
     */
    public function sendOrderConfirmation(
        string $toEmail,
        string $toName,
        string $orderRef,
        float  $orderTotal,
        array  $items
    ): bool {
        $appUrl  = rtrim($_ENV['APP_URL'] ?? 'http://localhost/ceylon-cues', '/');
        $subject = "Order Confirmed — #{$orderRef} | Ceylon Cues";

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<tr>'
                . '<td style="padding:6px 12px;">' . htmlspecialchars((string)($item['name'] ?? '')) . '</td>'
                . '<td style="padding:6px 12px;text-align:center;">' . (int)($item['quantity'] ?? 1) . '</td>'
                . '<td style="padding:6px 12px;text-align:right;">Rs. ' . number_format((float)($item['unit_price'] ?? 0), 2) . '</td>'
                . '</tr>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
  <div style="max-width:600px;margin:auto;background:#fff;border-radius:8px;overflow:hidden;">
    <div style="background:#1a1a2e;color:#fff;padding:24px;">
      <h1 style="margin:0;font-size:22px;">Ceylon Cues</h1>
      <p style="margin:4px 0 0;color:#ccc;">Order Confirmation</p>
    </div>
    <div style="padding:24px;">
      <p>Dear <strong>{$toName}</strong>,</p>
      <p>Thank you for your order. We have received it and are processing it now.</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;">
        <tr style="background:#f8f8f8;">
          <th style="padding:8px 12px;text-align:left;">Item</th>
          <th style="padding:8px 12px;text-align:center;">Qty</th>
          <th style="padding:8px 12px;text-align:right;">Price</th>
        </tr>
        {$itemsHtml}
        <tr>
          <td colspan="2" style="padding:12px;text-align:right;font-weight:bold;">Total</td>
          <td style="padding:12px;text-align:right;font-weight:bold;">Rs. {$orderTotal}</td>
        </tr>
      </table>
      <p><strong>Order Reference:</strong> #{$orderRef}</p>
      <a href="{$appUrl}/orders/{$orderRef}" style="display:inline-block;background:#e63946;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;margin-top:12px;">Track Your Order</a>
      <p style="margin-top:24px;color:#666;font-size:13px;">If you have questions, reply to this email or contact us at support@ceyloncues.lk</p>
    </div>
  </div>
</body>
</html>
HTML;
        return $this->send($toEmail, $toName, $subject, $html);
    }

    /**
     * Send order shipped email to customer.
     */
    public function sendOrderShipped(
        string $toEmail,
        string $toName,
        string $orderRef,
        string $carrier,
        string $trackingNumber
    ): bool {
        $subject = "Your Order Has Shipped — #{$orderRef} | Ceylon Cues";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
  <div style="max-width:600px;margin:auto;background:#fff;border-radius:8px;overflow:hidden;">
    <div style="background:#1a1a2e;color:#fff;padding:24px;">
      <h1 style="margin:0;font-size:22px;">Ceylon Cues</h1>
      <p style="margin:4px 0 0;color:#ccc;">Your order is on the way!</p>
    </div>
    <div style="padding:24px;">
      <p>Dear <strong>{$toName}</strong>,</p>
      <p>Great news! Your order <strong>#{$orderRef}</strong> has been shipped.</p>
      <table width="100%" style="border-collapse:collapse;margin:16px 0;background:#f8f8f8;border-radius:4px;">
        <tr><td style="padding:12px;font-weight:bold;width:160px;">Carrier</td><td style="padding:12px;">{$carrier}</td></tr>
        <tr><td style="padding:12px;font-weight:bold;">Tracking #</td><td style="padding:12px;">{$trackingNumber}</td></tr>
      </table>
      <p style="margin-top:24px;color:#666;font-size:13px;">Questions? Contact us at support@ceyloncues.lk</p>
    </div>
  </div>
</body>
</html>
HTML;
        return $this->send($toEmail, $toName, $subject, $html);
    }
}
