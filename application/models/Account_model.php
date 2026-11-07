<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Account_model extends CI_Model {

    public function __construct() {
        parent::__construct();
    }

    public function get_all() {
        return $this->db->order_by('is_default', 'DESC')->order_by('id', 'ASC')->get('mail_accounts')->result();
    }

    public function get_default() {
        $acc = $this->db->where('is_default', 1)->get('mail_accounts')->row();
        if (!$acc) {
            $acc = $this->db->order_by('id', 'ASC')->get('mail_accounts')->row();
        }
        return $acc;
    }

    public function get_by_id($id) {
        return $this->db->where('id', $id)->get('mail_accounts')->row();
    }

    public function create($data) {
        // If this is set as default, clear others
        if (!empty($data['is_default'])) {
            $this->db->update('mail_accounts', array('is_default' => 0));
        } else {
            // If it's the first account, make it default automatically
            $count = $this->db->count_all('mail_accounts');
            if ($count === 0) {
                $data['is_default'] = 1;
            }
        }
        $this->db->insert('mail_accounts', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        if (!empty($data['is_default'])) {
            $this->db->where('id !=', $id)->update('mail_accounts', array('is_default' => 0));
        }
        $this->db->where('id', $id)->update('mail_accounts', $data);
        return $this->db->affected_rows() >= 0;
    }

    public function delete($id) {
        $this->db->where('id', $id)->delete('mail_accounts');
        // If default account was deleted, make the first remaining default
        $remaining = $this->get_default();
        if ($remaining && empty($remaining->is_default)) {
            $this->db->where('id', $remaining->id)->update('mail_accounts', array('is_default' => 1));
        }
        return true;
    }

    public function set_default($id) {
        $this->db->update('mail_accounts', array('is_default' => 0));
        $this->db->where('id', $id)->update('mail_accounts', array('is_default' => 1));
        return true;
    }
}
