<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Settings_model extends CI_Model {

    public function __construct() {
        parent::__construct();
    }

    public function get_all() {
        $query = $this->db->get('mail_settings');
        $result = array();
        foreach ($query->result() as $row) {
            $result[$row->setting_key] = $row->setting_value;
        }
        return $result;
    }

    public function get($key, $default = null) {
        $row = $this->db->where('setting_key', $key)->get('mail_settings')->row();
        return $row ? $row->setting_value : $default;
    }

    public function set($key, $value) {
        $exists = $this->db->where('setting_key', $key)->count_all_results('mail_settings');
        if ($exists) {
            $this->db->where('setting_key', $key)->update('mail_settings', array('setting_value' => $value));
        } else {
            $this->db->insert('mail_settings', array('setting_key' => $key, 'setting_value' => $value));
        }
        return true;
    }

    public function set_batch($settings) {
        foreach ($settings as $key => $val) {
            $this->set($key, $val);
        }
        return true;
    }
}
