<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Hestia SMTP Library
 * SMTP sender built on PHPMailer for HestiaCP Exim mail server.
 */
class Hestia_smtp {

    protected $CI;
    protected $account = null;
    protected $last_error = '';

    public function __construct() {
        $this->CI =& get_instance();
    }

    public function set_account($account) {
        $this->account = is_array($account) ? (object)$account : $account;
    }

    /**
     * Send email
     */
    public function send($to, $subject, $body_html, $attachments = array(), $cc = array(), $bcc = array(), $reply_to = null) {
        if (!$this->account) {
            $this->last_error = 'E-posta hesabı seçilmedi.';
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            // Server configuration
            $mail->isSMTP();
            $mail->Host       = $this->account->smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->account->smtp_username ?? $this->account->email;
            $mail->Password   = $this->account->smtp_password;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;

            $encryption = strtolower($this->account->smtp_encryption ?? 'ssl');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = !empty($this->account->smtp_port) ? (int)$this->account->smtp_port : 465;
            } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = !empty($this->account->smtp_port) ? (int)$this->account->smtp_port : 587;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
                $mail->Port       = !empty($this->account->smtp_port) ? (int)$this->account->smtp_port : 25;
            }

            // HestiaCP / Self-Signed SSL certificate options
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Sender
            $from_name = !empty($this->account->name) ? $this->account->name : $this->account->email;
            $mail->setFrom($this->account->email, $from_name);

            // Recipients
            $to_list = is_array($to) ? $to : explode(',', $to);
            foreach ($to_list as $recipient) {
                $recipient = trim($recipient);
                if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($recipient);
                }
            }

            // CC
            if (!empty($cc)) {
                $cc_list = is_array($cc) ? $cc : explode(',', $cc);
                foreach ($cc_list as $c) {
                    $c = trim($c);
                    if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
                        $mail->addCC($c);
                    }
                }
            }

            // BCC
            if (!empty($bcc)) {
                $bcc_list = is_array($bcc) ? $bcc : explode(',', $bcc);
                foreach ($bcc_list as $b) {
                    $b = trim($b);
                    if (filter_var($b, FILTER_VALIDATE_EMAIL)) {
                        $mail->addBCC($b);
                    }
                }
            }

            // Reply-To
            if (!empty($reply_to)) {
                $mail->addReplyTo($reply_to);
            }

            // Append signature if available
            if (!empty($this->account->signature)) {
                $body_html .= "<br><br>--<br>" . $this->account->signature;
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body_html;
            $mail->AltBody = strip_tags(str_replace(array('<br>', '<p>', '</p>'), array("\n", "\n", "\n\n"), $body_html));

            // Attachments
            if (!empty($attachments)) {
                foreach ($attachments as $att) {
                    if (is_array($att)) {
                        if (isset($att['tmp_name']) && (is_uploaded_file($att['tmp_name']) || file_exists($att['tmp_name']))) {
                            $mail->addAttachment($att['tmp_name'], $att['name']);
                        } elseif (isset($att['content']) && !empty($att['name'])) {
                            $mime = !empty($att['mime_type']) ? $att['mime_type'] : '';
                            $mail->addStringAttachment($att['content'], $att['name'], 'base64', $mime);
                        } elseif (isset($att['path']) && file_exists($att['path'])) {
                            $mail->addAttachment($att['path'], !empty($att['name']) ? $att['name'] : basename($att['path']));
                        }
                    } elseif (is_string($att) && file_exists($att)) {
                        $mail->addAttachment($att);
                    }
                }
            }

            $mail->send();

            // Return sent MIME message for IMAP Sent folder
            $raw_mime = $mail->getSentMIMEMessage();

            return array(
                'success' => true,
                'raw_mime' => $raw_mime
            );

        } catch (Exception $e) {
            $this->last_error = $mail->ErrorInfo ?: $e->getMessage();
            return false;
        }
    }

    /**
     * Test SMTP connection
     */
    public function test_connection($host, $port, $encryption, $username, $password) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->Timeout    = 10;

            $encryption = strtolower($encryption);
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = !empty($port) ? (int)$port : 465;
            } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = !empty($port) ? (int)$port : 587;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
                $mail->Port       = !empty($port) ? (int)$port : 25;
            }

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $time_start = microtime(true);
            $connected = $mail->smtpConnect();
            $latency = round((microtime(true) - time_start) * 1000);

            if ($connected) {
                $mail->smtpClose();
                return array(
                    'success' => true,
                    'message' => 'SMTP bağlantısı ve kimlik doğrulama başarılı! (' . $latency . ' ms)',
                    'latency' => $latency
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'SMTP sunucusuna bağlanılamadı.'
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $mail->ErrorInfo ?: $e->getMessage()
            );
        }
    }

    public function get_last_error() {
        return $this->last_error;
    }
}
