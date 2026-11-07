<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Calendar_model extends CI_Model {

    public function __construct() {
        parent::__construct();
    }

    public function get_events($start, $end) {
        $this->db->select('*');
        $this->db->from('calendar_events');
        $this->db->where('start_date <=', $end);
        $this->db->where('end_date >=', $start);
        $this->db->order_by('start_date', 'ASC');
        return $this->db->get()->result();
    }

    public function get_upcoming($limit = 5) {
        $now = date('Y-m-d H:i:s');
        return $this->db->where('end_date >=', $now)
                        ->order_by('start_date', 'ASC')
                        ->limit($limit)
                        ->get('calendar_events')
                        ->result();
    }

    public function get_by_id($id) {
        return $this->db->where('id', $id)->get('calendar_events')->row();
    }

    public function create($data) {
        if (empty($data['color'])) {
            $data['color'] = '#4f46e5';
        }
        $this->db->insert('calendar_events', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        $this->db->where('id', $id)->update('calendar_events', $data);
        return true;
    }

    public function delete($id) {
        return $this->db->where('id', $id)->delete('calendar_events');
    }

    public function export_ics($event_id = null) {
        if ($event_id) {
            $events = array($this->get_by_id($event_id));
        } else {
            $events = $this->db->order_by('start_date', 'ASC')->get('calendar_events')->result();
        }

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Hestia Webmail Suite//TR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";

        foreach ($events as $ev) {
            if (!$ev) continue;
            $dtstart = gmdate('Ymd\THis\Z', strtotime($ev->start_date));
            $dtend = gmdate('Ymd\THis\Z', strtotime($ev->end_date));
            $dtstamp = gmdate('Ymd\THis\Z');
            $uid = "event-" . $ev->id . "-" . md5($ev->title . $ev->start_date) . "@hestia-webmail.local";

            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:{$uid}\r\n";
            $ics .= "DTSTAMP:{$dtstamp}\r\n";
            $ics .= "DTSTART:{$dtstart}\r\n";
            $ics .= "DTEND:{$dtend}\r\n";
            $ics .= "SUMMARY:" . str_replace(array("\r", "\n"), " ", $ev->title) . "\r\n";
            if (!empty($ev->description)) {
                $ics .= "DESCRIPTION:" . str_replace("\n", "\\n", $ev->description) . "\r\n";
            }
            if (!empty($ev->location)) {
                $ics .= "LOCATION:" . str_replace(array("\r", "\n"), " ", $ev->location) . "\r\n";
            }
            $ics .= "STATUS:CONFIRMED\r\n";
            $ics .= "END:VEVENT\r\n";
        }

        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }
}
