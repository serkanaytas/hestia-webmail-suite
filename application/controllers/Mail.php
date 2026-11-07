<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Mail extends CI_Controller {

    protected $account = null;

    public function __construct() {
        parent::__construct();
        $this->load->model('Account_model', 'account_model');
        $this->load->model('Contact_model', 'contact_model');
        $this->load->model('Settings_model', 'settings_model');
        $this->load->library('Hestia_imap', null, 'hestia_imap');
        $this->load->library('Hestia_smtp', null, 'hestia_smtp');

        // Check if an account is selected in session, otherwise load default
        $account_id = $this->session->userdata('active_account_id');
        if ($account_id) {
            $this->account = $this->account_model->get_by_id($account_id);
        }
        if (!$this->account) {
            $this->account = $this->account_model->get_default();
            if ($this->account) {
                $this->session->set_userdata('active_account_id', $this->account->id);
            }
        }

        if ($this->account) {
            $this->hestia_imap->set_account($this->account);
            $this->hestia_smtp->set_account($this->account);
        }
    }

    /**
     * Main Webmail Dashboard
     */
    public function index($folder = 'INBOX') {
        $data = array();
        $data['accounts'] = $this->account_model->get_all();
        $data['active_account'] = $this->account;
        $data['current_folder'] = urldecode($folder);
        $data['theme'] = $this->settings_model->get('theme', 'dark');
        $data['app_title'] = $this->settings_model->get('app_title', 'Hestia Webmail Suite');
        $data['active_tab'] = 'mail';

        // If no account exists yet, flag for onboarding wizard
        if (!$this->account) {
            $data['needs_setup'] = true;
            $data['mailboxes'] = array();
            $data['messages'] = array();
            $data['total_messages'] = 0;
            $data['total_pages'] = 1;
            $data['current_page'] = 1;
        } else {
            $data['needs_setup'] = false;
            // Fetch mailboxes
            $data['mailboxes'] = $this->hestia_imap->get_mailboxes();
            
            // Fetch emails for current folder
            $page = (int)$this->input->get('page') ?: 1;
            $search = $this->input->get('search') ?: null;
            $filter = $this->input->get('filter') ?: null;
            $per_page = (int)$this->settings_model->get('page_size', 25);

            $result = $this->hestia_imap->get_messages($data['current_folder'], $page, $per_page, $search, $filter);
            $data['messages'] = $result['messages'] ?? array();
            $data['total_messages'] = $result['total'] ?? 0;
            $data['total_pages'] = $result['pages'] ?? 1;
            $data['current_page'] = $result['current_page'] ?? 1;
            $data['imap_error'] = $this->hestia_imap->get_last_error();
        }

        $this->load->view('webmail/header', $data);
        $this->load->view('webmail/sidebar', $data);
        $this->load->view('webmail/inbox', $data);
        $this->load->view('webmail/compose_modal', $data);
        $this->load->view('webmail/footer', $data);
    }

    /**
     * AJAX endpoint to fetch folder messages
     */
    public function get_messages_ajax() {
        if (!$this->account) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Lütfen önce e-posta hesabı ekleyin.')));
        }

        $folder = $this->input->get('folder') ?: 'INBOX';
        $page = (int)$this->input->get('page') ?: 1;
        $search = $this->input->get('search') ?: null;
        $filter = $this->input->get('filter') ?: null;
        $per_page = (int)$this->settings_model->get('page_size', 25);

        $result = $this->hestia_imap->get_messages($folder, $page, $per_page, $search, $filter);
        $result['success'] = true;
        
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode($result));
    }

    /**
     * AJAX endpoint to view single email
     */
    public function view_email_ajax() {
        if (!$this->account) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Aktif hesap bulunamadı.')));
        }

        $folder = $this->input->get('folder') ?: 'INBOX';
        $uid = (int)$this->input->get('uid');

        if (!$uid) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Geçersiz e-posta UID.')));
        }

        $email = $this->hestia_imap->get_message($folder, $uid);
        if ($email) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => true, 'email' => $email)));
        } else {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'E-posta yüklenemedi: ' . $this->hestia_imap->get_last_error())));
        }
    }

    /**
     * Download attachment
     */
    public function download_attachment() {
        if (!$this->account) {
            show_error('E-posta hesabı bulunamadı.');
        }

        $folder = $this->input->get('folder') ?: 'INBOX';
        $uid = (int)$this->input->get('uid');
        $part = $this->input->get('part');

        $attachment = $this->hestia_imap->get_attachment($folder, $uid, $part);
        if (!$attachment) {
            show_error('Ek dosya bulunamadı.');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: attachment; filename="' . $attachment['filename'] . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $attachment['size']);
        echo $attachment['content'];
        exit;
    }

    /**
     * Send email via SMTP & append to Sent folder on IMAP
     */
    public function send_email() {
        if (!$this->account) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Aktif e-posta hesabı bulunamadı.')));
        }

        $to = $this->input->post('to');
        $subject = $this->input->post('subject');
        $body = $this->input->post('body');
        $cc = $this->input->post('cc');
        $bcc = $this->input->post('bcc');

        if (empty($to)) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Lütfen alıcı (Kime) alanını doldurun.')));
        }

        // Handle uploaded files
        $attachments = array();
        if (!empty($_FILES['attachments']['name'][0])) {
            $files_count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $files_count; $i++) {
                if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                    $attachments[] = array(
                        'name' => $_FILES['attachments']['name'][$i],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'size' => $_FILES['attachments']['size'][$i]
                    );
                }
            }
        }

        // Handle forwarded attachments from original message
        $forward_attachments_json = $this->input->post('forward_attachments');
        if (!empty($forward_attachments_json)) {
            $forward_attachments = is_string($forward_attachments_json) ? json_decode($forward_attachments_json, true) : $forward_attachments_json;
            if (is_array($forward_attachments)) {
                foreach ($forward_attachments as $f_att) {
                    if (!empty($f_att['folder']) && !empty($f_att['uid']) && isset($f_att['part_no'])) {
                        $f_folder = $f_att['folder'];
                        $f_uid = (int)$f_att['uid'];
                        $f_part = (string)$f_att['part_no'];

                        $att_data = $this->hestia_imap->get_attachment($f_folder, $f_uid, $f_part);
                        if ($att_data && isset($att_data['content'])) {
                            $attachments[] = array(
                                'name' => !empty($f_att['filename']) ? $f_att['filename'] : $att_data['filename'],
                                'content' => $att_data['content'],
                                'mime_type' => !empty($att_data['mime_type']) ? $att_data['mime_type'] : (!empty($f_att['mime_type']) ? $f_att['mime_type'] : ''),
                                'size' => $att_data['size'] ?? strlen($att_data['content'])
                            );
                        }
                    }
                }
            }
        }

        $send_result = $this->hestia_smtp->send($to, $subject, $body, $attachments, $cc, $bcc);

        if ($send_result && !empty($send_result['success'])) {
            // Append to IMAP Sent folder
            if (!empty($send_result['raw_mime'])) {
                @$this->hestia_imap->append_sent($send_result['raw_mime']);
            }

            // Auto-add recipient to contacts if not already existing
            $this->auto_save_contact($to);

            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => true, 'message' => 'E-posta başarıyla gönderildi!')));
        } else {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => false, 
                    'message' => 'Gönderim hatası: ' . $this->hestia_smtp->get_last_error()
                )));
        }
    }

    /**
     * Mark message status (seen / unread / flagged / unflagged)
     */
    public function mark_status() {
        $folder = $this->input->post('folder') ?: 'INBOX';
        $uid = (int)$this->input->post('uid');
        $type = $this->input->post('type'); // seen, unseen, flagged, unflagged

        if (!$uid) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false)));
        }

        $success = false;
        switch ($type) {
            case 'seen':
                $success = $this->hestia_imap->set_flag($folder, $uid, "\\Seen", true);
                break;
            case 'unseen':
                $success = $this->hestia_imap->set_flag($folder, $uid, "\\Seen", false);
                break;
            case 'flagged':
                $success = $this->hestia_imap->set_flag($folder, $uid, "\\Flagged", true);
                break;
            case 'unflagged':
                $success = $this->hestia_imap->set_flag($folder, $uid, "\\Flagged", false);
                break;
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => $success)));
    }

    /**
     * Delete email
     */
    public function delete_email() {
        $folder = $this->input->post('folder') ?: 'INBOX';
        $uid = (int)$this->input->post('uid');

        if (!$uid) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Geçersiz UID.')));
        }

        $res = $this->hestia_imap->delete_message($folder, $uid);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => $res)));
    }

    /**
     * Mark message as Spam or Unmark (move to Junk/Spam or back to INBOX)
     */
    public function mark_spam() {
        if (!$this->account) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Aktif e-posta hesabı bulunamadı.')));
        }

        $folder = $this->input->post('folder') ?: 'INBOX';
        $uid = (int)$this->input->post('uid');
        $action = $this->input->post('action'); // 'spam', 'unspam', or null for toggle

        if (!$uid) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Geçersiz e-posta UID.')));
        }

        $is_in_spam = $this->hestia_imap->is_spam_folder($folder);

        if ($action === 'unspam' || ($action === null && $is_in_spam)) {
            $success = $this->hestia_imap->unmark_as_spam($folder, $uid);
            $msg = $success ? 'E-posta spam listesinden çıkarıldı ve Gelen Kutusu\'na taşındı.' : ('İşlem başarısız: ' . $this->hestia_imap->get_last_error());
            $is_spam = false;
        } else {
            $success = $this->hestia_imap->mark_as_spam($folder, $uid);
            $msg = $success ? 'E-posta İstenmeyen (Spam) olarak işaretlendi ve taşındı.' : ('İşlem başarısız: ' . $this->hestia_imap->get_last_error());
            $is_spam = true;
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array(
                'success' => $success,
                'is_spam' => $is_spam,
                'message' => $msg
            )));
    }

    /**
     * Switch active email account
     */
    public function switch_account($account_id) {
        $acc = $this->account_model->get_by_id($account_id);
        if ($acc) {
            $this->session->set_userdata('active_account_id', $acc->id);
        }
        redirect('mail');
    }

    /**
     * Auto save recipient to contacts
     */
    protected function auto_save_contact($to) {
        $emails = explode(',', $to);
        foreach ($emails as $item) {
            $parsed = $this->hestia_imap->parse_address($item);
            if (!empty($parsed['email']) && filter_var($parsed['email'], FILTER_VALIDATE_EMAIL)) {
                $exists = $this->db->where('email', $parsed['email'])->count_all_results('contacts');
                if ($exists == 0) {
                    $parts = explode(' ', $parsed['name'], 2);
                    $this->contact_model->create(array(
                        'first_name' => $parts[0] ?? $parsed['name'],
                        'last_name' => $parts[1] ?? '',
                        'email' => $parsed['email'],
                        'group_name' => 'Otomatik Eklenen'
                    ));
                }
            }
        }
    }
}
