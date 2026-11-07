<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Hestia IMAP Library
 * High-performance IMAP client tailored for HestiaCP (Dovecot) and standard IMAP servers.
 */
class Hestia_imap {

    protected $CI;
    protected $connection = null;
    protected $current_mailbox = 'INBOX';
    protected $account = null;
    protected $last_error = '';

    public function __construct() {
        $this->CI =& get_instance();
    }

    /**
     * Set account credentials
     */
    public function set_account($account) {
        $this->account = is_array($account) ? (object)$account : $account;
        if ($this->connection) {
            $this->disconnect();
        }
    }

    /**
     * Connect to IMAP server
     */
    public function connect($folder = 'INBOX') {
        if (!function_exists('imap_open')) {
            $this->last_error = 'PHP IMAP eklentisi (php_imap) aktif değil. php.ini dosyasından extension=imap satırını aktif ediniz.';
            return false;
        }

        if (!$this->account) {
            $this->last_error = 'E-posta hesabı seçilmedi.';
            return false;
        }

        if ($this->connection && $this->current_mailbox === $folder) {
            return $this->connection;
        }

        if ($this->connection) {
            $this->disconnect();
        }

        $host = $this->account->imap_host;
        $port = !empty($this->account->imap_port) ? (int)$this->account->imap_port : 993;
        $encryption = strtolower($this->account->imap_encryption ?? 'ssl');
        $username = $this->account->imap_username ?? $this->account->email;
        $password = $this->account->imap_password;

        // Build mailbox connection string
        // /novalidate-cert is critical for self-signed or new Let's Encrypt certificates on HestiaCP
        $flags = '';
        if ($encryption === 'ssl') {
            $flags = "/imap/ssl/novalidate-cert";
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $flags = "/imap/tls/novalidate-cert";
        } else {
            $flags = "/imap/notls";
        }

        $encoded_folder = $this->encode_utf7($folder);
        $server_spec = "{" . "{$host}:{$port}{$flags}" . "}" . $encoded_folder;

        // Suppress warnings and capture errors
        $old_error_reporting = error_reporting(0);
        $conn = @imap_open($server_spec, $username, $password, 0, 1, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));
        error_reporting($old_error_reporting);

        if (!$conn) {
            $errors = imap_errors();
            $last = imap_last_error();
            $this->last_error = !empty($errors) ? implode(', ', $errors) : ($last ? $last : 'IMAP sunucusuna bağlanılamadı.');
            return false;
        }

