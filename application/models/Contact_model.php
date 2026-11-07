<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Contact_model extends CI_Model {

    public function __construct() {
        parent::__construct();
    }

    public function get_all($search = null, $group = null, $only_favorites = false) {
        $this->db->select('*');
        $this->db->from('contacts');

        if (!empty($search)) {
            $this->db->group_start();
            $this->db->like('first_name', $search);
            $this->db->or_like('last_name', $search);
            $this->db->or_like('email', $search);
            $this->db->or_like('phone', $search);
            $this->db->or_like('company', $search);
            $this->db->group_end();
        }

        if (!empty($group) && $group !== 'all') {
            $this->db->where('group_name', $group);
        }

        if ($only_favorites) {
            $this->db->where('is_favorite', 1);
        }

        $this->db->order_by('is_favorite', 'DESC');
        $this->db->order_by('first_name', 'ASC');
        $this->db->order_by('last_name', 'ASC');
        return $this->db->get()->result();
    }

    public function get_by_id($id) {
        return $this->db->where('id', $id)->get('contacts')->row();
    }

    public function create($data) {
        $colors = array('#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777');
        if (empty($data['avatar_color'])) {
            $data['avatar_color'] = $colors[array_rand($colors)];
        }
        $this->db->insert('contacts', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        $this->db->where('id', $id)->update('contacts', $data);
        return true;
    }

    public function delete($id) {
        return $this->db->where('id', $id)->delete('contacts');
    }

    public function toggle_favorite($id) {
        $contact = $this->get_by_id($id);
        if ($contact) {
            $new_val = $contact->is_favorite ? 0 : 1;
            $this->db->where('id', $id)->update('contacts', array('is_favorite' => $new_val));
            return $new_val;
        }
        return false;
    }

    public function get_groups() {
        $query = $this->db->select('group_name, COUNT(id) as total')
                          ->group_by('group_name')
                          ->order_by('group_name', 'ASC')
                          ->get('contacts');
        return $query->result();
    }

    public function search_autocomplete($query) {
        $this->db->select('id, first_name, last_name, email, company');
        $this->db->from('contacts');
        $this->db->group_start();
        $this->db->like('first_name', $query);
        $this->db->or_like('last_name', $query);
        $this->db->or_like('email', $query);
        $this->db->or_like('company', $query);
        $this->db->group_end();
        $this->db->limit(10);
        return $this->db->get()->result();
    }

    public function export_vcard($contacts) {
        $vcf = "";
        foreach ($contacts as $c) {
            $vcf .= "BEGIN:VCARD\r\n";
            $vcf .= "VERSION:3.0\r\n";
            $vcf .= "N:" . $c->last_name . ";" . $c->first_name . ";;;\r\n";
            $vcf .= "FN:" . trim($c->first_name . " " . $c->last_name) . "\r\n";
            if (!empty($c->company)) $vcf .= "ORG:" . $c->company . "\r\n";
            if (!empty($c->job_title)) $vcf .= "TITLE:" . $c->job_title . "\r\n";
            if (!empty($c->email)) $vcf .= "EMAIL;TYPE=INTERNET,PREF:" . $c->email . "\r\n";
            if (!empty($c->phone)) $vcf .= "TEL;TYPE=CELL,VOICE:" . $c->phone . "\r\n";
            if (!empty($c->notes)) $vcf .= "NOTE:" . str_replace("\n", "\\n", $c->notes) . "\r\n";
            $vcf .= "END:VCARD\r\n";
        }
        return $vcf;
    }

    public function export_csv($contacts) {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, array('Adı', 'Soyadı', 'E-posta', 'Telefon', 'Şirket', 'Ünvan', 'Grup', 'Notlar'));
        foreach ($contacts as $c) {
            fputcsv($output, array(
                $c->first_name,
                $c->last_name,
                $c->email,
                $c->phone,
                $c->company,
                $c->job_title,
                $c->group_name,
                $c->notes
            ));
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return "\xEF\xBB\xBF" . $csv; // Add BOM for Excel UTF-8
    }

    public function import_vcard($vcard_text) {
        $lines = explode("\n", str_replace("\r", "", $vcard_text));
        $count = 0;
        $current = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (strpos($line, 'BEGIN:VCARD') !== false) {
                $current = array(
                    'first_name' => '',
                    'last_name' => '',
                    'email' => '',
                    'phone' => '',
                    'company' => '',
                    'job_title' => '',
                    'group_name' => 'İçe Aktarılanlar',
                    'notes' => ''
                );
            } elseif (strpos($line, 'END:VCARD') !== false) {
                if (!empty($current['email']) || !empty($current['first_name'])) {
                    if (empty($current['first_name']) && !empty($current['email'])) {
                        $current['first_name'] = explode('@', $current['email'])[0];
                    }
                    $this->create($current);
                    $count++;
                }
                $current = array();
            } else {
                if (preg_match('/^FN:(.+)$/i', $line, $m)) {
                    $parts = explode(' ', trim($m[1]), 2);
                    $current['first_name'] = $parts[0] ?? '';
                    $current['last_name'] = $parts[1] ?? '';
                } elseif (preg_match('/^N:(.+)$/i', $line, $m)) {
                    $parts = explode(';', trim($m[1]));
                    if (empty($current['last_name'])) $current['last_name'] = $parts[0] ?? '';
                    if (empty($current['first_name'])) $current['first_name'] = $parts[1] ?? '';
                } elseif (preg_match('/^EMAIL.*?:(.+)$/i', $line, $m)) {
                    $current['email'] = trim($m[1]);
                } elseif (preg_match('/^TEL.*?:(.+)$/i', $line, $m)) {
                    $current['phone'] = trim($m[1]);
                } elseif (preg_match('/^ORG:(.+)$/i', $line, $m)) {
                    $current['company'] = trim($m[1]);
                } elseif (preg_match('/^TITLE:(.+)$/i', $line, $m)) {
                    $current['job_title'] = trim($m[1]);
                } elseif (preg_match('/^NOTE:(.+)$/i', $line, $m)) {
                    $current['notes'] = str_replace('\\n', "\n", trim($m[1]));
                }
            }
        }
        return $count;
    }
}
