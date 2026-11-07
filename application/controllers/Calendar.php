<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Calendar extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Account_model', 'account_model');
        $this->load->model('Calendar_model', 'calendar_model');
        $this->load->model('Settings_model', 'settings_model');
    }

    public function index() {
        $data = array();
        $data['accounts'] = $this->account_model->get_all();
        $data['active_account'] = $this->account_model->get_default();
        $data['upcoming_events'] = $this->calendar_model->get_upcoming(6);
        $data['theme'] = $this->settings_model->get('theme', 'dark');
        $data['app_title'] = $this->settings_model->get('app_title', 'Hestia Webmail Suite');
        $data['active_tab'] = 'calendar';

        $this->load->view('webmail/header', $data);
        $this->load->view('webmail/sidebar', $data);
        $this->load->view('webmail/calendar', $data);
        $this->load->view('webmail/compose_modal', $data);
        $this->load->view('webmail/footer', $data);
    }

    public function events_json() {
        $start = $this->input->get('start') ?: date('Y-m-01 00:00:00');
        $end = $this->input->get('end') ?: date('Y-m-t 23:59:59');

        $events = $this->calendar_model->get_events($start, $end);
        $formatted = array();

        foreach ($events as $ev) {
            $formatted[] = array(
                'id' => $ev->id,
                'title' => $ev->title,
                'start' => date('c', strtotime($ev->start_date)),
                'end' => date('c', strtotime($ev->end_date)),
                'allDay' => (bool)$ev->all_day,
                'backgroundColor' => $ev->color,
                'borderColor' => $ev->color,
                'textColor' => '#ffffff',
                'extendedProps' => array(
                    'location' => $ev->location,
                    'description' => $ev->description,
                    'category' => $ev->category,
                    'reminder' => $ev->reminder_minutes,
                    'startDateRaw' => date('Y-m-d\TH:i', strtotime($ev->start_date)),
                    'endDateRaw' => date('Y-m-d\TH:i', strtotime($ev->end_date)),
                )
            );
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode($formatted));
    }

    public function save_event() {
        $id = (int)$this->input->post('id');
        $title = trim($this->input->post('title'));
        $start_date = $this->input->post('start_date');
        $end_date = $this->input->post('end_date');
        $all_day = $this->input->post('all_day') ? 1 : 0;
        $color = $this->input->post('color') ?: '#4f46e5';
        $category = $this->input->post('category') ?: 'Toplantı';
        $location = trim($this->input->post('location'));
        $description = trim($this->input->post('description'));

        if (empty($title) || empty($start_date) || empty($end_date)) {
            return $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Lütfen başlık, başlangıç ve bitiş tarihlerini doldurun.')));
        }

        $event_data = array(
            'title' => $title,
            'start_date' => date('Y-m-d H:i:s', strtotime($start_date)),
            'end_date' => date('Y-m-d H:i:s', strtotime($end_date)),
            'all_day' => $all_day,
            'color' => $color,
            'category' => $category,
            'location' => $location,
            'description' => $description
        );

        if ($id > 0) {
            $this->calendar_model->update($id, $event_data);
            $msg = 'Etkinlik güncellendi.';
        } else {
            $id = $this->calendar_model->create($event_data);
            $msg = 'Etkinlik oluşturuldu.';
        }

        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => true, 'message' => $msg, 'id' => $id)));
    }

    public function delete_event($id) {
        $res = $this->calendar_model->delete((int)$id);
        return $this->output->set_content_type('application/json')
            ->set_output(json_encode(array('success' => (bool)$res)));
    }

    public function export_ics($id = null) {
        $ics = $this->calendar_model->export_ics($id ? (int)$id : null);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="takvim_' . date('Y-m-d') . '.ics"');
        echo $ics;
        exit;
    }
}
