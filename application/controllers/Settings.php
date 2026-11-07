<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Settings extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Account_model', 'account_model');
        $this->load->model('Settings_model', 'settings_model');
        $this->load->library('Hestia_imap', null, 'hestia_imap');
        $this->load->library('Hestia_smtp', null, 'hestia_smtp');
    }

    public function index() {
        $data = array();
        $data['accounts'] = $this->account_model->get_all();
        $data['active_account'] = $this->account_model->get_default();
        $data['settings'] = $this->settings_model->get_all();
        $data['theme'] = $this->settings_model->get('theme', 'dark');
        $data['app_title'] = $this->settings_model->get('app_title', 'Hestia Webmail Suite');
        $data['active_tab'] = 'settings';

        $this->load->view('webmail/header', $data);
        $this->load->view('webmail/sidebar', $data);
        $this->load->view('webmail/settings', $data);
        $this->load->view('webmail/compose_modal', $data);
        $this->load->view('webmail/footer', $data);
    }

    public function save_account() {
        $id = (int)$this->input->post('id');
        $name = trim($this->input->post('name'));
        $email = trim($this->input->post('email'));
        $imap_host = trim($this->input->post('imap_host'));
        $imap_port = (int)$this->input->post('imap_port') ?: 993;
        $imap_encryption = trim($this->input->post('imap_encryption')) ?: 'ssl';
        $imap_username = trim($this->input->post('imap_username')) ?: $email;
        $imap_password = $this->input->post('imap_password');

        $smtp_host = trim($this->input->post('smtp_host')) ?: $imap_host;
        $smtp_port = (int)$this->input->post('smtp_port') ?: 465;
        $smtp_encryption = trim($this->input->post('smtp_encryption')) ?: 'ssl';
        $smtp_username = trim($this->input->post('smtp_username')) ?: $imap_username;
        $smtp_password = $this->input->post('smtp_password') ?: $imap_password;

        $signature = $this->input->post('signature');
        $is_default = $this->input->post('is_default') ? 1 : 0;

        if (empty($email) || empty($imap_host)) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'E-posta ve Sunucu adresi zorunludur.')));
        }

        $account_data = array(
            'name' => !empty($name) ? $name : $email,
            'email' => $email,
            'imap_host' => $imap_host,
            'imap_port' => $imap_port,
            'imap_encryption' => $imap_encryption,
            'imap_username' => $imap_username,
            'smtp_host' => $smtp_host,
            'smtp_port' => $smtp_port,
            'smtp_encryption' => $smtp_encryption,
            'smtp_username' => $smtp_username,
            'signature' => $signature,
            'is_default' => $is_default
        );

        if (!empty($imap_password)) {
            $account_data['imap_password'] = $imap_password;
            $account_data['smtp_password'] = $smtp_password;
        }

        if ($id > 0) {
            $this->account_model->update($id, $account_data);
            $msg = 'Hesap başarıyla güncellendi.';
        } else {
            if (empty($imap_password)) {
                return $this->output->set_content_type('application/json')
                    ->set_output(json_encode(array('success' => false, 'message' => 'Lütfen hesap şifresini girin.')));
            }
            $id = $this->account_model->create($account_data);
            $msg = 'Yeni hesap başarıyla eklendi.';
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => true, 'message' => $msg, 'id' => $id)));
    }

    public function delete_account($id) {
        $res = $this->account_model->delete((int)$id);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => (bool)$res)));
    }

    public function set_default($id) {
        $res = $this->account_model->set_default((int)$id);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => (bool)$res)));
    }

    /**
     * Live test connection for IMAP and SMTP
     */
    public function test_connection() {
        $imap_host = trim($this->input->post('imap_host'));
        $imap_port = (int)$this->input->post('imap_port') ?: 993;
        $imap_encryption = trim($this->input->post('imap_encryption')) ?: 'ssl';
        $imap_username = trim($this->input->post('imap_username'));
        $imap_password = $this->input->post('imap_password');

        $smtp_host = trim($this->input->post('smtp_host')) ?: $imap_host;
        $smtp_port = (int)$this->input->post('smtp_port') ?: 465;
        $smtp_encryption = trim($this->input->post('smtp_encryption')) ?: 'ssl';
        $smtp_username = trim($this->input->post('smtp_username')) ?: $imap_username;
        $smtp_password = $this->input->post('smtp_password') ?: $imap_password;

        if (empty($imap_host) || empty($imap_username) || empty($imap_password)) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Lütfen sunucu, kullanıcı adı ve şifre alanlarını doldurun.')));
        }

        // Test IMAP
        $imap_res = $this->hestia_imap->test_connection($imap_host, $imap_port, $imap_encryption, $imap_username, $imap_password);

        // Test SMTP
        $smtp_res = $this->hestia_smtp->test_connection($smtp_host, $smtp_port, $smtp_encryption, $smtp_username, $smtp_password);

        $all_success = ($imap_res['success'] && $smtp_res['success']);

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array(
                'success' => $all_success,
                'imap' => $imap_res,
                'smtp' => $smtp_res,
                'message' => $all_success ? 'Tüm bağlantılar (IMAP ve SMTP) başarıyla doğrulandı!' : 'Bağlantı sırasında bazı hatalarla karşılaşıldı.'
            )));
    }

    /**
     * Save general preferences
     */
    public function save_preferences() {
        $theme = $this->input->post('theme') ?: 'dark';
        $page_size = (int)$this->input->post('page_size') ?: 25;
        $auto_refresh = (int)$this->input->post('auto_refresh_minutes') ?: 3;
        $app_title = trim($this->input->post('app_title')) ?: 'Hestia Webmail Suite';

        $this->settings_model->set('theme', $theme);
        $this->settings_model->set('page_size', $page_size);
        $this->settings_model->set('auto_refresh_minutes', $auto_refresh);
        $this->settings_model->set('app_title', $app_title);

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => true, 'message' => 'Ayarlar başarıyla kaydedildi.')));
    }
}