        $this->connection = $conn;
        $this->current_mailbox = $folder;
        return $this->connection;
    }

    /**
     * Disconnect IMAP
     */
    public function disconnect() {
        if ($this->connection) {
            @imap_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Test connection
     */
    public function test_connection($host, $port, $encryption, $username, $password) {
        if (!function_exists('imap_open')) {
            return array(
                'success' => false,
                'message' => 'PHP IMAP eklentisi (php_imap) aktif değil. php.ini dosyasından extension=imap satırını aktif ediniz.'
            );
        }

        $flags = '';
        $encryption = strtolower($encryption);
        if ($encryption === 'ssl') {
            $flags = "/imap/ssl/novalidate-cert";
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $flags = "/imap/tls/novalidate-cert";
        } else {
            $flags = "/imap/notls";
        }

        $server_spec = "{" . "{$host}:{$port}{$flags}" . "}INBOX";
        
        $old_error_reporting = error_reporting(0);
        $time_start = microtime(true);
        $conn = @imap_open($server_spec, $username, $password, OP_HALFOPEN, 1);
        $latency = round((microtime(true) - time_start) * 1000);
        error_reporting($old_error_reporting);

        if ($conn) {
            $boxes = @imap_getmailboxes($conn, "{" . "{$host}:{$port}{$flags}" . "}", "*");
            $folders = array();
            if ($boxes) {
                foreach ($boxes as $box) {
                    $name = $this->decode_utf7(str_replace("{" . "{$host}:{$port}{$flags}" . "}", "", $box->name));
                    $folders[] = $name;
                }
            }
            @imap_close($conn);
            return array(
                'success' => true,
                'message' => 'IMAP bağlantısı başarılı! (' . $latency . ' ms)',
                'latency' => $latency,
                'folders' => $folders
            );
        } else {
            $errors = imap_errors();
            $last = imap_last_error();
            return array(
                'success' => false,
                'message' => !empty($errors) ? implode('; ', $errors) : ($last ? $last : 'IMAP bağlantı hatası oluştu.')
            );
        }
    }

    /**
     * Get Mailbox folders
     */
    public function get_mailboxes() {
        if (!$this->connect('INBOX')) {
            return array();
        }

        $host = $this->account->imap_host;
        $port = !empty($this->account->imap_port) ? (int)$this->account->imap_port : 993;
        $encryption = strtolower($this->account->imap_encryption ?? 'ssl');
        $flags = ($encryption === 'ssl') ? "/imap/ssl/novalidate-cert" : (($encryption === 'tls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
        $prefix = "{" . "{$host}:{$port}{$flags}" . "}";

        $boxes = @imap_getmailboxes($this->connection, $prefix, "*");
        $list = array();

        $standard_types = array(
            'inbox'   => array('title' => 'Gelen Kutusu', 'icon' => 'inbox', 'color' => 'blue'),
            'sent'    => array('title' => 'Gönderilenler', 'icon' => 'send', 'color' => 'green'),
            'drafts'  => array('title' => 'Taslaklar', 'icon' => 'file-text', 'color' => 'amber'),
            'trash'   => array('title' => 'Çöp Kutusu', 'icon' => 'trash-2', 'color' => 'rose'),
            'junk'    => array('title' => 'İstenmeyen / Spam', 'icon' => 'alert-octagon', 'color' => 'orange'),
            'archive' => array('title' => 'Arşiv', 'icon' => 'archive', 'color' => 'purple'),
        );

        if ($boxes) {
            foreach ($boxes as $box) {
                $raw_name = str_replace($prefix, "", $box->name);
                $decoded_name = $this->decode_utf7($raw_name);
                
                // Determine folder category
                $lower = strtolower($decoded_name);
                $type = 'custom';
                if ($lower === 'inbox') {
                    $type = 'inbox';
                } elseif (strpos($lower, 'sent') !== false || strpos($lower, 'gönder') !== false) {
                    $type = 'sent';
                } elseif (strpos($lower, 'draft') !== false || strpos($lower, 'taslak') !== false) {
                    $type = 'drafts';
                } elseif (strpos($lower, 'trash') !== false || strpos($lower, 'çöp') !== false || strpos($lower, 'silin') !== false || strpos($lower, 'bin') !== false) {
                    $type = 'trash';
                } elseif (strpos($lower, 'junk') !== false || strpos($lower, 'spam') !== false) {
                    $type = 'junk';
                } elseif (strpos($lower, 'archive') !== false || strpos($lower, 'arşiv') !== false) {
                    $type = 'archive';
                }

                // Check message counts
                $status = @imap_status($this->connection, $box->name, SA_ALL);
                $total = $status ? (int)$status->messages : 0;
                $unread = $status ? (int)$status->unseen : 0;

                $meta = $standard_types[$type] ?? array(
                    'title' => $decoded_name,
                    'icon' => 'folder',
                    'color' => 'slate'
                );

                $list[] = array(
                    'name' => $decoded_name,
                    'raw_name' => $raw_name,
                    'display_name' => $meta['title'],
                    'type' => $type,
                    'icon' => $meta['icon'],
                    'color' => $meta['color'],
                    'total' => $total,
                    'unread' => $unread
                );
            }
        }

        // Sort: INBOX first, then Sent, Drafts, Trash, Junk, Archive, then custom
        $order = array('inbox' => 1, 'sent' => 2, 'drafts' => 3, 'archive' => 4, 'junk' => 5, 'trash' => 6, 'custom' => 7);
        usort($list, function($a, $b) use ($order) {
            $oa = $order[$a['type']] ?? 99;
            $ob = $order[$b['type']] ?? 99;
            if ($oa === $ob) return strcmp($a['display_name'], $b['display_name']);
            return $oa - $ob;
        });

        return $list;
    }

    /**
     * Get emails list with pagination and search
     */
    public function get_messages($folder = 'INBOX', $page = 1, $per_page = 25, $search = null, $filter = null) {
        if (!$this->connect($folder)) {
            return array(
                'messages' => array(),
                'total' => 0,
                'pages' => 1,
                'current_page' => $page,
                'folder' => $folder,
                'error' => $this->last_error
            );
        }

        $search_criteria = 'ALL';
        if (!empty($search)) {
            $search_safe = addslashes($search);
            $search_criteria = 'OR SUBJECT "' . $search_safe . '" FROM "' . $search_safe . '"';
        }
        if ($filter === 'unread') {
            $search_criteria = ($search_criteria === 'ALL') ? 'UNSEEN' : 'UNSEEN ' . $search_criteria;
        } elseif ($filter === 'starred') {
            $search_criteria = ($search_criteria === 'ALL') ? 'FLAGGED' : 'FLAGGED ' . $search_criteria;
        }

        // Search messages
        $msg_nums = @imap_search($this->connection, $search_criteria, SE_FREE, "UTF-8");
        if (!$msg_nums) {
            // Fallback without UTF-8 charset specification if server doesn't support it
            $msg_nums = @imap_search($this->connection, $search_criteria);
        }

        if (empty($msg_nums)) {
            return array(
                'messages' => array(),
                'total' => 0,
                'pages' => 1,
                'current_page' => $page,
                'folder' => $folder
            );
        }

        // Sort descending (newest first)
        rsort($msg_nums);

        $total = count($msg_nums);
        $pages = max(1, (int)ceil($total / $per_page));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $per_page;
        $page_nums = array_slice($msg_nums, $offset, $per_page);

        $messages = array();
        if (!empty($page_nums)) {
            $sequence = implode(',', $page_nums);
            $overviews = @imap_fetch_overview($this->connection, $sequence);
            
            // Map overviews by msgno
            $overview_map = array();
            if ($overviews) {
                foreach ($overviews as $ov) {
                    $overview_map[$ov->msgno] = $ov;
                }
            }

            foreach ($page_nums as $msg_no) {
                $ov = $overview_map[$msg_no] ?? null;
                $uid = $ov ? $ov->uid : @imap_uid($this->connection, $msg_no);
                
                $subject = $ov && isset($ov->subject) ? $this->decode_mime($ov->subject) : '(Konusuz)';
                if (trim($subject) === '') $subject = '(Konusuz)';

                $from = $ov && isset($ov->from) ? $this->decode_mime($ov->from) : 'Bilinmeyen Gönderen';
                $from_parsed = $this->parse_address($from);

                $to = $ov && isset($ov->to) ? $this->decode_mime($ov->to) : '';
                $to_parsed = $this->parse_address($to);

                $date_raw = $ov && isset($ov->date) ? $ov->date : '';
                $timestamp = !empty($date_raw) ? strtotime($date_raw) : time();
                $formatted_date = $this->format_date($timestamp);

                $is_seen = $ov && !empty($ov->seen);
                $is_flagged = $ov && !empty($ov->flagged);
                $is_answered = $ov && !empty($ov->answered);
                $size = $ov && isset($ov->size) ? $this->format_size($ov->size) : '';

                // Quick check for attachments
                $has_attachment = false;
                $structure = @imap_fetchstructure($this->connection, $msg_no);
                if ($structure && isset($structure->parts) && count($structure->parts) > 1) {
                    $has_attachment = $this->check_has_attachment($structure);
                }

                $messages[] = array(
                    'uid' => $uid,
                    'msg_no' => $msg_no,
                    'subject' => $subject,
                    'from' => $from_parsed,
                    'to' => $to_parsed,
                    'date' => $formatted_date,
                    'timestamp' => $timestamp,
                    'is_seen' => $is_seen,
                    'is_flagged' => $is_flagged,
                    'is_answered' => $is_answered,
                    'size' => $size,
                    'has_attachment' => $has_attachment
                );
            }
        }

        return array(
            'messages' => $messages,
            'total' => $total,
            'pages' => $pages,
            'current_page' => $page,
            'folder' => $folder
        );
    }

    /**
     * Get single email by UID
     */
    public function get_message($folder, $uid) {
        if (!$this->connect($folder)) {
            return false;
        }

        $msg_no = @imap_msgno($this->connection, $uid);
        if (!$msg_no) {
            return false;
        }

        $header_info = @imap_headerinfo($this->connection, $msg_no);
        $structure = @imap_fetchstructure($this->connection, $msg_no);

        $subject = isset($header_info->subject) ? $this->decode_mime($header_info->subject) : '(Konusuz)';
        if (trim($subject) === '') $subject = '(Konusuz)';

        $from_str = isset($header_info->fromaddress) ? $this->decode_mime($header_info->fromaddress) : '';
        $from = $this->parse_address($from_str);

        $to_str = isset($header_info->toaddress) ? $this->decode_mime($header_info->toaddress) : '';
        $to = $this->parse_address($to_str);

        $cc_str = isset($header_info->ccaddress) ? $this->decode_mime($header_info->ccaddress) : '';
        $cc = !empty($cc_str) ? $this->parse_address($cc_str) : null;

        $reply_to_str = isset($header_info->reply_toaddress) ? $this->decode_mime($header_info->reply_toaddress) : '';
        $reply_to = !empty($reply_to_str) ? $this->parse_address($reply_to_str) : null;

        $date_raw = isset($header_info->date) ? $header_info->date : '';
        $timestamp = !empty($date_raw) ? strtotime($date_raw) : time();
        $formatted_date = date('d.m.Y H:i', $timestamp);

        // Fetch body & attachments
        $body_plain = '';
        $body_html = '';
        $attachments = array();

        $this->parse_parts($msg_no, $structure, "", $body_plain, $body_html, $attachments);

        // Fallback if structure parsing didn't find body
        if (empty($body_plain) && empty($body_html)) {
            $raw_body = @imap_body($this->connection, $msg_no);
            $body_plain = $raw_body ? $this->decode_mime($raw_body) : '';
        }

        // Sanitize HTML body for security
        $safe_html = !empty($body_html) ? $this->sanitize_html($body_html) : '';

        // Auto mark as read
        @imap_setflag_full($this->connection, (string)$uid, "\\Seen", ST_UID);

        return array(
            'uid' => $uid,
            'msg_no' => $msg_no,
            'folder' => $folder,
            'subject' => $subject,
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'reply_to' => $reply_to,
            'date' => $formatted_date,
            'timestamp' => $timestamp,
            'is_seen' => true,
            'is_flagged' => isset($header_info->Flagged) && $header_info->Flagged === 'F',
            'is_spam' => $this->is_spam_folder($folder),
            'body_html' => $safe_html,
            'body_plain' => $body_plain,
            'has_html' => !empty($safe_html),
            'attachments' => $attachments
        );
    }

    /**
     * Parse MIME message parts
     */
    protected function parse_parts($msg_no, $part, $part_no, &$body_plain, &$body_html, &$attachments) {
        if (!$part) return;

        // Subparts
        if (isset($part->parts) && count($part->parts) > 0) {
            foreach ($part->parts as $index => $sub_part) {
                $sub_part_no = empty($part_no) ? ($index + 1) : ($part_no . "." . ($index + 1));
                $this->parse_parts($msg_no, $sub_part, $sub_part_no, $body_plain, $body_html, $attachments);
            }
            return;
        }

        $part_number = empty($part_no) ? 1 : $part_no;
        $data = @imap_fetchbody($this->connection, $msg_no, $part_number);

        // Decode data
        if ($part->encoding == 4) {
            $data = quoted_printable_decode($data);
        } elseif ($part->encoding == 3) {
            $data = base64_decode($data);
        }

        // Character encoding conversion to UTF-8
        $params = array();
        if ($part->ifparameters) {
            foreach ($part->parameters as $p) {
                $params[strtolower($p->attribute)] = $p->value;
            }
        }
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $p) {
                $params[strtolower($p->attribute)] = $p->value;
            }
        }

        $charset = $params['charset'] ?? 'UTF-8';
        if (strtoupper($charset) !== 'UTF-8') {
            $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
            if ($converted !== false && !empty($converted)) {
                $data = $converted;
            }
        }

        // Filename check (is it an attachment?)
        $filename = '';
        if (isset($params['filename'])) {
            $filename = $params['filename'];
        } elseif (isset($params['name'])) {
            $filename = $params['name'];
        }

        $disposition = isset($part->disposition) ? strtolower($part->disposition) : '';
        $is_attachment = ($disposition === 'attachment' || !empty($filename));

        if ($is_attachment) {
            $filename = !empty($filename) ? $this->decode_mime($filename) : ('attachment_' . $part_number);
            $mime_type = $this->get_mime_type($part);
            $size = isset($part->bytes) ? $this->format_size($part->bytes) : $this->format_size(strlen($data));

            $attachments[] = array(
                'part_no' => $part_number,
                'filename' => $filename,
                'mime_type' => $mime_type,
                'size' => $size,
                'bytes' => strlen($data)
            );
        } else {
            // Text or HTML
            if ($part->type == 0) { // Text
                if (strtolower($part->subtype) == 'plain') {
                    $body_plain .= $data;
                } elseif (strtolower($part->subtype) == 'html') {
                    $body_html .= $data;
                }
            }
        }
    }

    /**
     * Get attachment content
     */
    public function get_attachment($folder, $uid, $part_no) {
        if (!$this->connect($folder)) {
            return false;
        }

        $msg_no = @imap_msgno($this->connection, $uid);
        if (!$msg_no) return false;

        $structure = @imap_fetchstructure($this->connection, $msg_no);
        $part = $this->find_part_by_number($structure, $part_no);
        if (!$part) return false;

        $data = @imap_fetchbody($this->connection, $msg_no, $part_no);
        if ($part->encoding == 4) {
            $data = quoted_printable_decode($data);
        } elseif ($part->encoding == 3) {
            $data = base64_decode($data);
        }

        $filename = 'download';
        if ($part->ifparameters) {
            foreach ($part->parameters as $p) {
                if (strtolower($p->attribute) == 'name' || strtolower($p->attribute) == 'filename') {
                    $filename = $this->decode_mime($p->value);
                    break;
                }
            }
        }
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $p) {
                if (strtolower($p->attribute) == 'name' || strtolower($p->attribute) == 'filename') {
                    $filename = $this->decode_mime($p->value);
                    break;
                }
            }
        }

        return array(
            'filename' => $filename,
            'mime_type' => $this->get_mime_type($part),
            'content' => $data,
            'size' => strlen($data)
        );
    }

    /**
     * Helper to find part by dotted number
     */
    protected function find_part_by_number($structure, $part_no) {
        $parts_path = explode('.', $part_no);
        $current = $structure;
        foreach ($parts_path as $idx) {
            $n = (int)$idx - 1;
            if (isset($current->parts[$n])) {
                $current = $current->parts[$n];
            } else {
                return $current;
            }
        }
        return $current;
    }

    /**
     * Check if structure has attachments
     */
    protected function check_has_attachment($part) {
        if (isset($part->parts)) {
            foreach ($part->parts as $sub) {
                if ($this->check_has_attachment($sub)) return true;
            }
        }
        $disposition = isset($part->disposition) ? strtolower($part->disposition) : '';
        if ($disposition === 'attachment') return true;
        if ($part->ifparameters) {
            foreach ($part->parameters as $p) {
                if (strtolower($p->attribute) == 'filename' || strtolower($p->attribute) == 'name') return true;
            }
        }
        return false;
    }

    /**
     * Mark message as seen / unseen
     */
    public function set_flag($folder, $uid, $flag, $set = true) {
        if (!$this->connect($folder)) return false;
        if ($set) {
            return @imap_setflag_full($this->connection, (string)$uid, $flag, ST_UID);
        } else {
            return @imap_clearflag_full($this->connection, (string)$uid, $flag, ST_UID);
        }
    }

    /**
     * Delete email (move to Trash or expunge)
     */
    public function delete_message($folder, $uid) {
        if (!$this->connect($folder)) return false;

        // If not in Trash already, try moving to Trash
        $is_trash = (strpos(strtolower($folder), 'trash') !== false || strpos(strtolower($folder), 'çöp') !== false);
        if (!$is_trash) {
            // Find Trash folder name
            $mailboxes = $this->get_mailboxes();
            $trash_folder = null;
            foreach ($mailboxes as $box) {
                if ($box['type'] === 'trash') {
                    $trash_folder = $box['name'];
                    break;
                }
            }

            if ($trash_folder && $trash_folder !== $folder) {
                $encoded_target = $this->encode_utf7($trash_folder);
                $moved = @imap_mail_move($this->connection, (string)$uid, $encoded_target, CP_UID);
                if ($moved) {
                    @imap_expunge($this->connection);
                    return true;
                }
            }
        }

        // Direct delete & expunge
        @imap_delete($this->connection, (string)$uid, FT_UID);
        @imap_expunge($this->connection);
        return true;
    }

    /**
     * Check if a folder is a Junk/Spam folder
     */
    public function is_spam_folder($folder) {
        $lower = strtolower($folder);
        return (strpos($lower, 'junk') !== false || strpos($lower, 'spam') !== false || strpos($lower, 'istenmeyen') !== false);
    }

    /**
     * Get target Spam folder name
     */
    public function get_spam_folder() {
        $mailboxes = $this->get_mailboxes();
        foreach ($mailboxes as $box) {
            if ($box['type'] === 'junk') {
                return $box['name'];
            }
        }

        // Try creating standard Junk folder if not found
        if ($this->connection && $this->account) {
            $host = $this->account->imap_host;
            $port = !empty($this->account->imap_port) ? (int)$this->account->imap_port : 993;
            $encryption = strtolower($this->account->imap_encryption ?? 'ssl');
            $flags = ($encryption === 'ssl') ? "/imap/ssl/novalidate-cert" : (($encryption === 'tls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
            $prefix = "{" . "{$host}:{$port}{$flags}" . "}";

            $candidates = array('Junk', 'Spam', 'INBOX.Junk', 'INBOX.Spam');
            foreach ($candidates as $cand) {
                if (@imap_createmailbox($this->connection, $prefix . $this->encode_utf7($cand))) {
                    return $cand;
                }
            }
        }

        return 'Junk';
    }

    /**
     * Move message to a destination folder
     */
    public function move_message($folder, $uid, $target_folder) {
        if (!$this->connect($folder)) return false;

        $encoded_target = $this->encode_utf7($target_folder);
        $moved = @imap_mail_move($this->connection, (string)$uid, $encoded_target, CP_UID);
        if ($moved) {
            @imap_expunge($this->connection);
            return true;
        }

        $this->last_error = imap_last_error() ?: 'E-posta taşınamadı.';
        return false;
    }

    /**
     * Mark message as Spam (Move to Junk/Spam folder)
     */
    public function mark_as_spam($folder, $uid) {
        if (!$this->connect($folder)) return false;

        $spam_folder = $this->get_spam_folder();
        if ($spam_folder === $folder) {
            // Already in spam folder
            return true;
        }

        // Move to Spam folder
        $encoded_target = $this->encode_utf7($spam_folder);
        $moved = @imap_mail_move($this->connection, (string)$uid, $encoded_target, CP_UID);
        if ($moved) {
            @imap_expunge($this->connection);
            return true;
        }

        $this->last_error = imap_last_error() ?: 'E-posta İstenmeyen klasörüne taşınamadı.';
        return false;
    }

    /**
     * Unmark Spam (Move from Junk back to INBOX)
     */
    public function unmark_as_spam($folder, $uid) {
        if (!$this->connect($folder)) return false;

        $inbox_folder = 'INBOX';
        $encoded_target = $this->encode_utf7($inbox_folder);
        $moved = @imap_mail_move($this->connection, (string)$uid, $encoded_target, CP_UID);
        if ($moved) {
            @imap_expunge($this->connection);
            return true;
        }

        $this->last_error = imap_last_error() ?: 'E-posta Gelen Kutusu\'na taşınamadı.';
        return false;
    }

    /**
     * Append message to Sent folder
     */
    public function append_sent($raw_mime_message) {
        // Find Sent folder
        $mailboxes = $this->get_mailboxes();
        $sent_folder = 'Sent';
        foreach ($mailboxes as $box) {
            if ($box['type'] === 'sent') {
                $sent_folder = $box['name'];
                break;
            }
        }

        $host = $this->account->imap_host;
        $port = !empty($this->account->imap_port) ? (int)$this->account->imap_port : 993;
        $encryption = strtolower($this->account->imap_encryption ?? 'ssl');
        $flags = ($encryption === 'ssl') ? "/imap/ssl/novalidate-cert" : (($encryption === 'tls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
        
        $encoded_sent = $this->encode_utf7($sent_folder);
        $mailbox = "{" . "{$host}:{$port}{$flags}" . "}" . $encoded_sent;

        if ($this->connection) {
            @imap_append($this->connection, $mailbox, $raw_mime_message, "\\Seen");
        }
    }

    /**
     * Decode MIME encoded headers
     */
    public function decode_mime($string) {
        if (empty($string)) return '';
        $elements = @imap_mime_header_decode($string);
        if (!$elements) return $string;

        $output = '';
        foreach ($elements as $el) {
            $charset = strtoupper($el->charset);
            $text = $el->text;
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8') {
                $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
                $output .= ($converted !== false) ? $converted : $text;
            } else {
                $output .= $text;
            }
        }
        return $output;
    }

    /**
     * Parse address string (e.g. "John Doe <john@domain.com>")
     */
    public function parse_address($str) {
        $str = trim($str);
        $name = '';
        $email = '';

        if (preg_match('/^(.*?)\s*<(.+?)>$/', $str, $matches)) {
            $name = trim(trim($matches[1]), '"\'');
            $email = trim($matches[2]);
        } elseif (filter_var($str, FILTER_VALIDATE_EMAIL)) {
            $email = $str;
            $name = explode('@', $str)[0];
        } else {
            $name = $str;
            $email = $str;
        }

        if (empty($name)) {
            $name = explode('@', $email)[0];
        }

        return array(
            'raw' => $str,
            'name' => $name,
            'email' => $email
        );
    }

    /**
     * Get MIME Type string from part
     */
    protected function get_mime_type($part) {
        $primary = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        $type = isset($primary[$part->type]) ? $primary[$part->type] : 'APPLICATION';
        $subtype = isset($part->subtype) ? strtoupper($part->subtype) : 'OCTET-STREAM';
        return strtolower($type . '/' . $subtype);
    }

    /**
     * UTF-7 Encoding / Decoding for IMAP Mailbox names
     */
    protected function encode_utf7($str) {
        if (function_exists('mb_convert_encoding')) {
            return @mb_convert_encoding($str, 'UTF7-IMAP', 'UTF-8');
        }
        return $str;
    }

    protected function decode_utf7($str) {
        if (function_exists('mb_convert_encoding')) {
            return @mb_convert_encoding($str, 'UTF-8', 'UTF7-IMAP');
        }
        return $str;
    }

    /**
     * Sanitize HTML Body for secure rendering
     */
    public function sanitize_html($html) {
        // Strip script, iframe, object, embed tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);
        $html = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $html);
        $html = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $html);
        // Remove event handlers (onload, onclick, onerror, etc.)
        $html = preg_replace('/\s*on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);
        // Neutralize javascript: hrefs
        $html = preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/i', 'href="#"', $html);

        // Target blank for all links
        $html = preg_replace('/<a\s+(?!.*target=)([^>]+)>/i', '<a target="_blank" rel="noopener noreferrer" $1>', $html);

        return $html;
    }

    /**
     * Helpers
     */
    protected function format_size($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 1) . ' ' . $units[$pow];
    }

    protected function format_date($timestamp) {
        $today = strtotime('today');
        $yesterday = strtotime('yesterday');
        
        if ($timestamp >= $today) {
            return date('H:i', $timestamp);
        } elseif ($timestamp >= $yesterday) {
            return 'Dün ' . date('H:i', $timestamp);
        } elseif ($timestamp >= strtotime('-6 days')) {
            $days = array('Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi');
            return $days[date('w', $timestamp)];
        } elseif (date('Y', $timestamp) === date('Y')) {
            $months = array('', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara');
            return date('j ', $timestamp) . $months[(int)date('n', $timestamp)];
        } else {
            return date('d.m.Y', $timestamp);
        }
    }

    public function get_last_error() {
        return $this->last_error;
    }
}
