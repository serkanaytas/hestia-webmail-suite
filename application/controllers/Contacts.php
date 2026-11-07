<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Contacts extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Account_model', 'account_model');
        $this->load->model('Contact_model', 'contact_model');
        $this->load->model('Settings_model', 'settings_model');
    }

    public function index() {
        $search = $this->input->get('q');
        $group = $this->input->get('group');
        $only_fav = $this->input->get('favorites') == '1';

        $data = array();
        $data['accounts'] = $this->account_model->get_all();
        $data['active_account'] = $this->account_model->get_default();
        $data['contacts'] = $this->contact_model->get_all($search, $group, $only_fav);
        $data['groups'] = $this->contact_model->get_groups();
        $data['current_group'] = $group ?: 'all';
        $data['current_search'] = $search;
        $data['only_favorites'] = $only_fav;
        $data['theme'] = $this->settings_model->get('theme', 'dark');
        $data['app_title'] = $this->settings_model->get('app_title', 'Hestia Webmail Suite');
        $data['active_tab'] = 'contacts';

        $this->load->view('webmail/header', $data);
        $this->load->view('webmail/sidebar', $data);
        $this->load->view('webmail/contacts', $data);
        $this->load->view('webmail/compose_modal', $data);
        $this->load->view('webmail/footer', $data);
    }

    public function save() {
        $id = (int)$this->input->post('id');
        $first_name = trim($this->input->post('first_name'));
        $last_name = trim($this->input->post('last_name'));
        $email = trim($this->input->post('email'));
        $phone = trim($this->input->post('phone'));
        $company = trim($this->input->post('company'));
        $job_title = trim($this->input->post('job_title'));
        $group_name = trim($this->input->post('group_name')) ?: 'Genel';
        $notes = trim($this->input->post('notes'));

        if (empty($first_name) || empty($email)) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Ad ve E-posta alanları zorunludur.')));
        }

        $contact_data = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'company' => $company,
            'job_title' => $job_title,
            'group_name' => $group_name,
            'notes' => $notes
        );

        if ($id > 0) {
            $this->contact_model->update($id, $contact_data);
            $msg = 'Kişi başarıyla güncellendi.';
        } else {
            $id = $this->contact_model->create($contact_data);
            $msg = 'Yeni kişi başarıyla eklendi.';
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => true, 'message' => $msg, 'id' => $id)));
    }

    public function delete($id) {
        $res = $this->contact_model->delete((int)$id);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => (bool)$res)));
    }

    public function toggle_favorite($id) {
        $res = $this->contact_model->toggle_favorite((int)$id);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => true, 'is_favorite' => $res)));
    }

    public function autocomplete() {
        $q = $this->input->get('q');
        if (strlen($q) < 1) {
            return $this->output->set_content_type('application/json')->set_output(json_encode(array()));
        }
        $results = $this->contact_model->search_autocomplete($q);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode($results));
    }

    public function export_vcf() {
        $contacts = $this->contact_model->get_all();
        $vcf = $this->contact_model->export_vcard($contacts);
        
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="rehber_' . date('Y-m-d') . '.vcf"');
        echo $vcf;
        exit;
    }

    public function export_csv() {
        $contacts = $this->contact_model->get_all();
        $csv = $this->contact_model->export_csv($contacts);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rehber_' . date('Y-m-d') . '.csv"');
        echo $csv;
        exit;
    }

    public function import() {
        if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Lütfen bir dosya seçin.')));
        }

        $filename = $_FILES['file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $content = file_get_contents($_FILES['file']['tmp_name']);

        $count = 0;
        if ($ext === 'vcf') {
            $count = $this->contact_model->import_vcard($content);
        } elseif ($ext === 'csv') {
            $lines = explode("\n", $content);
            $header = null;
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $row = str_getcsv($line);
                if (!$header) {
                    $header = $row;
                    continue;
                }
                if (isset($row[2]) && filter_var($row[2], FILTER_VALIDATE_EMAIL)) {
                    $this->contact_model->create(array(
                        'first_name' => $row[0] ?? '',
                        'last_name' => $row[1] ?? '',
                        'email' => $row[2],
                        'phone' => $row[3] ?? '',
                        'company' => $row[4] ?? '',
                        'job_title' => $row[5] ?? '',
                        'group_name' => $row[6] ?? 'İçe Aktarılan',
                        'notes' => $row[7] ?? ''
                    ));
                    $count++;
                }
            }
        } else {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Desteklenmeyen dosya formatı. Lütfen .vcf veya .csv yükleyin.')));
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => true, 'message' => "{$count} kişi başarıyla içe aktarıldı.")));
    }
}
