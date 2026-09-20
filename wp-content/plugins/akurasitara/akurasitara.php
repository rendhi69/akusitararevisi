<?php
/**
 * Plugin Name:       AkurasiTara - Unit Kerja Survey
 * Plugin URI:        https://akurasitara.id
 * Description:       v2.2.24. Mengubah bukti pertanyaan menjadi link/URL, bukan upload dokumen.
 * Version:           2.2.24
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Lukman Hakim
 * Author URI:        https://akurasitara.id
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       akurasitara
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AkurasiTara {
    private $current_unlock = '';
    private $current_user_token = '';
    private $current_user_id = 0;
    const VER  = '2.2.24';
    const SLUG = 'akurasitara';

    public function __construct(){
        register_activation_hook(__FILE__, array($this,'activate'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'handle_downloads')); // CSV downloads early to avoid HTML mixing
add_action('init', array($this, 'maybe_handle_submission'));
        add_action('init', array($this, 'maybe_upgrade_schema'));
        add_action('admin_footer', array($this,'admin_footer_confirm'));
        add_action('admin_post_at_import_users_csv', array($this,'handle_import_users_csv'));
        // AJAX: activate/deactivate run + close now
        add_action('wp_ajax_at_toggle_run_active', array($this,'ajax_toggle_run_active')); 

        // AJAX: captcha image (logged in/out)
        add_action('wp_ajax_nopriv_akurasitara_captcha_img', array($this,'ajax_captcha_img'));
        add_action('wp_ajax_akurasitara_captcha_img', array($this,'ajax_captcha_img'));
        // Auto close expired runs
        add_action('init', array($this,'auto_close_expired_runs'));
        add_shortcode('akurasitara_survey', array($this, 'shortcode_render_form'));
        add_shortcode('akurasitara_results', array($this, 'shortcode_render_results'));
    }

    private function db(){ global $wpdb; return $wpdb; }
    private function tables(){
        global $wpdb; $p=$wpdb->prefix.'akurasitara_';
        return (object) array(
            'units'     => $p.'units',
            'surveys'   => $p.'surveys',
            'questions' => $p.'questions',
            'runs'      => $p.'runs',
            'responses' => $p.'responses',
            'answers'   => $p.'answers',
            'id_templates' => $p.'id_templates',
            'id_elements'   => $p.'id_elements',
            'user_groups'   => $p.'user_groups',
            'users'         => $p.'users',
            'user_identity' => $p.'user_identity',
        'unit_heads'    => $p.'unit_heads',
        'data_processors' => $p.'data_processors',
        'wa_settings'   => $p.'wa_settings',
        'wa_logs'       => $p.'wa_logs',
        );
    }

    /* ================= Identity element flags ================= */
    private function id_element_columns_upgrade(){
        global $wpdb; $t=$this->tables();
        $c1 = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->id_elements} LIKE %s", 'editable_by_user') );
        if(!$c1){
            $wpdb->query("ALTER TABLE {$t->id_elements} ADD COLUMN editable_by_user TINYINT(1) NOT NULL DEFAULT 0 AFTER options_csv");
        }
        $c2 = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->id_elements} LIKE %s", 'show_in_results') );
        if(!$c2){
            $wpdb->query("ALTER TABLE {$t->id_elements} ADD COLUMN show_in_results TINYINT(1) NOT NULL DEFAULT 0 AFTER editable_by_user");
        }
    }

    /* ================= Kepala Unit helpers ================= */
    private function get_head_unit_id(){
        $uid = get_current_user_id();
        if(!$uid) return 0;
        $db=$this->db(); $t=$this->tables();
        return intval($db->get_var($db->prepare("SELECT unit_id FROM {$t->unit_heads} WHERE wp_user_id=%d", $uid)));
    }
    private function is_kepala_unit(){ return $this->get_head_unit_id() > 0; }

    /* ================= Pengolah Data helpers ================= */
    private function get_processor_unit_id(){
        $uid = get_current_user_id();
        if(!$uid) return 0;
        $db=$this->db(); $t=$this->tables();
        return intval($db->get_var($db->prepare("SELECT unit_id FROM {$t->data_processors} WHERE wp_user_id=%d", $uid)));
    }
    private function is_data_processor(){ return $this->get_processor_unit_id() > 0; }
    private function get_unit_role_id_for_current_user(){
        $head = $this->get_head_unit_id();
        if($head) return $head;
        return $this->get_processor_unit_id();
    }
    private function response_belongs_to_unit($response_id, $unit_id){
        $response_id = intval($response_id); $unit_id = intval($unit_id);
        if(!$response_id || !$unit_id) return false;
        $db=$this->db(); $t=$this->tables();
        $row = $db->get_row($db->prepare("
            SELECT r.id, r.user_id, rn.unit_id AS run_unit_id, u.unit_id AS user_unit_id
            FROM {$t->responses} r
            JOIN {$t->runs} rn ON rn.id = r.run_id
            LEFT JOIN {$t->users} u ON u.id = r.user_id
            WHERE r.id=%d
        ", $response_id));
        if(!$row) return false;
        if(intval($row->user_unit_id) === $unit_id) return true;
        if(intval($row->user_id) <= 0 && intval($row->run_unit_id) === $unit_id) return true;
        return false;
    }

    // Backward-compat alias: older code expects this name
    private function get_kepala_unit_unit_id(){
        return $this->get_head_unit_id();
    }

    /**
     * Permission helper: who can see all runs/results (admin-like).
     * Used by results/charts pages to decide whether to filter by unit.
     */
    private function can_access_all_runs(){
        return current_user_can('manage_options');
    }

    /* ================= Run lifecycle helpers (active + schedule) ================= */
    private function now_ts(){
        // Use WP timezone
        return (int) current_time('timestamp');
    }
    private function ts_from_mysql($dt){
        if(!$dt) return 0;
        $dt = (string)$dt;
        $ts = strtotime($dt);
        return $ts ? (int)$ts : 0;
    }
    private function parse_datetime_local_to_mysql($v){
        // input: YYYY-MM-DDTHH:MM (datetime-local)
        $v = trim((string)$v);
        if($v==='') return null;
        $v = str_replace('T',' ', $v);
        if(strlen($v)==16){ $v .= ':00'; }
        // basic validation
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return null;
        return $v;
    }
    private function run_is_currently_open($run, &$reason){
        $reason = '';
        if(!$run) { $reason = 'Pelaksanaan tidak ditemukan.'; return false; }

        // Keep backward compatibility: status field
        if(isset($run->status) && $run->status!=='active'){
            $reason = 'Survey tidak aktif.';
            return false;
        }

        // Auto-close if expired
        $end_ts = isset($run->end_date) ? $this->ts_from_mysql($run->end_date) : 0;
        if($end_ts>0 && $this->now_ts() > $end_ts){
            // if still marked active, persist the auto-close
            if(isset($run->is_active) && intval($run->is_active)===1){
                $db=$this->db(); $t=$this->tables();
                $db->update($t->runs, array('is_active'=>0,'status'=>'closed'), array('id'=>intval($run->id)));
                $run->is_active = 0;
                $run->status = 'closed';
            }
            $reason = 'Survey sudah melewati batas waktu dan ditutup otomatis.';
            return false;
        }

        if(isset($run->is_active) && intval($run->is_active)!==1){
            $reason = 'Survey sedang dinonaktifkan.';
            return false;
        }

        $start_ts = isset($run->start_date) ? $this->ts_from_mysql($run->start_date) : 0;
        if($start_ts>0 && $this->now_ts() < $start_ts){
            $reason = 'Survey belum dibuka.';
            return false;
        }

        return true;
    }

    public function auto_close_expired_runs(){
        // Lightweight: close any run past end_date that is still active
        $db=$this->db(); $t=$this->tables();
        // Ensure columns exist before running
        $col = $db->get_var($db->prepare("SHOW COLUMNS FROM {$t->runs} LIKE %s", 'end_date'));
        if(!$col) return;
        $now = (string) current_time('mysql');
        $db->query($db->prepare(
            "UPDATE {$t->runs} SET is_active=0, status='closed' WHERE end_date IS NOT NULL AND end_date < %s AND is_active=1",
            $now
        ));
    }

    public function ajax_toggle_run_active(){
        if(!current_user_can('manage_options')) wp_send_json_error('forbidden');
        check_ajax_referer('at_run_toggle');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;
        if(!$id) wp_send_json_error('bad_id');
        $db=$this->db(); $t=$this->tables();
        $db->update($t->runs, array('is_active'=>($status?1:0), 'status'=>($status? 'active':'closed')), array('id'=>$id));
        wp_send_json_success();
    }

    /* ================= Activation (create tables) ================= */
    public function activate(){
        // WordPress menandai plugin bermasalah jika ada notice/warning yang tercetak
        // saat aktivasi. Buffer ini mencegah output tak sengaja dari dbDelta/ALTER
        // bercampur dengan header aktivasi, tanpa mengubah proses pembuatan tabel.
        $at_activation_ob_level = ob_get_level();
        ob_start();

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = $this->tables();
        // Evidence link mode: tidak membuat folder upload bukti.

        $sql = "
        CREATE TABLE {$t->units} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->surveys} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(191) NOT NULL,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->questions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id BIGINT UNSIGNED NOT NULL,
            question_text TEXT NOT NULL,
            qtype ENUM('text','textarea','number','date','radio','select','checkbox','label') NOT NULL DEFAULT 'text',
            options_csv TEXT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            requires_file TINYINT(1) NOT NULL DEFAULT 0,
            allow_other TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            
            cond_parent_id BIGINT UNSIGNED NULL,
            cond_operator VARCHAR(20) NULL,
            cond_value TEXT NULL,
            cond_action VARCHAR(10) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY survey_id (survey_id)
        ) $charset;

        CREATE TABLE {$t->runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_name VARCHAR(191) NULL,
            survey_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            year INT NOT NULL,
            survey_type VARCHAR(20) NOT NULL DEFAULT 'general',
            group_id BIGINT UNSIGNED NULL,
            status ENUM('active','closed') NOT NULL DEFAULT 'active',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            start_date DATETIME NULL,
            end_date DATETIME NULL,
            password VARCHAR(191) NULL,
            KEY survey_unit_year (survey_id, unit_id, year),
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->responses} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            survey_id BIGINT UNSIGNED NOT NULL,
            year INT NOT NULL,
            ip_address VARCHAR(45) NULL,
            fill_status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            completed_at DATETIME NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY fill_status (fill_status)
        ) $charset;

        CREATE TABLE {$t->answers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            response_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            answer_text TEXT NULL,
            file_url TEXT NULL,
            file_path TEXT NULL,
            file_name VARCHAR(255) NULL,
            file_mime VARCHAR(100) NULL,
            file_size BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY response_id (response_id),
            KEY question_id (question_id)
        ) $charset;


        CREATE TABLE {$t->id_templates} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->id_elements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            field_label VARCHAR(191) NOT NULL,
            field_type ENUM('text','select','textarea') NOT NULL DEFAULT 'text',
            options_csv TEXT NULL,
            editable_by_user TINYINT(1) NOT NULL DEFAULT 0,
            show_in_results TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY template_id (template_id)
        ) $charset;

        CREATE TABLE {$t->user_groups} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY template_id (template_id)
        ) $charset;

        CREATE TABLE {$t->users} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NULL,
            username VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_username (username),
            KEY group_id (group_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->user_identity} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            element_id BIGINT UNSIGNED NOT NULL,
            value_long TEXT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY element_id (element_id)
        ) $charset;


        CREATE TABLE {$t->unit_heads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wp_user (wp_user_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->data_processors} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wp_user (wp_user_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset;

        ";
        dbDelta($sql);

        // Pastikan tidak ada output tersisa selama aktivasi.
        while (ob_get_level() > $at_activation_ob_level) {
            ob_end_clean();
        }
    }

    
    /* ================= Upgrade (ensure qtype enum supports latest types) ================= */
    public function maybe_upgrade_schema(){
        global $wpdb;
        $t = $this->tables();
        $charset = $wpdb->get_charset_collate();
        // Evidence link mode: tidak membuat folder upload bukti.
        // Tabel pengolah data untuk instalasi lama.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t->data_processors} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wp_user (wp_user_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset");

        // Ensure qtype enum is correct
        // NOTE: MySQL ENUM must explicitly include all supported values,
        // otherwise inserts for new types will fail silently.
        $sql = "ALTER TABLE {$t->questions} MODIFY qtype ENUM('text','textarea','number','date','radio','select','checkbox','label') NOT NULL DEFAULT 'text'";
        $wpdb->query($sql); // suppress errors if already correct

        // Add "other" option flag for radio/select
        $exists_other = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->questions} LIKE %s", 'allow_other') );
        if(!$exists_other){
            $wpdb->query("ALTER TABLE {$t->questions} ADD COLUMN allow_other TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required");
        }
        $exists_requires_file = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->questions} LIKE %s", 'requires_file') );
        if(!$exists_requires_file){
            $wpdb->query("ALTER TABLE {$t->questions} ADD COLUMN requires_file TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required");
        }

        // Add secure evidence upload columns to answers (safe for existing installs)
        $answer_file_cols = array(
            'file_url'  => "ALTER TABLE {$t->answers} ADD COLUMN file_url TEXT NULL AFTER answer_text",
            'file_path' => "ALTER TABLE {$t->answers} ADD COLUMN file_path TEXT NULL AFTER file_url",
            'file_name' => "ALTER TABLE {$t->answers} ADD COLUMN file_name VARCHAR(255) NULL AFTER file_path",
            'file_mime' => "ALTER TABLE {$t->answers} ADD COLUMN file_mime VARCHAR(100) NULL AFTER file_name",
            'file_size' => "ALTER TABLE {$t->answers} ADD COLUMN file_size BIGINT UNSIGNED NULL AFTER file_mime",
        );
        foreach($answer_file_cols as $col=>$alter){
            $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->answers} LIKE %s", $col) );
            if(!$exists){ $wpdb->query($alter); }
        }

        // Add conditional-logic columns (safe for existing installs)
        $cols = array(
            'cond_parent_id' => "ALTER TABLE {$t->questions} ADD COLUMN cond_parent_id BIGINT UNSIGNED NULL AFTER sort_order",
            'cond_operator'  => "ALTER TABLE {$t->questions} ADD COLUMN cond_operator VARCHAR(20) NULL AFTER cond_parent_id",
            'cond_value'     => "ALTER TABLE {$t->questions} ADD COLUMN cond_value TEXT NULL AFTER cond_operator",
            'cond_action'    => "ALTER TABLE {$t->questions} ADD COLUMN cond_action VARCHAR(10) NULL AFTER cond_value",
        );
        foreach($cols as $col=>$alter){
            $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->questions} LIKE %s", $col) );
            if(!$exists){
                $wpdb->query($alter);
            }
        }


        // Add runs columns for run name, survey type & group restriction
        $run_cols = array(
            'run_name'    => "ALTER TABLE {$t->runs} ADD COLUMN run_name VARCHAR(191) NULL AFTER id",
            'survey_type' => "ALTER TABLE {$t->runs} ADD COLUMN survey_type VARCHAR(20) NOT NULL DEFAULT 'general' AFTER year",
            'group_id'    => "ALTER TABLE {$t->runs} ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER survey_type",
        );
        foreach($run_cols as $col=>$alter){
            $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->runs} LIKE %s", $col) );
            if(!$exists){ $wpdb->query($alter); }
        }

        // v2.2.6: Pelaksanaan survey harus boleh dibuat lebih dari satu kali
        // untuk kombinasi survey, unit, dan tahun yang sama selama nama pelaksanaannya berbeda.
        // Versi lama memakai UNIQUE KEY uniq(survey_id, unit_id, year), sehingga insert kedua gagal.
        $run_unique = $wpdb->get_var("SHOW INDEX FROM {$t->runs} WHERE Key_name = 'uniq'");
        if($run_unique){
            $wpdb->query("ALTER TABLE {$t->runs} DROP INDEX uniq");
        }
        $lookup_idx = $wpdb->get_var("SHOW INDEX FROM {$t->runs} WHERE Key_name = 'survey_unit_year'");
        if(!$lookup_idx){
            $wpdb->query("ALTER TABLE {$t->runs} ADD KEY survey_unit_year (survey_id, unit_id, year)");
        }

        // Add runs lifecycle columns (activate/deactivate + schedule)
        $life_cols = array(
            'is_active'  => "ALTER TABLE {$t->runs} ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status",
            'start_date' => "ALTER TABLE {$t->runs} ADD COLUMN start_date DATETIME NULL AFTER is_active",
            'end_date'   => "ALTER TABLE {$t->runs} ADD COLUMN end_date DATETIME NULL AFTER start_date",
        );
        foreach($life_cols as $col=>$alter){
            $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->runs} LIKE %s", $col) );
            if(!$exists){ $wpdb->query($alter); }
        }

        // Add responses column for user_id (optional)
        $resp_exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->responses} LIKE %s", 'user_id') );
        if(!$resp_exists){
            $wpdb->query("ALTER TABLE {$t->responses} ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER ip_address");
        }

        // v2.2.9: status pengisian. Data lama dianggap completed agar hasil historis tidak hilang.
        $fill_status_exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->responses} LIKE %s", 'fill_status') );
        if(!$fill_status_exists){
            $wpdb->query("ALTER TABLE {$t->responses} ADD COLUMN fill_status VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER ip_address");
            $wpdb->query("UPDATE {$t->responses} SET fill_status='completed' WHERE fill_status IS NULL OR fill_status=''");
        }
        $completed_at_exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->responses} LIKE %s", 'completed_at') );
        if(!$completed_at_exists){
            $wpdb->query("ALTER TABLE {$t->responses} ADD COLUMN completed_at DATETIME NULL AFTER fill_status");
            $wpdb->query("UPDATE {$t->responses} SET completed_at=submitted_at WHERE fill_status='completed' AND completed_at IS NULL");
        }
        $fill_status_idx = $wpdb->get_var("SHOW INDEX FROM {$t->responses} WHERE Key_name = 'fill_status'");
        if(!$fill_status_idx){
            $wpdb->query("ALTER TABLE {$t->responses} ADD KEY fill_status (fill_status)");
        }

        // Ensure identity/group/user tables exist (safe dbDelta)
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql2 = "
        CREATE TABLE {$t->id_templates} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->id_elements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            field_label VARCHAR(191) NOT NULL,
            field_type ENUM('text','select','textarea') NOT NULL DEFAULT 'text',
            options_csv TEXT NULL,
            editable_by_user TINYINT(1) NOT NULL DEFAULT 0,
            show_in_results TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY template_id (template_id)
        ) $charset;

        CREATE TABLE {$t->user_groups} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY template_id (template_id)
        ) $charset;

        CREATE TABLE {$t->users} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_username (username),
            KEY group_id (group_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->user_identity} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            element_id BIGINT UNSIGNED NOT NULL,
            value_long TEXT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY element_id (element_id)
        ) $charset;

        CREATE TABLE {$t->unit_heads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wp_user (wp_user_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$t->data_processors} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id BIGINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wp_user (wp_user_id),
            KEY unit_id (unit_id),
            PRIMARY KEY (id)
        ) $charset;
        ";
        dbDelta($sql2);
        // Add users.unit_id (unit kerja) for user profile (safe alter)
        $u_unit = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$t->users} LIKE %s", 'unit_id') );
        if(!$u_unit){
            $wpdb->query("ALTER TABLE {$t->users} ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER group_id");
            $wpdb->query("ALTER TABLE {$t->users} ADD KEY unit_id (unit_id)");
        }

        // WA Survey settings table
        $sql3 = "
        CREATE TABLE {$t->wa_settings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            run_id BIGINT UNSIGNED NOT NULL,
            wa_field_key VARCHAR(100) NOT NULL,
            message_template LONGTEXT NOT NULL,
            fonnte_token TEXT NULL,
            country_code VARCHAR(10) NOT NULL DEFAULT '62',
            delay_seconds INT NOT NULL DEFAULT 0,
            schedule_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY run_id (run_id)
        ) $charset;";
        dbDelta($sql3);

        // WA send logs table
        $sql4 = "
        CREATE TABLE {$t->wa_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            setting_id BIGINT UNSIGNED NULL,
            phone VARCHAR(50) NULL,
            send_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            response_code INT NULL,
            response_body LONGTEXT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY run_user (run_id, user_id),
            KEY sent_at (sent_at)
        ) $charset;";
        dbDelta($sql4);

        // Add id_elements flags (editable / show on results)
        $this->id_element_columns_upgrade();


    }


    /* ================= Helpers ================= */
    private function get_current_unlock(){
        return (string)$this->current_unlock;
    }
    
    /* Password cookie helpers */
    private function pass_cookie_name($run_id){
        return 'akurasitara_pass_' . intval($run_id);
    }
    private function pass_make_token($password_plain){
        $salt = defined('AUTH_SALT') ? AUTH_SALT : ( defined('NONCE_SALT') ? NONCE_SALT : 'akurasitara-salt' );
        if (function_exists('hash_hmac')){
            return hash_hmac('sha256', (string)$password_plain, (string)$salt);
        }
        return md5((string)$password_plain . '|' . (string)$salt);
    }
    private function pass_cookie_valid($run){
        if (empty($run->password)) return true; // no password set
        $name = $this->pass_cookie_name($run->id);
        if (!isset($_COOKIE[$name])) return false;
        $token = (string)$_COOKIE[$name];
        $expected = $this->pass_make_token($run->password);
        return hash_equals((string)$expected, (string)$token);
    }

    private function esc($s){ return esc_html((string)$s); }

    // Parse "Label|Nilai, Label 2|N2" -> [['label'=>..., 'value_long' => ...], ...]
    private function parse_options_pairs($csv){
        $tokens = preg_split('/\s*,\s*/', (string)$csv);
        $out = array();
        foreach($tokens as $tok){
            $tok = trim($tok);
            if($tok==='') continue;
            if(strpos($tok,'|')!==false){
                list($label,$value) = explode('|',$tok,2);
                $label = trim($label); $value = trim($value);
            } else { $label = $tok; $value = $tok; }
			// Standarkan key agar konsisten dipakai di form & grafik
			$out[] = array(
				'label'      => $label,
				'value'      => $value,
				'value_long' => $value,
			);
        }



        return $out;
    }

// Static helpers used by admin pages
private static function split_csv($s){
    if($s===null) return array();
    $s = trim((string)$s);
    if($s==='') return array();
    $parts = preg_split('/\s*,\s*/', $s);
    $out = array();
    foreach($parts as $p){
        $p = trim($p);
        if($p==='') continue;
        $out[] = $p;
    }
    return $out;
}


    private function at_parse_options_map($options_csv){
        $map = array();
        $pairs = $this->parse_options_pairs($options_csv);
        foreach($pairs as $p){
            $v = isset($p['value_long']) ? (string)$p['value_long'] : '';
            $lab = isset($p['label']) ? (string)$p['label'] : '';
            if($v!=='' && !isset($map[$v])) $map[$v] = $lab;
        }
        return $map;
    }


    private function at_cond_value_from_post(){
        if(isset($_POST['cond_value_choices']) && is_array($_POST['cond_value_choices'])){
            $vals = array();
            foreach($_POST['cond_value_choices'] as $v){
                $v = sanitize_text_field(wp_unslash($v));
                $v = trim((string)$v);
                if($v!=='' && !in_array($v, $vals, true)) $vals[] = $v;
            }
            return implode(',', $vals);
        }
        return sanitize_text_field(wp_unslash($_POST['cond_value'] ?? ''));
    }

    private function at_cond_question_options_data($questions){
        $data = array();
        if(!$questions) return $data;
        foreach($questions as $cq){
            if(isset($cq->qtype) && (string)$cq->qtype === 'label') continue;
            $opts = array();
            if(isset($cq->qtype) && in_array((string)$cq->qtype, array('radio','select','checkbox'), true)){
                $pairs = $this->parse_options_pairs(isset($cq->options_csv) ? $cq->options_csv : '');
                foreach($pairs as $p){
                    $val = isset($p['value']) ? (string)$p['value'] : '';
                    $lab = isset($p['label']) ? (string)$p['label'] : $val;
                    if($val==='') continue;
                    $opts[] = array('value'=>$val, 'label'=>$lab);
                }
            }
            $data[(string)intval($cq->id)] = array(
                'id' => intval($cq->id),
                'text' => wp_strip_all_tags((string)$cq->question_text),
                'qtype' => isset($cq->qtype) ? (string)$cq->qtype : '',
                'options' => $opts,
            );
        }
        return $data;
    }

    private function at_render_cond_value_picker($current_value=''){
        $current_value = (string)$current_value;
        $html  = '<div class="at-cond-value-picker" data-current="'.esc_attr($current_value).'">';
        $html .= '<input type="hidden" name="cond_value" class="at-cond-value-hidden" value="'.esc_attr($current_value).'">';
        $html .= '<div class="at-cond-value-ui"><em>Pilih pertanyaan pemicu dan operator terlebih dahulu.</em></div>';
        $html .= '<p class="description" style="margin-top:6px;">Untuk pertanyaan pilihan, centang berdasarkan label jawaban. Nilai yang disimpan tetap value database.</p>';
        $html .= '</div>';
        return $html;
    }

    private function at_answer_display_mode(){
        $mode = isset($_GET['answer_display']) ? sanitize_text_field(wp_unslash($_GET['answer_display'])) : 'value';
        return in_array($mode, array('value','label'), true) ? $mode : 'value';
    }

    private function at_format_choice_answer($answer, $q, $mode='value'){
        $answer = (string)$answer;
        if($mode !== 'label') return $answer;
        $qt = isset($q->qtype) ? (string)$q->qtype : '';
        if(!in_array($qt, array('radio','select','checkbox'), true)) return $answer;
        $map = $this->at_parse_options_map(isset($q->options_csv) ? $q->options_csv : '');
        if(!$map || trim($answer)==='') return $answer;
        $parts = ($qt === 'checkbox') ? explode(',', $answer) : array($answer);
        $out = array();
        foreach($parts as $part){
            $raw = trim((string)$part);
            $out[] = ($raw !== '' && isset($map[$raw])) ? $map[$raw] : $raw;
        }
        return ($qt === 'checkbox') ? implode(', ', $out) : (isset($out[0]) ? $out[0] : $answer);
    }

    private function at_svg_point($cx, $cy, $r, $deg){
        $rad = deg2rad($deg);
        return array($cx + ($r * cos($rad)), $cy + ($r * sin($rad)));
    }

    private function at_svg_sector_path($cx, $cy, $r, $start_deg, $end_deg){
        $sweep = $end_deg - $start_deg;
        if($sweep >= 360){ $end_deg = $start_deg + 359.999; $sweep = 359.999; }
        list($x1, $y1) = $this->at_svg_point($cx, $cy, $r, $start_deg);
        list($x2, $y2) = $this->at_svg_point($cx, $cy, $r, $end_deg);
        $large = ($sweep > 180) ? 1 : 0;
        return 'M '.$cx.' '.$cy.' L '.round($x1,3).' '.round($y1,3).' A '.$r.' '.$r.' 0 '.$large.' 1 '.round($x2,3).' '.round($y2,3).' Z';
    }

    private function at_svg_donut_slice_path($cx, $cy, $outer_r, $inner_r, $start_deg, $end_deg){
        $sweep = $end_deg - $start_deg;
        if($sweep >= 360){ $end_deg = $start_deg + 359.999; $sweep = 359.999; }
        list($ox1, $oy1) = $this->at_svg_point($cx, $cy, $outer_r, $start_deg);
        list($ox2, $oy2) = $this->at_svg_point($cx, $cy, $outer_r, $end_deg);
        list($ix1, $iy1) = $this->at_svg_point($cx, $cy, $inner_r, $start_deg);
        list($ix2, $iy2) = $this->at_svg_point($cx, $cy, $inner_r, $end_deg);
        $large = ($sweep > 180) ? 1 : 0;
        return 'M '.round($ox1,3).' '.round($oy1,3)
            .' A '.$outer_r.' '.$outer_r.' 0 '.$large.' 1 '.round($ox2,3).' '.round($oy2,3)
            .' L '.round($ix2,3).' '.round($iy2,3)
            .' A '.$inner_r.' '.$inner_r.' 0 '.$large.' 0 '.round($ix1,3).' '.round($iy1,3)
            .' Z';
    }

    private function at_render_pie_donut_chart($items, $type='pie'){
        $type = ($type==='donut') ? 'donut' : 'pie';
        $total = 0;
        foreach($items as $it){ $total += intval($it['count']); }
        if($total<=0){ return '<div style="color:#666;">Belum ada data.</div>'; }

        $size = 260;
        $cx = $size / 2;
        $cy = $size / 2;
        $outer_r = 110;
        $inner_r = 58;
        $start_deg = -90.0;
        $colors = array();

        $html = '<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;">';
        $html .= '<div style="width:'.$size.'px;height:'.$size.'px;position:relative;">';
        $html .= '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'" role="img" aria-label="Grafik '.esc_attr($type).'">';

        foreach($items as $idx=>$it){
            $cnt = max(0, intval($it['count']));
            if($cnt<=0) continue;
            $pct = ($cnt/$total)*100.0;
            $angle = ($cnt/$total)*360.0;
            $end_deg = $start_deg + $angle;
            $h = ($idx*57) % 360;
            $color = 'hsl('.$h.',70%,55%)';
            $colors[] = $color;
            $path = ($type==='donut')
                ? $this->at_svg_donut_slice_path($cx, $cy, $outer_r, $inner_r, $start_deg, $end_deg)
                : $this->at_svg_sector_path($cx, $cy, $outer_r, $start_deg, $end_deg);
            $lab = isset($it['label']) ? (string)$it['label'] : '';
            $tooltip = $lab.' — '.number_format_i18n($pct, 2).'% ('.$cnt.' isian)';
            $html .= '<path d="'.$path.'" fill="'.$color.'" stroke="#ffffff" stroke-width="2">';
            $html .= '<title>'.esc_html($tooltip).'</title>';
            $html .= '</path>';
            $start_deg = $end_deg;
        }

        if($type==='donut'){
            $html .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$inner_r.'" fill="#ffffff"></circle>';
            $html .= '<text x="'.$cx.'" y="'.($cy - 4).'" text-anchor="middle" font-size="28" font-weight="700" fill="#111827">'.$total.'</text>';
            $html .= '<text x="'.$cx.'" y="'.($cy + 18).'" text-anchor="middle" font-size="12" fill="#6b7280">isian</text>';
        }

        $html .= '</svg>';
        $html .= '</div>';

        $html .= '<div style="min-width:260px;">';
        $html .= '<div style="font-weight:600;margin-bottom:8px;">Legenda</div>';
        $html .= '<ul style="list-style:none;margin:0;padding:0;">';
        foreach($items as $idx=>$it){
            $lab = isset($it['label']) ? (string)$it['label'] : '';
            $cnt = intval($it['count']);
            $pct = $total>0 ? (($cnt/$total)*100.0) : 0;
            $sw = isset($colors[$idx]) ? $colors[$idx] : '#999';
            $html .= '<li style="display:flex;align-items:center;gap:10px;margin:6px 0;">';
            $html .= '<span style="width:14px;height:14px;border-radius:3px;background:'.$sw.';display:inline-block;"></span>';
            $html .= '<span style="flex:1;min-width:0;">'.esc_html($lab).'</span>';
            $html .= '<span style="white-space:nowrap;font-weight:600;">'.number_format_i18n($pct, 2).'% • '.$cnt.'</span>';
            $html .= '</li>';
        }
        $html .= '</ul></div></div>';

        return $html;
    }

private static function parse_options($csv){
    $csv = $csv===null ? '' : (string)$csv;
    $csv = trim($csv);
    if($csv==='') return array();
    $items = preg_split('/\s*,\s*/', $csv);
    $out = array();
    foreach($items as $it){
        $it = trim($it);
        if($it==='') continue;
        // expected format: Label|Value  OR just Label (value = label)
        $parts = explode('|', $it, 2);
        $label = trim($parts[0]);
        $value = isset($parts[1]) ? trim($parts[1]) : $label;
        $out[] = array('label'=>$label, 'value'=>$value);
    }
    return $out;
}




// --- CSV helpers (Import Pengguna) ---
private function csv_norm_header($h){
    $h = trim((string)$h);
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // strip BOM
    $h = strtolower($h);
    $h = preg_replace('/\s+/', '_', $h);
    return $h;
}

private function csv_get_reader_rows($path){
    $rows = array();
    if(!file_exists($path)) return $rows;
    $fh = fopen($path, 'r');
    if(!$fh) return $rows;
    while(($line = fgets($fh)) !== false){
        // skip comment lines starting with #
        if(preg_match('/^\s*#/', $line)) continue;
        // rewind 1 line by using a temp buffer: easiest is to parse via str_getcsv
        $row = str_getcsv($line);
        // ignore empty row
        $all_empty = true;
        foreach($row as $c){ if(trim((string)$c)!==''){ $all_empty=false; break; } }
        if($all_empty) continue;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

    private function client_ip(){
        $ip='';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        if (strlen($ip) > 45) $ip = substr($ip,0,45);
        return $ip;
    }

    private 

function require_password($run){
        // No password configured
        if (empty($run->password)) { return true; }

        // Accept unlock via GET (transient)
        if (isset($_GET['at_unlock'])){
            $tok = sanitize_text_field( wp_unslash($_GET['at_unlock']) );
            $stored = get_transient('akurasitara_unlock_' . $tok);
            if ($stored && intval($stored) === intval($run->id)) {
                $this->current_unlock = $tok;
                return true;
            }
        }

        // Accept posted password once, then set transient unlock (no headers/cookies)
        if (isset($_POST['at_pass'])){
            $posted = isset($_POST['at_pass']) ? sanitize_text_field( wp_unslash($_POST['at_pass']) ) : '';
            if ($posted !== '' && hash_equals((string)$run->password, (string)$posted)) {
                $unlock = wp_generate_password(20, false, false);
                set_transient('akurasitara_unlock_' . $unlock, intval($run->id), 12 * HOUR_IN_SECONDS);
                $this->current_unlock = $unlock;
                return true;
            }
        }

        // Show password form
        echo '<form method="post" class="akurasitara-pass">
            <p>Survey ini dilindungi kata sandi.</p>
            <p><input type="password" name="at_pass" placeholder="Masukkan password" /></p>
            <p><button type="submit" class="button button-primary">Masuk</button></p>
        </form>';
        return false;
    }


    /* ================= Survey user login (for group-restricted runs) ================= */
    private function user_token_get_current(){
        return isset($this->current_user_token) ? (string)$this->current_user_token : '';
    }
    private function user_token_set_current($tok){
        $this->current_user_token = (string)$tok;
    }
    private function user_id_get_current(){
        return isset($this->current_user_id) ? intval($this->current_user_id) : 0;
    }
    private function user_id_set_current($uid){
        $this->current_user_id = intval($uid);
    }

    private function user_token_verify($run_id, $token){
        $stored = get_transient('akurasitara_user_' . $token);
        if(!$stored || !is_array($stored)) return 0;
        if(intval($stored['run_id'] ?? 0) !== intval($run_id)) return 0;
        return intval($stored['user_id'] ?? 0);
    }

    private function user_token_get_store($token){
        $stored = get_transient('akurasitara_user_' . $token);
        return (is_array($stored)) ? $stored : array();
    }

    private function user_token_set_store($token, $arr, $ttl = 0){
        if($ttl<=0) $ttl = 12 * HOUR_IN_SECONDS;
        set_transient('akurasitara_user_' . $token, $arr, $ttl);
    }

    private function is_run_group($run){
        return (isset($run->survey_type) && $run->survey_type === 'group' && !empty($run->group_id));
    }

    private function require_identity_confirmation($run){
        if(!$this->is_run_group($run)) return true;

        $tok = $this->user_token_get_current();
        $uid = $this->user_id_get_current();
        if($tok==='' || $uid<=0) return true; // login gate already handles

        $store = $this->user_token_get_store($tok);
        if(intval($store['confirmed'] ?? 0) === 1) return true;

        // Render confirmation page (user can edit only fields marked editable_by_user)
        $db=$this->db(); $t=$this->tables();
        $group = $db->get_row($db->prepare("SELECT * FROM {$t->user_groups} WHERE id=%d", intval($run->group_id)));
        if(!$group){
            // fallback: allow proceed if group missing (avoid lock)
            $store['confirmed']=1;
            $this->user_token_set_store($tok,$store);
            return true;
        }

        $elements = $db->get_results($db->prepare("SELECT * FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC, id ASC", intval($group->template_id)));
        $uname = $db->get_var($db->prepare("SELECT username FROM {$t->users} WHERE id=%d", intval($uid)));

        echo '<div class="akurasitara-survey at-scope"><div class="at-card"><div class="at-head"><div class="at-title">Konfirmasi Identitas</div>';
        echo '<div class="at-desc">Login sebagai <strong>'.$this->esc($uname).'</strong>. Silakan cek data identitas Anda. Field yang boleh diedit dapat Anda ubah sebelum melanjutkan.</div></div>';
        echo '<form method="post" class="at-form akurasitara-pass">';
        echo '<input type="hidden" name="at_profile_confirm" value="1">';
        echo '<input type="hidden" name="run_id" value="'.intval($run->id).'">';
        echo '<input type="hidden" name="at_user_token" value="'.esc_attr($tok).'">';
        $scheme = is_ssl() ? 'https://' : 'http://';
        $req_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $cur_url = esc_url_raw($scheme.$host.$req_uri);
        // Ensure token persists after confirmation redirect
        $cur_url = add_query_arg('at_user_token', $tok, $cur_url);
        echo '<input type="hidden" name="return_to" value="'.esc_attr($cur_url).'"/>';

        echo '<div class="at-section">';
        if($elements){
            echo '<table class="form-table at-identity">';
            foreach($elements as $el){
                $val = $db->get_var($db->prepare("SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", intval($uid), intval($el->id)));
                $val = (string)$val;
                $can_edit = intval($el->editable_by_user)===1;
                $field_name = 'id_el_'.intval($el->id);
                echo '<tr><th>'.$this->esc($el->field_label).'</th><td>';
                if($can_edit){
                    if($el->field_type==='textarea'){
                        echo '<textarea class="at-control" name="'.esc_attr($field_name).'" rows="3">'.esc_textarea($val).'</textarea>';
                    } elseif($el->field_type==='select'){
                        $pairs = $this->parse_options_pairs($el->options_csv);
                        echo '<select class="at-control" name="'.esc_attr($field_name).'"><option value="">-- pilih --</option>';
                        foreach($pairs as $p){
                            echo '<option value="'.esc_attr($p['value']).'" '.selected($val,$p['value'],false).'>'.$this->esc($p['label']).'</option>';
                        }
                        echo '</select>';
                    } else {
                        echo '<input class="at-control" type="text" name="'.esc_attr($field_name).'" value="'.esc_attr($val).'">';
                    }
                } else {
                    echo '<span class="at-readonly">'.($val!==''?$this->esc($val):'<span class="at-note">-</span>').'</span></span>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        } else {
            echo '<p><em>Tidak ada elemen identitas.</em></p>';
        }
        echo '<div class="at-actions"><button type="submit" class="at-btn at-btn-primary">Konfirmasi &amp; Lanjut</button></div>';
        echo '</div>';
        echo '</form></div></div>';
        return false;
    }

    private function get_result_identity_elements_for_run($run){
        if(!$this->is_run_group($run)) return array();
        $db=$this->db(); $t=$this->tables();
        $group = $db->get_row($db->prepare("SELECT * FROM {$t->user_groups} WHERE id=%d", intval($run->group_id)));
        if(!$group) return array();
        return $db->get_results($db->prepare(
            "SELECT * FROM {$t->id_elements} WHERE template_id=%d AND show_in_results=1 ORDER BY sort_order ASC, id ASC",
            intval($group->template_id)
        ));
    }

    private function require_group_login($run){
        // Only for survey_type = group with a selected group_id
        if (!isset($run->survey_type) || $run->survey_type !== 'group' || empty($run->group_id)) {
            return true;
        }

        $t = $this->tables();
        $db = $this->db();

        // Accept token via GET
        if (isset($_GET['at_user_token'])){
            $tok = sanitize_text_field( wp_unslash($_GET['at_user_token']) );
            $uid = $this->user_token_verify($run->id, $tok);
            if($uid>0){
                $this->user_token_set_current($tok);
                $this->user_id_set_current($uid);
                return true;
            }
        }

        // Accept token from hidden field (POST redirect-less paging)
        if (isset($_POST['at_user_token'])){
            $tok = sanitize_text_field( wp_unslash($_POST['at_user_token']) );
            $uid = $this->user_token_verify($run->id, $tok);
            if($uid>0){
                $this->user_token_set_current($tok);
                $this->user_id_set_current($uid);
                return true;
            }
        }

        $captcha_ctx = 'login_run_' . intval($run->id);

        // Handle login attempt
        if (isset($_POST['at_user_login'])){
            $username = sanitize_text_field( wp_unslash($_POST['at_username'] ?? '') );
            $password = (string) (wp_unslash($_POST['at_password'] ?? ''));
            $cap_tok = sanitize_text_field( wp_unslash($_POST['at_captcha_token'] ?? '') );
            $cap_ans = sanitize_text_field( wp_unslash($_POST['at_captcha_answer'] ?? '') );

            if(!$this->at_captcha_verify($cap_tok, $cap_ans, $captcha_ctx)){
                echo '<div class="akurasitara-error">Captcha salah. Silakan coba lagi.</div>';
            } else {
            if($username !== '' && $password !== ''){
                $user = $db->get_row( $db->prepare("SELECT * FROM {$t->users} WHERE username=%s", $username) );
                if($user && intval($user->group_id) === intval($run->group_id) && function_exists('wp_check_password') && wp_check_password($password, $user->password_hash)){
                    $tok = wp_generate_password(24, false, false);
                    set_transient('akurasitara_user_' . $tok, array('run_id'=>intval($run->id),'user_id'=>intval($user->id)), 12 * HOUR_IN_SECONDS);
                    $this->user_token_set_current($tok);
                    $this->user_id_set_current(intval($user->id));
                    return true;
                }
            }
            echo '<div class="akurasitara-error">Username atau password tidak valid.</div>';
            }
        }

        // Show login form
        echo '<div class="akurasitara-survey at-scope"><div class="at-card at-auth">';
        echo '<div class="at-head"><div class="at-title">Login</div><div class="at-desc">Survey ini khusus untuk pengguna tertentu. Silakan login terlebih dahulu.</div></div>';
        echo '<form method="post" class="at-form akurasitara-pass">';
        echo '<div class="at-section">';
        echo '<div class="at-field"><label class="at-label">Username</label><input class="at-control" type="text" name="at_username" placeholder="Masukkan username" autocomplete="username" required></div>';
        echo '<div class="at-field"><label class="at-label">Password</label><input class="at-control" type="password" name="at_password" placeholder="Masukkan password" autocomplete="current-password" required></div>';
        $this->at_render_captcha_field($captcha_ctx);
        echo '<div class="at-actions"><button type="submit" name="at_user_login" value="1" class="at-btn at-btn-primary">Login</button></div>';
        echo '</div>';
        echo '</form></div></div>';
        return false;
    }

    
    /* ================= Image Captcha (Self-contained, No External Libraries) ================= */
    private function at_captcha_make($ctx){
        // Generate short random code
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // avoid confusing chars
        $text = '';
        for($i=0;$i<5;$i++){
            $text .= $alphabet[rand(0, strlen($alphabet)-1)];
        }
        $token = substr(md5(uniqid('', true)),0,18);
        // Store plaintext for image rendering; verify will compare and then delete (one-time use)
        set_transient('akurasitara_captcha_' . $token, array('text'=>$text, 'ctx'=>(string)$ctx, 'ts'=>time()), 10 * MINUTE_IN_SECONDS);
        return array('token'=>$token, 'text'=>$text);
    }

    private function at_captcha_verify($token, $answer, $ctx){
        $token = (string)$token;
        if($token==='') return false;
        $store = get_transient('akurasitara_captcha_' . $token);
        // one-time use
        delete_transient('akurasitara_captcha_' . $token);
        if(!$store || !is_array($store)) return false;
        if(!isset($store['ctx']) || (string)$store['ctx'] !== (string)$ctx) return false;

        $expected = isset($store['text']) ? (string)$store['text'] : '';
        $given = strtoupper(preg_replace('/\s+/', '', (string)$answer));
        $expected = strtoupper($expected);
        if($expected==='') return false;
        return hash_equals($expected, $given);
    }

    public function ajax_captcha_img(){
        $token = sanitize_text_field( wp_unslash($_GET['token'] ?? '') );
        $ctx   = sanitize_text_field( wp_unslash($_GET['ctx'] ?? '') );

        $store = ($token!=='') ? get_transient('akurasitara_captcha_' . $token) : false;

        $text = '';
        if($store && is_array($store) && isset($store['text']) && isset($store['ctx']) && (string)$store['ctx']===(string)$ctx){
            $text = (string)$store['text'];
        }

        // SVG captcha (no GD dependency)
        nocache_headers();
        header('Content-Type: image/svg+xml; charset=utf-8');

        $w = 180; $h = 54;
        $bg1 = '#f8fafc'; $bg2 = '#eef2ff';
        $stroke = '#94a3b8';

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $svg .= '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$bg1.'"/><stop offset="1" stop-color="'.$bg2.'"/></linearGradient></defs>';
        $svg .= '<rect x="0" y="0" width="'.$w.'" height="'.$h.'" rx="12" fill="url(#g)" stroke="'.$stroke.'"/>';
        // noise lines
        for($i=0;$i<6;$i++){
            $x1 = rand(0,$w); $y1 = rand(0,$h);
            $x2 = rand(0,$w); $y2 = rand(0,$h);
            $svg .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="#cbd5e1" stroke-width="2" opacity="0.7"/>';
        }
        // dots
        for($i=0;$i<30;$i++){
            $cx = rand(6,$w-6); $cy = rand(6,$h-6); $r = rand(1,2);
            $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="#cbd5e1" opacity="0.7"/>';
        }

        // If token invalid/expired, show placeholder
        if($text===''){
            $svg .= '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="ui-sans-serif,system-ui" font-size="14" fill="#64748b">Captcha expired</text>';
            $svg .= '</svg>';
            echo $svg;
            wp_die();
        }

        // Draw characters with slight jitter
        $x = 22;
        for($i=0;$i<strlen($text);$i++){
            $ch = substr($text,$i,1);
            $y  = 34 + rand(-4,4);
            $rot = rand(-18,18);
            $size = 26 + rand(-2,2);
            $svg .= '<text x="'.$x.'" y="'.$y.'" font-family="ui-sans-serif,system-ui" font-weight="800" font-size="'.$size.'" fill="#0f172a" transform="rotate('.$rot.' '.$x.' '.$y.')">'.$ch.'</text>';
            $x += 28;
        }
        $svg .= '</svg>';

        echo $svg;
        wp_die();
    }

    private function at_render_captcha_field($ctx){
        $cap = $this->at_captcha_make($ctx);

        $img_url = admin_url('admin-ajax.php?action=akurasitara_captcha_img')
            . '&token=' . rawurlencode($cap['token'])
            . '&ctx='   . rawurlencode((string)$ctx)
            . '&_=' . time();

        echo '<div class="at-field">'
            .'<label class="at-label">Captcha</label>'
            .'<div class="at-captcha-wrap" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">'
                .'<img src="'.esc_url($img_url).'" alt="Captcha" style="height:54px;width:180px;border-radius:12px;border:1px solid #e2e8f0;"/>'
                .'<div style="flex:1;min-width:200px;">'
                    .'<input class="at-control" type="text" name="at_captcha_answer" placeholder="Ketik teks pada gambar" autocomplete="off" required>'
                    .'<div class="at-note" style="margin-top:6px;">Jika sulit dibaca, refresh halaman untuk mendapatkan captcha baru.</div>'
                .'</div>'
            .'</div>'
            .'<input type="hidden" name="at_captcha_token" value="'.esc_attr($cap['token']).'">'
            .'</div>';
    }


/* ================= Clean CSV Downloads (Template & Export) ================= */
    public function handle_downloads(){
		// Export CSV Hasil Kepala Unit harus diproses SEBELUM admin header tercetak.
		// Jangan batasi hanya manage_options karena Kepala Unit juga butuh export.
		if (isset($_GET['page'], $_GET['export_csv'], $_GET['run_id']) && $_GET['page']===self::SLUG.'_head_results' && $_GET['export_csv']=='1') {
			$run_id = intval($_GET['run_id']);
			$is_admin = current_user_can('manage_options');
			$head_unit_id = $this->get_unit_role_id_for_current_user();
			if(!$is_admin && !$head_unit_id){
				wp_die('Akses ditolak.');
			}

			// Unit filter: admin bisa pilih unit_id, kepala unit selalu unitnya.
			$unit_id = $is_admin ? intval($_GET['unit_id'] ?? $head_unit_id) : $head_unit_id;
			if(!$unit_id) $unit_id = $head_unit_id;

			// Nonce
			$nonce_action = 'at_export_head_csv_'.$run_id;
			if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
				wp_die('Nonce invalid.');
			}

			// Pastikan tidak ada output yang mengganggu header CSV
			while (ob_get_level()) { ob_end_clean(); }
			$latest_only = isset($_GET['latest_only']) ? intval($_GET['latest_only']) : 0;
			$answer_display = $this->at_answer_display_mode();
			$fill_status_filter = sanitize_text_field($_GET['fill_status'] ?? 'completed');
			if(!in_array($fill_status_filter, array('completed','in_progress','all'), true)) $fill_status_filter = 'completed';
			$this->export_head_results_csv($unit_id, $run_id, $latest_only, $answer_display, $fill_status_filter);
			exit;
		}

		if ( ! current_user_can('manage_options')) return;

        // Download Template Users CSV
        if (isset($_GET['page'], $_GET['at_download_users_template'], $_GET['group_id']) && $_GET['page']===self::SLUG.'_users' && $_GET['at_download_users_template']=='1') {
            $group_id = intval($_GET['group_id']);
            $nonce_action = 'at_download_users_template';
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
                wp_die('Nonce invalid.');
            }
            global $wpdb;
            // Ambil unit_id dari kelompok pengguna
            $group = $wpdb->get_row($wpdb->prepare("SELECT unit_id FROM {$this->table_user_groups} WHERE id=%d", $group_id));
            $unit_id = $group ? intval($group->unit_id) : 0;

            // Ambil template_id dari kelompok pengguna, lalu ambil elemen identitasnya dari tabel id_elements
            $t = self::tables();
            $template_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT template_id FROM {$t->user_groups} WHERE id=%d",
                $group_id
            ));

            $elements = [];
            if ($template_id > 0) {
                $elements = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, field_label AS label, field_type AS type, options_csv AS options FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC, id ASC",
                    $template_id
                ));
            }

            // Header CSV: username, password, unit_id + label elemen (contoh: Nama, NIM)
            $headers = ['username','password','unit_id'];
            if ($elements) {
                foreach ($elements as $el) {
                    $label = trim((string)$el->label);
                    $label = preg_replace('/\s+/', ' ', $label);
                    $label = str_replace(["\r","\n",','], ' ', $label);
                    $headers[] = $label;
                }
            }

            while (ob_get_level()) { ob_end_clean(); }
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="akurasitara_users_template_group_'.$group_id.'.csv"');
            $out = fopen('php://output','w');
	            // UTF-8 BOM untuk Excel (hindari karakter "Ã¯Â»Â¿" di header)
	            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

	            // Baris contoh (opsional) untuk memudahkan
	            $example = ['username_contoh','password123',$unit_id];
	            if ($elements) {
	                foreach ($elements as $el) {
	                    // Jika dropdown (select), pakai format Label|Value
                        $is_select = !empty($el->options);
	                    $example[] = ($is_select ? 'Label|Value' : '');
	                }
	            }
	            fputcsv($out, $example);

	            // Catatan referensi (akan diabaikan saat import karena diawali '#')
	            $blank = array_fill(0, count($headers), '');
	            fputcsv($out, $blank);
	            $note = $blank; $note[0] = '# Referensi Unit Kerja (unit_id):';
	            fputcsv($out, $note);
	            $t = self::tables();
	            $units = $wpdb->get_results("SELECT id, name FROM {$t->units} ORDER BY id ASC");
	            if ($units) {
	                foreach ($units as $u) {
	                    $r = $blank;
	                    $r[0] = '# '.$u->id.' = '.$u->name;
	                    fputcsv($out, $r);
	                }
	            } else {
	                $r = $blank;
	                $r[0] = '# (Belum ada data Unit Kerja)';
	                fputcsv($out, $r);
	            }
	            // Mapping kolom identitas ke label (supaya template mudah dipahami)
	            if ($elements) {
	                fputcsv($out, $blank);
	                $m = $blank; $m[0] = '# Mapping kolom identitas (kolom -> label):';
	                fputcsv($out, $m);
	                foreach ($elements as $el) {
	                    $r = $blank;
	                    $label = isset($el->label) ? trim((string)$el->label) : '';
	                    $r[0] = '# id_'.$el->id.' = '.$label;
	                    fputcsv($out, $r);
	                }
	            }
	            // Referensi pilihan untuk elemen bertipe select (isi VALUE saja)
	            foreach ($elements as $el) {
	                if (!isset($el->type) || $el->type !== 'select') continue;
	                $raw = isset($el->options) ? trim((string)$el->options) : '';
	                if ($raw === '') continue;

	                $values = array();
	                foreach (explode(',', $raw) as $opt) {
	                    $opt = trim($opt);
	                    if ($opt === '') continue;
	                    $parts = explode('|', $opt, 2);
	                    $value = (count($parts) === 2) ? trim($parts[1]) : trim($parts[0]);
	                    if ($value !== '') $values[] = $value;
	                }

	                if (!empty($values)) {
	                    fputcsv($out, array("# Pilihan untuk {$el->label} (select) - isi VALUE saja: " . implode(', ', $values)));
	                }
	            }

$note2 = $blank;
	            $note2[0] = '# Catatan dropdown (select): isi VALUE saja (tanpa "Label|"). Contoh: L';
	            fputcsv($out, $note2);

            fclose($out);
            exit;
        }


        // Download Template Pertanyaan CSV
        if (isset($_GET['page'], $_GET['download_template']) && $_GET['page']===self::SLUG.'_questions' && $_GET['download_template']=='1'){
            $sid = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : 0;
            while (ob_get_level()) { ob_end_clean(); }
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="akurasitara_template_pertanyaan'.($sid?'_survey_'.$sid:'').'.csv"');
            $out = fopen('php://output','w');
            fputcsv($out, array('question_text','qtype','options_csv','is_required','sort_order'));
            fputcsv($out, array('Seberapa puas Anda?','radio','Sangat Tidak Puas|1, Tidak Puas|2, Puas|3, Sangat Puas|4','1','10'));
            fputcsv($out, array('Saran untuk kami','textarea','','0','20'));
            fclose($out);
            exit;
        }

        // Export Hasil CSV
        if (isset($_GET['page'], $_GET['export'], $_GET['run_id']) && $_GET['page']===self::SLUG.'_results' && $_GET['export']=='1'){
            $db=$this->db(); $t=$this->tables();
            $run_id = intval($_GET['run_id']);
            $latest_only = isset($_GET['latest_only']) ? intval($_GET['latest_only']) : 0;
            $answer_display = $this->at_answer_display_mode();
            $fill_status_filter = sanitize_text_field($_GET['fill_status'] ?? 'completed');
            if(!in_array($fill_status_filter, array('completed','in_progress','all'), true)) $fill_status_filter = 'completed';
            $run = $db->get_row($db->prepare("SELECT r.*, s.title AS survey_title, u.name AS unit_name FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id JOIN {$t->units} u ON u.id=r.unit_id WHERE r.id=%d",$run_id));
            if($run){
                $qs = $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d AND qtype<>'label' ORDER BY sort_order ASC, id ASC",$run->survey_id));
                if($latest_only && $this->is_run_group($run)){
                    $responses = $db->get_results($db->prepare("
                        SELECT r1.* 
                        FROM {$t->responses} r1
                        INNER JOIN (
                            SELECT user_id, MAX(id) AS max_id
                            FROM {$t->responses}
                            WHERE run_id=%d AND ".($fill_status_filter==='all' ? "1=1" : $db->prepare("fill_status=%s", $fill_status_filter))." AND user_id IS NOT NULL AND user_id > 0
                            GROUP BY user_id
                        ) x ON x.max_id = r1.id
                        ORDER BY r1.id ASC
                    ", $run_id));
                } else {
                    if($fill_status_filter==='all'){
                        $responses = $db->get_results($db->prepare("SELECT * FROM {$t->responses} WHERE run_id=%d ORDER BY id ASC",$run_id));
                    } else {
                        $responses = $db->get_results($db->prepare("SELECT * FROM {$t->responses} WHERE run_id=%d AND fill_status=%s ORDER BY id ASC",$run_id,$fill_status_filter));
                    }
                }
                while (ob_get_level()) { ob_end_clean(); }
                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="akurasitara_results_run_'.$run_id.'.csv"');
                $out = fopen('php://output','w');
                $header = array('response_id','fill_status','submitted_at','completed_at','ip_address','unit_id','unit_name');
                $id_cols = $this->get_result_identity_elements_for_run($run); // group-only; returns [] if not applicable
                if($run->survey_type!=='general' && $id_cols){
                    $header[] = 'username';
                    foreach($id_cols as $el){
                        $header[] = 'ID: '.preg_replace('/\s+/', ' ', strip_tags($el->field_label));
                    }
                }
                foreach($qs as $q){ $header[] = 'Q'.$q->id.': '.preg_replace('/\s+/', ' ', strip_tags($q->question_text)); }
                fputcsv($out, $header);
                foreach($responses as $resp){
                    $unit_id_val = '';
                    $unit_name_val = '';
                    if(intval($resp->user_id) > 0){
                        $urow = $db->get_row($db->prepare("SELECT u.unit_id, un.name AS unit_name FROM {$t->users} u LEFT JOIN {$t->units} un ON un.id = u.unit_id WHERE u.id=%d", intval($resp->user_id)));
                        if($urow){
                            $unit_id_val = isset($urow->unit_id) ? $urow->unit_id : '';
                            $unit_name_val = isset($urow->unit_name) ? $urow->unit_name : '';
                        }
                    }
                    if($unit_id_val === '' && isset($run->unit_id)) $unit_id_val = $run->unit_id;
                    if($unit_name_val === '' && isset($run->unit_name)) $unit_name_val = $run->unit_name;
                    $row = array($resp->id, $resp->fill_status, $resp->submitted_at, $resp->completed_at, $resp->ip_address, $unit_id_val, $unit_name_val);
                    if($run->survey_type!=='general' && $id_cols){
                        $uname = $db->get_var($db->prepare("SELECT username FROM {$t->users} WHERE id=%d", intval($resp->user_id)));
                        $row[] = $uname;
                        foreach($id_cols as $el){
                            $v = $db->get_var($db->prepare("SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", intval($resp->user_id), intval($el->id)));
                            $row[] = $v;
                        }
                    }
                    foreach($qs as $q){
                        $ans = $db->get_var($db->prepare("SELECT answer_text FROM {$t->answers} WHERE response_id=%d AND question_id=%d",$resp->id,$q->id));
                        $row[] = $this->at_format_choice_answer($ans, $q, $answer_display);
                    }
                    fputcsv($out, $row);
                }
                fclose($out);
                exit;
            }
        }
    }

    /* ================= Admin Menu ================= */
    public function admin_menu(){
        add_menu_page('AkurasiTara','AkurasiTara','manage_options',self::SLUG,array($this,'page_units'),'dashicons-forms',26);
        add_submenu_page(self::SLUG,'Unit Kerja','Unit Kerja','manage_options',self::SLUG,array($this,'page_units'));
        add_submenu_page(self::SLUG,'Master Survey','Master Survey','manage_options',self::SLUG.'_surveys',array($this,'page_surveys'));
        add_submenu_page(self::SLUG,'Pertanyaan','Pertanyaan','manage_options',self::SLUG.'_questions',array($this,'page_questions'));
        add_submenu_page(self::SLUG,'Pelaksanaan Survey','Pelaksanaan','manage_options',self::SLUG.'_runs',array($this,'page_runs'));
        add_submenu_page(self::SLUG,'Hasil','Hasil','manage_options',self::SLUG.'_results',array($this,'page_results'));
		// Halaman untuk edit satu response (diakses dari tombol Edit pada Hasil)
		add_submenu_page(null,'Edit Hasil Survey','Edit Hasil Survey','read',self::SLUG.'_results_edit',array($this,'page_results_edit'));
		// Kepala unit (tercatat di tabel unit_heads) juga boleh akses grafik.
		add_submenu_page(self::SLUG,'Grafik Hasil','Grafik Hasil','read',self::SLUG.'_charts',array($this,'page_charts'));
        add_submenu_page(self::SLUG,'Monitoring Pengisian','Monitoring Pengisian','read',self::SLUG.'_participation',array($this,'page_participation'));
        add_submenu_page(self::SLUG,'Template Identitas Pengguna','Template Identitas','manage_options',self::SLUG.'_id_templates',array($this,'page_id_templates'));
        add_submenu_page(self::SLUG,'Kelompok Pengguna','Kelompok Pengguna','manage_options',self::SLUG.'_user_groups',array($this,'page_user_groups'));
        // halaman turunan
        add_submenu_page(self::SLUG,'Elemen Identitas','Elemen Identitas','manage_options',self::SLUG.'_id_elements',array($this,'page_id_elements'));
        add_submenu_page(self::SLUG,'Pengguna','Pengguna','manage_options',self::SLUG.'_users',array($this,'page_users'));
        add_submenu_page(self::SLUG,'Set Kepala Unit','Set Kepala Unit','manage_options',self::SLUG.'_unit_heads',array($this,'page_unit_heads'));
        add_submenu_page(self::SLUG,'Set Pengolah Data','Set Pengolah Data','manage_options',self::SLUG.'_data_processors',array($this,'page_data_processors'));
        add_submenu_page(self::SLUG,'WA-Survey Setting','WA-Survey Setting','manage_options',self::SLUG.'_wa_settings',array($this,'page_wa_settings'));
        add_submenu_page(self::SLUG,'Hasil Kepala Unit','Hasil Kepala Unit','read',self::SLUG.'_head_results',array($this,'page_head_results'));
    }

    /* ======= Admin footer: confirm handler + chart CSS ======= */
    public function admin_footer_confirm(){
        if (defined('REST_REQUEST') && REST_REQUEST) { return; }
        // Admin always; Kepala Unit juga butuh CSS grafik (tanpa fitur delete)
        if (!current_user_can('manage_options') && !$this->is_kepala_unit() && !$this->is_data_processor()) return;
        // Chart.js kadang membuat tinggi canvas mengikuti lebar (aspect ratio) sehingga tampak "terlalu besar".
        // Paksa tinggi wrapper + canvas agar konsisten.
        echo '<style>.at-chart-wrap{max-width:760px;margin:16px 0;height:auto}.at-chart-wrap canvas{width:100%!important;max-width:100%;height:100%!important}.at-simple-chart{max-width:760px;margin:16px 0}.at-bar-chart .at-bar-row{display:flex;align-items:center;gap:10px;margin:8px 0}.at-bar-chart .at-bar-label{flex:0 0 220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.at-bar-chart .at-bar-track{flex:1;height:12px;background:#e9eef6;border-radius:999px;overflow:hidden}.at-bar-chart .at-bar-fill{height:100%;background:#2d8cff;border-radius:999px}.at-bar-chart .at-bar-value{flex:0 0 48px;text-align:right}.at-donut-wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.at-donut{width:220px;height:220px;border-radius:50%;position:relative;box-shadow:0 0 0 1px rgba(0,0,0,.06) inset}.at-donut-hole{position:absolute;inset:22%;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center}.at-donut-pct{font-size:28px;font-weight:700;line-height:1}.at-donut-caption{font-size:12px;color:#555;margin-top:4px}.at-donut-legend{display:flex;flex-direction:column;gap:8px}.at-legend-item{display:flex;align-items:center;gap:8px}.at-legend-swatch{width:12px;height:12px;border-radius:3px;display:inline-block}</style>';
        echo '<script>(function(){document.addEventListener("click",function(e){var b=e.target.closest(".at-del"); if(!b) return; var msg=b.getAttribute("data-msg")||"Yakin?"; if(!confirm(msg)){ e.preventDefault(); }});})();</script>';
    }

    /* ================= Admin: Grafik Hasil ================= */
    public function page_charts(){
        global $wpdb;
        $t = $this->tables();

        // Kepala Unit boleh akses; admin juga.
        if(!current_user_can('manage_options') && !$this->is_kepala_unit() && !$this->is_data_processor()) return;

        echo '<div class="wrap">';
        echo '<h1>Grafik Hasil</h1>';

        $run_id = isset($_GET['run_id']) ? intval($_GET['run_id']) : 0;
        $latest_only = isset($_GET['latest_only']) ? intval($_GET['latest_only']) : 0;
        $fill_status_filter = sanitize_text_field($_GET['fill_status'] ?? 'completed');
        if(!in_array($fill_status_filter, array('completed','in_progress','all'), true)) $fill_status_filter = 'completed';
        $answer_display = $this->at_answer_display_mode();
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::SLUG.'_charts';

        // Untuk Kepala Unit: tampilkan run yang relevan dengan logika yang sama seperti Hasil Kepala Unit
        // 1) survey umum: run.unit_id = unit kepala unit
        // 2) survey pengguna tertentu: run.group_id memiliki minimal satu user dengan unit_id kepala unit
        $runs = array();
        if($this->can_access_all_runs()){
            $sql_runs = "SELECT r.id, r.year, r.survey_type, r.unit_id, r.group_id, s.title AS survey_title, u.name AS unit_name
                         FROM {$t->runs} r
                         LEFT JOIN {$t->surveys} s ON s.id = r.survey_id
                         LEFT JOIN {$t->units} u ON u.id = r.unit_id
                         ORDER BY r.id DESC LIMIT 500";
            $runs = $wpdb->get_results($sql_runs);
        } else {
            $unit_id = $this->get_unit_role_id_for_current_user();
            if($unit_id){
                $sql_runs = "SELECT r.id, r.year, r.survey_type, r.unit_id, r.group_id, s.title AS survey_title, u.name AS unit_name
                             FROM {$t->runs} r
                             LEFT JOIN {$t->surveys} s ON s.id = r.survey_id
                             LEFT JOIN {$t->units} u ON u.id = r.unit_id
                             WHERE (r.survey_type='general' AND r.unit_id=%d)
                                OR (r.survey_type<>'general' AND r.group_id IS NOT NULL AND EXISTS (
                                     SELECT 1 FROM {$t->users} uu WHERE uu.group_id=r.group_id AND uu.unit_id=%d
                                ))
                             ORDER BY r.id DESC LIMIT 500";
                $runs = $wpdb->get_results($wpdb->prepare($sql_runs, intval($unit_id), intval($unit_id)));
            }
        }

        echo '<style>
            .at-chart-filter{display:flex;flex-wrap:wrap;gap:14px 16px;align-items:flex-end;margin:12px 0 20px;max-width:1200px}
            .at-chart-filter .at-chart-field{display:flex;flex-direction:column;gap:6px;min-width:180px;flex:1 1 180px}
            .at-chart-filter .at-chart-field--run{flex:2 1 460px}
            .at-chart-filter .at-chart-field--latest{flex:1 1 260px}
            .at-chart-filter .at-chart-field--type{flex:0 1 180px}
            .at-chart-filter .at-chart-field label{margin:0;font-weight:600}
            .at-chart-filter select{width:100%;max-width:none}
            .at-chart-filter .at-chart-actions{display:flex;align-items:flex-end;flex:0 0 auto}
            .at-chart-filter .at-chart-actions .button{margin:0;height:auto;min-height:40px;padding:0 18px}
            @media (max-width: 900px){
                .at-chart-filter{display:block;max-width:none}
                .at-chart-filter .at-chart-field,.at-chart-filter .at-chart-actions{margin:0 0 12px}
            }
        </style>';
        echo '<form method="get" class="at-chart-filter">';
        echo '<input type="hidden" name="page" value="'.esc_attr($current_page).'" />';
        echo '<div class="at-chart-field at-chart-field--run">';
        echo '<label for="at-chart-run">Pilih Pelaksanaan (Run)</label>';
        echo '<select id="at-chart-run" name="run_id">';
        echo '<option value="0">-- pilih --</option>';
        foreach($runs as $r){
            $sel = ($run_id === intval($r->id)) ? 'selected' : '';
            $label = trim((string)$r->survey_title.' — '.(string)$r->unit_name.' / '.(string)$r->year);
            if($label === '— / ' || $label === '—  / ' || $label === '—  /' || $label === '— /') { $label = 'Run #'.intval($r->id); }
            if(isset($r->survey_type) && $r->survey_type !== ''){
                $label .= ' ('.($r->survey_type==='general' ? 'Umum' : 'Pengguna').')';
            }
            echo '<option value="'.intval($r->id).'" '.$sel.'>'.esc_html($label.' — #'.intval($r->id)).'</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="at-chart-field at-chart-field--latest">';
        echo '<label for="at-chart-latest">Tampilkan Data</label>';
        echo '<select id="at-chart-latest" name="latest_only">';
        echo '<option value="0" '.selected($latest_only,0,false).'>Semua isian survey</option>';
        echo '<option value="1" '.selected($latest_only,1,false).'>Hanya isian terakhir</option>';
        echo '</select>';
        echo '</div>';
        $chart_type = isset($_GET['chart']) ? sanitize_key(wp_unslash($_GET['chart'])) : 'bar';
        if(!in_array($chart_type, array('bar','pie','donut'), true)) $chart_type = 'bar';
        echo '<div class="at-chart-field at-chart-field--type">';
        echo '<label for="at-chart-type">Tipe Grafik</label>';
        echo '<select id="at-chart-type" name="chart">';
        foreach(array('bar'=>'Batang','pie'=>'Pie','donut'=>'Donat') as $k=>$v){
            $sel2 = ($chart_type===$k)?'selected':'';
            echo '<option value="'.esc_attr($k).'" '.$sel2.'>'.esc_html($v).'</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="at-chart-actions">';
        submit_button('Tampilkan','primary','',false);
        echo '</div>';
        echo '</form>';

        if(!$run_id){
            echo '<p>Pilih pelaksanaan (run) terlebih dahulu.</p>';
            echo '</div>';
            return;
        }

        // Validasi akses run untuk Kepala Unit:
        // - survey umum: run.unit_id harus sama
        // - survey pengguna tertentu: group run harus punya minimal satu user dari unit kepala unit
        if(!$this->can_access_all_runs()){
            $unit_id = $this->get_unit_role_id_for_current_user();
            $run_check = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                   FROM {$t->runs} r
                  WHERE r.id=%d AND (
                        (r.survey_type='general' AND r.unit_id=%d)
                     OR (r.survey_type<>'general' AND r.group_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM {$t->users} uu WHERE uu.group_id=r.group_id AND uu.unit_id=%d
                        ))
                  )",
                $run_id, intval($unit_id), intval($unit_id)
            ));
            if(!$run_check){
                echo '<div class="notice notice-error"><p>Anda tidak memiliki akses ke run ini.</p></div>';
                echo '</div>';
                return;
            }
        }

	        // NOTE: schema uses runs.survey_id (NOT runs.master_id) and runs table has no `title` column.
	        $run = $wpdb->get_row($wpdb->prepare(
	            "SELECT r.*, s.title AS survey_title, u.name AS unit_name
	               FROM {$t->runs} r
	               LEFT JOIN {$t->surveys} s ON s.id = r.survey_id
	               LEFT JOIN {$t->units}   u ON u.id = r.unit_id
	              WHERE r.id=%d",
	            $run_id
	        ));
        if(!$run){
            echo '<div class="notice notice-error"><p>Run tidak ditemukan.</p></div>';
            echo '</div>';
            return;
        }

	        $run_label = trim((string)$run->survey_title.' — '.(string)$run->unit_name.' / '.(string)$run->year);
	        $run_label = trim($run_label);
	        if($run_label === '' || $run_label === '— /' || $run_label === '— / ') $run_label = 'Run';
	        echo '<h2 style="margin-top:0;">'.esc_html($run_label.' — #'.intval($run->id)).'</h2>';

        $response_join = '';
        $response_where_extra = '';
        $response_params = array(intval($run_id));
        $latest_join = '';
        $latest_params = array();
        if(!$this->can_access_all_runs() && isset($run->survey_type) && $run->survey_type !== 'general'){
            $head_unit_id = $this->get_kepala_unit_unit_id();
            $response_join = " INNER JOIN {$t->users} fu ON fu.id = r.user_id ";
            $response_where_extra = ' AND fu.unit_id = %d ';
            $response_params[] = intval($head_unit_id);
        }
        if($latest_only && $this->is_run_group($run)){
            $latest_join = " INNER JOIN (SELECT MAX(id) AS max_id FROM {$t->responses} WHERE run_id=%d AND fill_status='completed' AND user_id IS NOT NULL AND user_id>0 GROUP BY user_id) lr ON lr.max_id = r.id ";
            $latest_params[] = intval($run_id);
        }

	        $questions = $wpdb->get_results($wpdb->prepare(
	            "SELECT id, question_text, qtype, options_csv FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",
	            intval($run->survey_id)
	        ));

        // CSS bar chart sederhana
        echo '<style>
            .at-simple-chart{margin:12px 0;}
            .at-bar-row{display:flex;gap:10px;align-items:center;margin:6px 0;}
            .at-bar-label{min-width:220px;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
            .at-bar-track{flex:1;background:#f0f0f1;border-radius:999px;height:10px;}
            .at-bar-fill{height:10px;border-radius:999px;background:#2271b1;}
            .at-bar-value{min-width:44px;text-align:right;font-variant-numeric:tabular-nums;}
        </style>';

        echo '<p class="description">'.(($this->is_run_group($run) && $latest_only) ? 'Mode grafik: hanya isian terakhir per pengguna.' : 'Mode grafik: semua isian survey yang terlihat.').'</p>';

        foreach($questions as $q){
            // Ensure question id is defined for queries below.
            $qid = isset($q->id) ? intval($q->id) : 0;
            if($q->qtype === 'label') continue;

            $opt_map = array();
            if(in_array($q->qtype, array('select','radio','checkbox'), true)){
                $opt_map = $this->at_parse_options_map(isset($q->options_csv)?$q->options_csv:'');
            }

            $items = array();

            if($q->qtype === 'checkbox'){
                $ans_sql = "SELECT a.answer_text AS answer
                     FROM {$t->answers} a
                     INNER JOIN {$t->responses} r ON r.id=a.response_id {$response_join} {$latest_join}
                     WHERE r.run_id=%d AND r.fill_status='completed' AND a.question_id=%d {$response_where_extra}";
                $ans_params = array_merge($latest_params, array(intval($run_id), intval($qid)), array_slice($response_params, 1));
                $ans_rows = $wpdb->get_results($wpdb->prepare($ans_sql, ...$ans_params));
                $counts = array();
                foreach($ans_rows as $ar){
                    $raw = isset($ar->answer) ? trim((string)$ar->answer) : '';
                    if($raw===''){
                        $counts['(kosong)'] = isset($counts['(kosong)']) ? ($counts['(kosong)']+1) : 1;
                        continue;
                    }
                    $parts = array_filter(array_map('trim', explode(',', $raw)), function($x){ return $x!==''; });
                    if(!$parts){
                        $counts['(kosong)'] = isset($counts['(kosong)']) ? ($counts['(kosong)']+1) : 1;
                        continue;
                    }
                    foreach($parts as $v){
                        $label = isset($opt_map[$v]) ? $opt_map[$v] : '';
                        $disp = ($label!=='') ? ($label.' ('. $v .')') : $v;
                        $counts[$disp] = isset($counts[$disp]) ? ($counts[$disp]+1) : 1;
                    }
                }
                arsort($counts);
                foreach($counts as $disp=>$cnt){
                    $items[] = array('label'=>$disp, 'count'=>intval($cnt));
                }
            } else {
                $rows_sql = "SELECT a.answer_text AS answer, COUNT(*) AS cnt
                     FROM {$t->answers} a
                     INNER JOIN {$t->responses} r ON r.id = a.response_id {$response_join} {$latest_join}
                     WHERE r.run_id=%d AND r.fill_status='completed' AND a.question_id=%d {$response_where_extra}
                     GROUP BY a.answer_text
                     ORDER BY cnt DESC";
                $rows_params = array_merge($latest_params, array(intval($run_id), intval($qid)), array_slice($response_params, 1));
                $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$rows_params));

                foreach($rows as $r){
                    $val = isset($r->answer) ? (string)$r->answer : '';
                    $val = trim($val);
                    if($val==='') $val='(kosong)';

                    $disp = $val;
                    if(($q->qtype==='select' || $q->qtype==='radio') && $val!=='(kosong)'){
                        $label = isset($opt_map[$val]) ? $opt_map[$val] : '';
                        if($label!=='') $disp = $label.' ('.$val.')';
                    }

                    $items[] = array('label'=>$disp, 'count'=>intval($r->cnt));
                }
            }

            echo '<div class="at-simple-chart" style="margin:12px 0;padding:12px;border:1px solid #e5e5e5;border-radius:10px;background:#fff;">';
            echo '<div style="font-weight:700;margin-bottom:6px;">'.esc_html($q->question_text).'</div>';

            if(!$items){
                echo '<div style="color:#666;">Belum ada jawaban.</div>';
                echo '</div>';
                continue;
            }

            $total = 0;
            foreach($items as $it){ $total += intval($it['count']); }

            if($chart_type==='bar'){
                echo '<div class="at-bar-chart">';
                foreach($items as $it){
                    $label = $it['label'];
                    $cnt = intval($it['count']);
                    $w = $total>0 ? round(($cnt/$total)*100,2) : 0;
                    $bar_tip = $label.' — '.number_format_i18n($w, 2).'% ('.$cnt.' isian)';
                    echo '<div class="at-bar-row" title="'.esc_attr($bar_tip).'">';
                    echo '<div class="at-bar-label">'.esc_html($label).'</div>';
                    echo '<div class="at-bar-track"><div class="at-bar-fill" style="width:'.$w.'%" title="'.esc_attr($bar_tip).'"></div></div>';
                    echo '<div class="at-bar-value">'.number_format_i18n($w, 2).'% • '.$cnt.'</div>';
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo $this->at_render_pie_donut_chart($items, $chart_type);
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /* ================= Admin: Units ================= */
    public function page_units(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();
        if (isset($_POST['at_action']) && $_POST['at_action']==='save_unit' && check_admin_referer('at_save_unit')){
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            if($name!==''){ $db->insert($t->units, array('name'=>$name)); echo '<div class="updated"><p>Unit disimpan.</p></div>'; }
        }
        if (isset($_POST['at_action']) && $_POST['at_action']==='update_unit' && check_admin_referer('at_update_unit')){
            $id = intval($_POST['id'] ?? 0);
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            if($id && $name!==''){ $db->update($t->units, array('name'=>$name), array('id'=>$id)); echo '<div class="updated"><p>Unit diperbarui.</p></div>'; }
        }
        if (isset($_POST['at_action']) && $_POST['at_action']==='delete_unit' && check_admin_referer('at_delete_unit')){
            $id = intval($_POST['id'] ?? 0);
            if($id){
                $used = intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->runs} WHERE unit_id=%d",$id)));
                $used += intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->responses} WHERE unit_id=%d",$id)));
                if($used>0){ echo '<div class="error"><p>Tidak dapat menghapus Unit karena sudah digunakan. Hapus dulu pelaksanaan/hasil terkait.</p></div>'; }
                else { $db->delete($t->units, array('id'=>$id)); echo '<div class="updated"><p>Unit dihapus.</p></div>'; }
            }
        }

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_row = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->units} WHERE id=%d",$edit_id)) : null;

        $units=$db->get_results("SELECT * FROM {$t->units} ORDER BY id DESC");
        echo '<div class="wrap"><h1>Unit Kerja</h1>';

        if($edit_row){
            echo '<h2>Edit Unit</h2><form method="post">'; wp_nonce_field('at_update_unit');
            echo '<input type="hidden" name="at_action" value="update_unit"/><input type="hidden" name="id" value="'.intval($edit_row->id).'"/>';
            echo '<p><input type="text" name="name" class="regular-text" value="'.esc_attr($edit_row->name).'" required/> ';
            echo '<button class="button button-primary">Simpan Perubahan</button> ';
            echo '<a class="button" href="'.admin_url('admin.php?page='.self::SLUG).'">Batal</a></p></form><hr/>';
        }else{
            echo '<h2>Tambah Unit</h2><form method="post">'; wp_nonce_field('at_save_unit');
            echo '<input type="hidden" name="at_action" value="save_unit"/>';
            echo '<p><input type="text" name="name" class="regular-text" placeholder="Nama Unit" required/> ';
            echo '<button class="button button-primary">Tambah</button></p></form><hr/>';
        }

        echo '<h2>Daftar Unit</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Nama</th></tr></thead><tbody>';
        if($units){
            foreach($units as $u){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'&edit='.$u->id);
                echo '<tr><td>'.intval($u->id).'</td><td>'.$this->esc($u->name).'</td><td>';
                echo '<a class="button-link" href="'.$edit_url.'">Edit</a> | ';
                echo '<form method="post" style="display:inline;">'; wp_nonce_field('at_delete_unit');
                echo '<input type="hidden" name="at_action" value="delete_unit"/><input type="hidden" name="id" value="'.intval($u->id).'"/>';
                echo '<button class="button-link-delete at-del" data-msg="'.esc_attr__('Hapus unit ini?','akurasitara').'" style="color:#b00;">Hapus</button>';
                echo '</form>';
                echo '</td></tr>';
            }
        }else{
            echo '<tr><td colspan="3"><em>Belum ada unit.</em></td></tr>';
        }
        echo '</tbody></table></div>';
    }


    /* ================= Admin: Set Kepala Unit ================= */
    public function page_unit_heads(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        // Save (assign/update)
        if(isset($_POST['at_action']) && $_POST['at_action']==='save_unit_head' && check_admin_referer('at_save_unit_head')){
            $unit_id = intval($_POST['unit_id'] ?? 0);
            $wp_user_id = intval($_POST['wp_user_id'] ?? 0);
            if($unit_id && $wp_user_id){
                $exists = $db->get_var($db->prepare("SELECT id FROM {$t->unit_heads} WHERE wp_user_id=%d",$wp_user_id));
                $data = array('unit_id'=>$unit_id,'wp_user_id'=>$wp_user_id);
                if($exists){
                    $db->update($t->unit_heads, $data, array('id'=>intval($exists)));
                    echo '<div class="updated"><p>Kepala unit diperbarui.</p></div>';
                } else {
                    $db->insert($t->unit_heads, $data);
                    echo '<div class="updated"><p>Kepala unit ditetapkan.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Unit dan pengguna WP wajib diisi.</p></div>';
            }
        }

        // Delete mapping
        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_del_unit_head')){
            $id=intval($_GET['delete']);
            if($id){
                $db->delete($t->unit_heads, array('id'=>$id));
                echo '<div class="updated"><p>Mapping kepala unit dihapus.</p></div>';
            }
        }

        $units = $db->get_results("SELECT * FROM {$t->units} ORDER BY name ASC");
        $wp_users = get_users(array('number'=>200,'orderby'=>'display_name','order'=>'ASC'));

        $rows = $db->get_results("SELECT uh.*, u.name AS unit_name FROM {$t->unit_heads} uh JOIN {$t->units} u ON u.id=uh.unit_id ORDER BY u.name ASC");

        echo '<div class="wrap"><h1>Set Kepala Unit</h1>';
        echo '<p class="description">Pilih akun WordPress yang bertindak sebagai Kepala Unit untuk masing-masing Unit Kerja.</p>';

        echo '<h2>Tambah / Ubah Kepala Unit</h2><form method="post">';
        wp_nonce_field('at_save_unit_head');
        echo '<input type="hidden" name="at_action" value="save_unit_head"/>';
        echo '<table class="form-table">';
        echo '<tr><th>Unit Kerja</th><td><select name="unit_id" required><option value="">-- pilih unit --</option>';
        foreach($units as $u){ echo '<option value="'.intval($u->id).'">'.$this->esc($u->name).'</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th>Akun WordPress</th><td><select name="wp_user_id" required><option value="">-- pilih user WP --</option>';
        foreach($wp_users as $wu){
            $label = $wu->display_name ? $wu->display_name : $wu->user_login;
            echo '<option value="'.intval($wu->ID).'">'.esc_html($label.' ('.$wu->user_login.')').'</option>';
        }
        echo '</select></td></tr>';
        echo '</table><p><button class="button button-primary">Simpan</button></p></form><hr/>';

        echo '<h2>Daftar Kepala Unit</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Unit</th><th>User WP</th><th>Login</th></tr></thead><tbody>';
        if($rows){
            foreach($rows as $r){
                $wu = get_user_by('id', intval($r->wp_user_id));
                $del = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_unit_heads&delete='.intval($r->id)),'at_del_unit_head');
                echo '<tr><td>'.$this->esc($r->unit_name).'</td><td>'.esc_html($wu ? $wu->display_name : '-').'</td><td>'.esc_html($wu ? $wu->user_login : '-').'</td>';
                echo '<td><a class="button at-del" href="'.$del.'">Hapus</a></td></tr>';
            }
        } else {
            echo '<tr><td colspan="4"><em>Belum ada kepala unit ditetapkan.</em></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ================= Admin: Set Pengolah Data ================= */
    public function page_data_processors(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        if(isset($_POST['at_action']) && $_POST['at_action']==='save_data_processor' && check_admin_referer('at_save_data_processor')){
            $unit_id = intval($_POST['unit_id'] ?? 0);
            $wp_user_id = intval($_POST['wp_user_id'] ?? 0);
            if($unit_id && $wp_user_id){
                $exists = $db->get_var($db->prepare("SELECT id FROM {$t->data_processors} WHERE wp_user_id=%d",$wp_user_id));
                $data = array('unit_id'=>$unit_id,'wp_user_id'=>$wp_user_id);
                if($exists){
                    $db->update($t->data_processors, $data, array('id'=>intval($exists)));
                    echo '<div class="updated"><p>Pengolah data diperbarui.</p></div>';
                } else {
                    $db->insert($t->data_processors, $data);
                    echo '<div class="updated"><p>Pengolah data ditetapkan.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Unit dan pengguna WP wajib diisi.</p></div>';
            }
        }

        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_del_data_processor')){
            $id=intval($_GET['delete']);
            if($id){
                $db->delete($t->data_processors, array('id'=>$id));
                echo '<div class="updated"><p>Mapping pengolah data dihapus.</p></div>';
            }
        }

        $units = $db->get_results("SELECT * FROM {$t->units} ORDER BY name ASC");
        $wp_users = get_users(array('number'=>200,'orderby'=>'display_name','order'=>'ASC'));
        $rows = $db->get_results("SELECT dp.*, u.name AS unit_name FROM {$t->data_processors} dp JOIN {$t->units} u ON u.id=dp.unit_id ORDER BY u.name ASC");

        echo '<div class="wrap"><h1>Set Pengolah Data</h1>';
        echo '<p class="description">Pilih akun WordPress yang bertindak sebagai Pengolah Data untuk masing-masing Unit Kerja. Pengolah Data dapat melihat monitoring/hasil unitnya serta mengedit atau menghapus hasil pengisian survey pada unitnya sendiri.</p>';

        echo '<h2>Tambah / Ubah Pengolah Data</h2><form method="post">';
        wp_nonce_field('at_save_data_processor');
        echo '<input type="hidden" name="at_action" value="save_data_processor"/>';
        echo '<table class="form-table">';
        echo '<tr><th>Unit Kerja</th><td><select name="unit_id" required><option value="">-- pilih unit --</option>';
        foreach($units as $u){ echo '<option value="'.intval($u->id).'">'.$this->esc($u->name).'</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th>Akun WordPress</th><td><select name="wp_user_id" required><option value="">-- pilih user WP --</option>';
        foreach($wp_users as $wu){
            $label = $wu->display_name ? $wu->display_name : $wu->user_login;
            echo '<option value="'.intval($wu->ID).'">'.esc_html($label.' ('.$wu->user_login.')').'</option>';
        }
        echo '</select></td></tr>';
        echo '</table><p><button class="button button-primary">Simpan</button></p></form><hr/>';

        echo '<h2>Daftar Pengolah Data</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Unit</th><th>User WP</th><th>Login</th><th>Aksi</th></tr></thead><tbody>';
        if($rows){
            foreach($rows as $r){
                $wu = get_user_by('id', intval($r->wp_user_id));
                $del = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_data_processors&delete='.intval($r->id)),'at_del_data_processor');
                echo '<tr><td>'.$this->esc($r->unit_name).'</td><td>'.esc_html($wu ? $wu->display_name : '-').'</td><td>'.esc_html($wu ? $wu->user_login : '-').'</td>';
                echo '<td><a class="button at-del" href="'.$del.'" data-msg="Hapus mapping pengolah data ini?">Hapus</a></td></tr>';
            }
        } else {
            echo '<tr><td colspan="4"><em>Belum ada pengolah data ditetapkan.</em></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ================= Kepala Unit: Hasil Terfilter ================= */
    public function page_head_results(){
        if(!current_user_can('read')) return;

        $db=$this->db(); $t=$this->tables();
        $head_unit_id = $this->get_head_unit_id();
        $processor_unit_id = $this->get_processor_unit_id();
        $role_unit_id = $head_unit_id ? $head_unit_id : $processor_unit_id;

        // Admin boleh akses tanpa mapping (pilih unit), kepala unit/pengolah data wajib mapping
        $is_admin = current_user_can('manage_options');

        if(!$role_unit_id && !$is_admin){
            echo '<div class="wrap"><h1>Hasil Kepala Unit</h1><p><em>Akun Anda belum ditetapkan sebagai Kepala Unit atau Pengolah Data.</em></p></div>';
            return;
        }

        $unit_id = $is_admin ? intval($_GET['unit_id'] ?? $role_unit_id) : $role_unit_id;
        if(!$unit_id) $unit_id = $role_unit_id;
        $can_manage_unit_results = $is_admin || ($processor_unit_id && intval($processor_unit_id)===intval($unit_id));

        $run_id = intval($_GET['run_id'] ?? 0);
        $latest_only = intval($_GET['latest_only'] ?? 0);
        $fill_status_filter = sanitize_text_field($_GET['fill_status'] ?? 'completed');
        if(!in_array($fill_status_filter, array('completed','in_progress','all'), true)) $fill_status_filter = 'completed';
        $answer_display = $this->at_answer_display_mode();

		// Export CSV diproses di admin_init (handle_downloads) agar header CSV tidak tercampur HTML admin.

        echo '<div class="wrap"><h1>Hasil Kepala Unit</h1>';

        if($is_admin){
            $units = $db->get_results("SELECT * FROM {$t->units} ORDER BY name ASC");
            echo '<form method="get" style="margin-bottom:10px;"><input type="hidden" name="page" value="'.self::SLUG.'_head_results">';
            echo '<label>Unit: <select name="unit_id" onchange="this.form.submit()"><option value="0">-- pilih --</option>';
            foreach($units as $u){
                echo '<option value="'.intval($u->id).'" '.selected($unit_id,$u->id,false).'>'.$this->esc($u->name).'</option>';
            }
            echo '</select></label> <button class="button">Tampilkan</button></form>';
        } else {
            $unit_name = $db->get_var($db->prepare("SELECT name FROM {$t->units} WHERE id=%d",$unit_id));
            echo '<p><strong>Unit:</strong> '.$this->esc($unit_name).'</p>';
        }

        // Runs visible for unit ini:
// 1) Survey umum: r.unit_id == unit yang dipilih (unit kepala unit / unit pilihan admin)
// 2) Survey pengguna tertentu: tampilkan bila di dalam group_id terdapat pengguna dengan unit_id == unit ini
$runs = $db->get_results($db->prepare("
    SELECT r.id, r.run_name, r.year, r.survey_type, r.unit_id, r.group_id, s.title AS survey_title, u.name AS unit_name
    FROM {$t->runs} r
    JOIN {$t->surveys} s ON s.id=r.survey_id
    JOIN {$t->units} u ON u.id=r.unit_id
    WHERE (r.survey_type='general' AND r.unit_id=%d)
       OR (r.survey_type<>'general' AND r.group_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM {$t->users} uu WHERE uu.group_id=r.group_id AND uu.unit_id=%d
       ))
    ORDER BY r.id DESC
", $unit_id, $unit_id));

        echo '<form method="get" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="'.self::SLUG.'_head_results">';
        if($is_admin){ echo '<input type="hidden" name="unit_id" value="'.intval($unit_id).'">'; }
        echo '<label>Pilih Pelaksanaan (Run): <select name="run_id" onchange="this.form.submit()"><option value="0">-- pilih --</option>';
        foreach($runs as $r){
            $tag = ($r->survey_type==='general') ? 'Umum' : 'Pengguna';
            $label = ($r->run_name ? $r->run_name.' — ' : '').$r->survey_title.' — '.$r->unit_name.' / '.$r->year.' ('.$tag.')';
            echo '<option value="'.intval($r->id).'" '.selected($run_id,$r->id,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        echo '<label>Tampilkan Data: <select name="latest_only">';
        echo '<option value="0" '.selected($latest_only,0,false).'>Semua isian</option>';
        echo '<option value="1" '.selected($latest_only,1,false).'>Isian terakhir per pengguna</option>';
        echo '</select></label>';
        echo '<label>Status Pengisian: <select name="fill_status">';
        echo '<option value="completed" '.selected($fill_status_filter,'completed',false).'>Completed</option>';
        echo '<option value="in_progress" '.selected($fill_status_filter,'in_progress',false).'>In Progress</option>';
        echo '<option value="all" '.selected($fill_status_filter,'all',false).'>Semua Status</option>';
        echo '</select></label>';
        echo '<label>Tampilan Jawaban Pilihan: <select name="answer_display">';
        echo '<option value="value" '.selected($answer_display,'value',false).'>Sesuai nilai hasil</option>';
        echo '<option value="label" '.selected($answer_display,'label',false).'>Sesuai label opsi survey</option>';
        echo '</select></label>';
        echo ' <button class="button">Tampilkan</button></form>';

        // Tombol Export CSV muncul setelah run dipilih
        if($run_id){
            $export_url = add_query_arg(array(
                'page' => self::SLUG.'_head_results',
                'run_id' => $run_id,
                'latest_only' => $latest_only,
                'fill_status' => $fill_status_filter,
                'answer_display' => $answer_display,
                'export_csv' => 1,
            ), admin_url('admin.php'));
            if($is_admin){
                $export_url = add_query_arg('unit_id', intval($unit_id), $export_url);
            }
            $export_url = wp_nonce_url($export_url, 'at_export_head_csv_'.$run_id);
            echo '<p style="margin:10px 0;"><a class="button button-primary" href="'.esc_url($export_url).'">Export CSV</a></p>';
        }

        if(!$run_id){ echo '</div>'; return; }

        $run = $db->get_row($db->prepare("SELECT r.*, s.title AS survey_title FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id WHERE r.id=%d",$run_id));
        if(!$run){ echo '<p><em>Run tidak ditemukan.</em></p></div>'; return; }

        // Questions
        $qs = $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",$run->survey_id));

        // Handle edit/delete khusus admin dan Pengolah Data unit terkait. Kepala Unit biasa hanya melihat.
        if($can_manage_unit_results && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['at_head_results_action'])){
            check_admin_referer('at_manage_head_results_'.$run_id);
            $act = sanitize_text_field($_POST['at_head_results_action']);
            $deleted = 0;
            $ids = array();
            if($act==='delete_one'){ $ids[] = intval($_POST['response_id'] ?? 0); }
            if($act==='delete_selected'){ $ids = array_map('intval', (array)($_POST['resp_ids'] ?? array())); }
            $ids = array_values(array_filter($ids));
            foreach($ids as $rid){
                if($this->response_belongs_to_unit($rid, $unit_id)){
                    $db->query($db->prepare("DELETE FROM {$t->answers} WHERE response_id=%d", $rid));
                    $deleted += (int)$db->query($db->prepare("DELETE FROM {$t->responses} WHERE id=%d AND run_id=%d", $rid, $run_id));
                }
            }
            if($deleted>0) echo '<div class="notice notice-success"><p>Berhasil menghapus '.intval($deleted).' data hasil survey.</p></div>';
            else echo '<div class="notice notice-warning"><p>Tidak ada data yang dihapus atau data berada di luar unit Anda.</p></div>';
        }

        // Ambil responses:
        // - Survey umum: tampilkan response pada run unit ini
        // - Survey pengguna tertentu: hanya responses dari pengguna yang unit_id-nya sama dengan unit yang boleh diakses
        $status_sql = ($fill_status_filter==='all') ? '1=1' : $db->prepare('r.fill_status=%s', $fill_status_filter);
        if($run->survey_type === 'general'){
            $responses = $db->get_results($db->prepare("
                SELECT r.*
                FROM {$t->responses} r
                WHERE r.run_id=%d AND {$status_sql}
                ORDER BY r.id ASC
            ", $run_id));
        } else {
            if($latest_only){
                $status_sql_inner = ($fill_status_filter==='all') ? '1=1' : $db->prepare('r.fill_status=%s', $fill_status_filter);
                $responses = $db->get_results($db->prepare("
                    SELECT r1.*
                    FROM {$t->responses} r1
                    JOIN {$t->users} u ON u.id = r1.user_id
                    INNER JOIN (
                        SELECT r.user_id, MAX(r.id) AS max_id
                        FROM {$t->responses} r
                        JOIN {$t->users} uu ON uu.id = r.user_id
                        WHERE r.run_id=%d AND {$status_sql_inner} AND uu.unit_id=%d AND r.user_id IS NOT NULL AND r.user_id > 0
                        GROUP BY r.user_id
                    ) x ON x.max_id = r1.id
                    WHERE u.unit_id=%d
                    ORDER BY r1.id ASC
                ", $run_id, $unit_id, $unit_id));
            } else {
                $responses = $db->get_results($db->prepare("
                    SELECT r.*
                    FROM {$t->responses} r
                    JOIN {$t->users} u ON u.id = r.user_id
                    WHERE r.run_id=%d AND {$status_sql} AND u.unit_id=%d
                    ORDER BY r.id ASC
                ", $run_id, $unit_id));
            }
        }

        echo '<h2>'.($run->run_name ? $this->esc($run->run_name).' — ' : '').$this->esc($run->survey_title).' / '.$run->year.'</h2>';
        if($run->survey_type === 'general'){
            echo '<p class="description">Catatan: survey umum dapat berisi respon anonim, sehingga identitas tidak ditampilkan.</p>';
        } else {
            echo '<p class="description">Catatan: hanya respon yang berasal dari pengguna (memiliki username) pada unit ini yang ditampilkan.</p>';
        }
        echo '<p class="description">'.(($run->survey_type !== 'general' && $latest_only) ? 'Mode tampilan: hanya isian terakhir per pengguna pada unit ini.' : 'Mode tampilan: semua isian yang terlihat untuk unit ini.').'</p>';

        if(!$responses){ echo '<p><em>Belum ada respon dari unit ini.</em></p></div>'; return; }

        $id_cols = $this->get_result_identity_elements_for_run($run); // group-only; returns [] if not applicable
        if($can_manage_unit_results){
            echo '<form method="post" onsubmit="return confirm(this.dataset.confirmMsg || \'Lanjutkan?\');" data-confirm-msg="Lanjutkan proses?">';
            wp_nonce_field('at_manage_head_results_'.$run_id);
            echo '<input type="hidden" name="at_head_results_action" value="" />';
            echo '<p style="margin:10px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;"><button type="submit" class="button" onclick="this.form.at_head_results_action.value=\'delete_selected\';this.form.dataset.confirmMsg=\'Hapus data yang dipilih?\'">Hapus Terpilih</button><span class="description">Pengolah Data hanya dapat menghapus data pada unitnya sendiri.</span></p>';
        }
        echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr>';
        if($can_manage_unit_results){ echo '<th style="width:40px;"><input type="checkbox" onclick="document.querySelectorAll(\'input[name=&quot;resp_ids[]&quot;]\').forEach(function(cb){cb.checked=this.checked;}.bind(this));" /></th>'; }
        echo '<th>Response ID</th><th>Status Pengisian</th><th>Waktu Mulai/Submit</th><th>Waktu Completed</th><th>Username</th>';
        if($run->survey_type!=='general' && $id_cols){
            foreach($id_cols as $el){ echo '<th>'.$this->esc($el->field_label).'</th>'; }
        }
        foreach($qs as $q){ echo '<th>'.$this->esc($q->question_text).'</th>'; }
        if($can_manage_unit_results){ echo '<th style="min-width:140px;">Aksi</th>'; }
        echo '</tr></thead><tbody>';

        foreach($responses as $resp){
            $uname = $db->get_var($db->prepare("SELECT username FROM {$t->users} WHERE id=%d", intval($resp->user_id)));
            echo '<tr>';
            if($can_manage_unit_results){ echo '<td><input type="checkbox" name="resp_ids[]" value="'.intval($resp->id).'" /></td>'; }
            echo '<td>'.intval($resp->id).'</td><td>'.$this->esc($resp->fill_status).'</td><td>'.$this->esc($resp->submitted_at).'</td><td>'.$this->esc($resp->completed_at).'</td><td>'.$this->esc($uname).'</td>';
            if($run->survey_type!=='general' && $id_cols){
                foreach($id_cols as $el){
                    $v = $db->get_var($db->prepare("SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", intval($resp->user_id), intval($el->id)));
                    echo '<td>'.$this->esc($v).'</td>';
                }
            }
            foreach($qs as $q){
                $ans_row = $db->get_row($db->prepare("SELECT answer_text, file_url, file_name FROM {$t->answers} WHERE response_id=%d AND question_id=%d ORDER BY id DESC LIMIT 1",$resp->id,$q->id));
                $ans = $ans_row ? (string)$ans_row->answer_text : '';
                $cell = $this->esc($this->at_format_choice_answer($ans, $q, $answer_display));
                if($ans_row && !empty($ans_row->file_url)){
                    $fn = !empty($ans_row->file_name) ? $ans_row->file_name : basename($ans_row->file_url);
                    $cell .= '<br><a href="'.esc_url($ans_row->file_url).'" target="_blank" rel="noopener">Buka bukti</a>';
                }
                echo '<td>'.$cell.'</td>';
            }
            if($can_manage_unit_results){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_results_edit&run_id='.$run_id.'&response_id='.intval($resp->id).'&from=head_results&unit_id='.intval($unit_id));
                echo '<td><a class="button button-small" href="'.esc_url($edit_url).'">Edit</a> ';
                echo '<button type="submit" class="button button-small button-link-delete" name="response_id" value="'.intval($resp->id).'" onclick="this.form.at_head_results_action.value=\'delete_one\';this.form.dataset.confirmMsg=\'Hapus data response ini?\'">Hapus</button>';
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if($can_manage_unit_results){ echo '</form>'; }
        echo '</div>';
    }

    /** Export CSV untuk halaman Hasil Kepala Unit (data sudah terfilter sesuai unit). */
    private function export_head_results_csv($unit_id, $run_id, $latest_only = 0, $answer_display = 'value', $fill_status_filter = 'completed'){
        $db = $this->db();
        $t  = $this->tables();

        $run = $db->get_row($db->prepare(
            "SELECT r.*, s.title AS survey_title FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id WHERE r.id=%d",
            $run_id
        ));
        if(!$run){
            wp_die('Run tidak ditemukan.');
        }

        $qs = $db->get_results($db->prepare(
            "SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",
            $run->survey_id
        ));

        if($run->survey_type === 'general'){
            $responses = $db->get_results($db->prepare(
                "SELECT r.* FROM {$t->responses} r WHERE r.run_id=%d AND ".($fill_status_filter==='all' ? '1=1' : $db->prepare('r.fill_status=%s', $fill_status_filter))." ORDER BY r.id ASC",
                $run_id
            ));
        } else {
            if($latest_only){
                $responses = $db->get_results($db->prepare(
                    "SELECT r1.*
                     FROM {$t->responses} r1
                     JOIN {$t->users} u ON u.id=r1.user_id
                     INNER JOIN (
                         SELECT r.user_id, MAX(r.id) AS max_id
                         FROM {$t->responses} r
                         JOIN {$t->users} uu ON uu.id=r.user_id
                         WHERE r.run_id=%d AND ".($fill_status_filter==='all' ? '1=1' : $db->prepare('r.fill_status=%s', $fill_status_filter))." AND uu.unit_id=%d AND r.user_id IS NOT NULL AND r.user_id > 0
                         GROUP BY r.user_id
                     ) x ON x.max_id = r1.id
                     WHERE u.unit_id=%d
                     ORDER BY r1.id ASC",
                    $run_id, $unit_id, $unit_id
                ));
            } else {
                $responses = $db->get_results($db->prepare(
                    "SELECT r.* FROM {$t->responses} r JOIN {$t->users} u ON u.id=r.user_id WHERE r.run_id=%d AND ".($fill_status_filter==='all' ? '1=1' : $db->prepare('r.fill_status=%s', $fill_status_filter))." AND u.unit_id=%d ORDER BY r.id ASC",
                    $run_id, $unit_id
                ));
            }
        }

        $id_cols = ($run->survey_type === 'general') ? array() : $this->get_result_identity_elements_for_run($run);

        // Headers
        $safe_title = sanitize_title(($run->run_name ? $run->run_name.'-' : '').$run->survey_title);
        $filename = 'akurasitara_'.$safe_title.'_run_'.$run_id.'_unit_'.$unit_id.'_'.gmdate('Ymd_His').'.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');
        if($out === false){
            wp_die('Gagal membuat output CSV.');
        }

        // UTF-8 BOM agar Excel Indonesia kebaca rapi
        fwrite($out, "\xEF\xBB\xBF");

        $header = array('Response ID', 'Waktu', 'Username');
        if($run->survey_type !== 'general' && $id_cols){
            foreach($id_cols as $el){
                $header[] = $el->field_label;
            }
        }
        foreach($qs as $q){
            $header[] = $q->question_text;
        }
        fputcsv($out, $header);

        if($responses){
            foreach($responses as $resp){
                $uname = '';
                if(!empty($resp->user_id)){
                    $uname = (string) $db->get_var($db->prepare("SELECT username FROM {$t->users} WHERE id=%d", intval($resp->user_id)));
                }
                $row = array(intval($resp->id), (string)$resp->submitted_at, $uname);

                if($run->survey_type !== 'general' && $id_cols){
                    foreach($id_cols as $el){
                        $v = $db->get_var($db->prepare(
                            "SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d",
                            intval($resp->user_id), intval($el->id)
                        ));
                        $row[] = (string)$v;
                    }
                }
                foreach($qs as $q){
                    $ans = $db->get_var($db->prepare(
                        "SELECT answer_text FROM {$t->answers} WHERE response_id=%d AND question_id=%d",
                        intval($resp->id), intval($q->id)
                    ));
                    $row[] = (string)$this->at_format_choice_answer($ans, $q, $answer_display);
                }
                fputcsv($out, $row);
            }
        }

        fclose($out);
        exit;
    }


    /* ================= Admin: Surveys ================= */
    public function page_surveys(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        if (isset($_POST['at_action']) && $_POST['at_action']==='save_survey' && check_admin_referer('at_save_survey')){
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
            if($title!==''){ $db->insert($t->surveys, array('title'=>$title,'description'=>$description)); echo '<div class="updated"><p>Survey disimpan.</p></div>'; }
        }
        if (isset($_POST['at_action']) && $_POST['at_action']==='update_survey' && check_admin_referer('at_update_survey')){
            $id = intval($_POST['id'] ?? 0);
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
            if($id && $title!==''){ $db->update($t->surveys, array('title'=>$title,'description'=>$description), array('id'=>$id)); echo '<div class="updated"><p>Survey diperbarui.</p></div>'; }
        }
        if (isset($_POST['at_action']) && $_POST['at_action']==='delete_survey' && check_admin_referer('at_delete_survey')){
            $id = intval($_POST['id'] ?? 0);
            if($id){
                $used = intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->runs} WHERE survey_id=%d",$id)));
                $used += intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->questions} WHERE survey_id=%d",$id)));
                $used += intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->responses} WHERE survey_id=%d",$id)));
                if($used>0){ echo '<div class="error"><p>Tidak dapat menghapus Survey karena sudah digunakan. Hapus dulu pelaksanaan/hasil terkait.</p></div>'; }
                else { $db->delete($t->surveys, array('id'=>$id)); echo '<div class="updated"><p>Survey dihapus.</p></div>'; }
            }
        }

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_row = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->surveys} WHERE id=%d",$edit_id)) : null;
        $surveys=$db->get_results("SELECT * FROM {$t->surveys} ORDER BY id DESC");

        echo '<div class="wrap"><h1>Master Survey</h1>';

        if($edit_row){
            echo '<h2>Edit Survey</h2><form method="post">'; wp_nonce_field('at_update_survey');
            echo '<input type="hidden" name="at_action" value="update_survey"/><input type="hidden" name="id" value="'.intval($edit_row->id).'"/>';
            echo '<table class="form-table">
                <tr><th>Judul</th><td><input type="text" name="title" class="regular-text" value="'.esc_attr($edit_row->title).'" required></td></tr>
                <tr><th>Deskripsi</th><td><textarea name="description" class="large-text" rows="3">'.esc_textarea((string)$edit_row->description).'</textarea></td></tr>
            </table>
            <p><button class="button button-primary">Simpan Perubahan</button> <a class="button" href="'.admin_url('admin.php?page='.self::SLUG.'_surveys').'">Batal</a></p></form><hr/>';
        }else{
            echo '<h2>Buat Survey</h2><form method="post">'; wp_nonce_field('at_save_survey');
            echo '<input type="hidden" name="at_action" value="save_survey"/>
            <table class="form-table">
                <tr><th>Judul</th><td><input type="text" name="title" class="regular-text" required></td></tr>
                <tr><th>Deskripsi</th><td><textarea name="description" class="large-text" rows="3"></textarea></td></tr>
            </table>
            <p><button class="button button-primary">Simpan</button></p></form><hr/>';
        }

        echo '<h2>Daftar Survey</h2>';
        if($surveys){
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Judul</th><th>Deskripsi</th></tr></thead><tbody>';
            foreach($surveys as $s){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_surveys&edit='.$s->id);
                echo '<tr><td>'.intval($s->id).'</td><td>'.$this->esc($s->title).'</td><td>'.$this->esc($s->description).'</td><td>';
                echo '<a class="button-link" href="'.$edit_url.'">Edit</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>Belum ada survey.</em></p>';
        }

        // UI helper: show "Gunakan Lainnya" only for radio/select
        $at_js = <<<'ATJS'
<script>(function(){
            function toggleNew(){
                var sel = document.getElementById("at_qtype_new");
                var row = document.getElementById("at_allow_other_row_new");
                if(!sel || !row) return;
                var v = sel.value||"";
                row.style.display = (v==="radio" || v==="select" || v==="checkbox") ? "" : "none";
                if(row.style.display==="none"){
                    var cb = row.querySelector("input[type=checkbox]");
                    if(cb) cb.checked = false;
                }
            }
            function toggleEdit(sel){
                if(!sel) return;
                var qid = sel.getAttribute("data-qid");
                var row = document.querySelector(".at_allow_other_row_edit[data-qid=\""+qid+"\"]");
                if(!row) return;
                var v = sel.value||"";
                row.style.display = (v==="radio" || v==="select" || v==="checkbox") ? "" : "none";
                if(row.style.display==="none"){
                    var cb = row.querySelector("input[type=checkbox]");
                    if(cb) cb.checked = false;
                }
            }
            document.addEventListener('DOMContentLoaded', function(){
                toggleNew();
                var selNew = document.getElementById("at_qtype_new");
                if(selNew) selNew.addEventListener('change', toggleNew);

                document.querySelectorAll('select.at_qtype_edit').forEach(function(s){
                    toggleEdit(s);
                    s.addEventListener('change', function(){ toggleEdit(s); });
                });
            });
        })();</script>
ATJS;
        echo $at_js;

        echo '</div>';
    }

    /* ================= Admin: Questions (separate) ================= */
    public function page_questions(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();
        $sel_survey = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : 0;

        // Import CSV
        if (isset($_POST['at_action']) && $_POST['at_action']==='import_csv' && check_admin_referer('at_import_questions')){
            $survey_id = intval($_POST['survey_id'] ?? 0);
            if($survey_id && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])){
                $fh = fopen($_FILES['csv']['tmp_name'], 'r');
                // Detect CSV delimiter (Excel often uses semicolon ';' depending on locale)
                $delimiter = ',';
                $probe = fgets($fh);
                if ($probe !== false) {
                    $commaCount = substr_count($probe, ',');
                    $semiCount  = substr_count($probe, ';');
                    if ($semiCount > $commaCount) { $delimiter = ';'; }
                    rewind($fh);
                }
                $row=0; $added=0;
                while(($data = fgetcsv($fh, 0, $delimiter)) !== false){
                    $row++;
                    if($row==1 && (stripos(implode('',$data),'question_text')!==false)) { continue; } // header
                    $question_text = isset($data[0]) ? sanitize_text_field($data[0]) : '';
                    $qtype         = isset($data[1]) ? sanitize_text_field($data[1]) : 'text';
                    $allowed_qtypes = array('text','textarea','number','date','radio','select','checkbox','label');
                    if(!in_array($qtype, $allowed_qtypes, true)){
                        $qtype = 'text';
                    }
                    $options_csv   = isset($data[2]) ? sanitize_text_field($data[2]) : '';
                    $is_required   = isset($data[3]) ? intval($data[3]) : 0;
                    $sort_order    = isset($data[4]) ? intval($data[4]) : 0;
                    if($question_text!==''){
                        $db->insert($t->questions, array(
                            'survey_id'=>$survey_id,
                            'question_text'=>$question_text,
                            'qtype'=>$qtype,
                            'options_csv'=>$options_csv,
                            'is_required'=>$is_required,
                            'sort_order'=>$sort_order,
                        ));
                        $added++;
                    }
                }
                fclose($fh);
                echo '<div class="updated"><p>Import selesai. '.$added.' pertanyaan ditambahkan.</p></div>';
                $sel_survey = $survey_id;
            }
        }

        // CRUD
        if (isset($_POST['at_action']) && $_POST['at_action']==='save_question' && check_admin_referer('at_save_question')){
            $survey_id = intval($_POST['survey_id'] ?? 0);
            $question_text = sanitize_text_field(wp_unslash($_POST['question_text'] ?? ''));
            $qtype = sanitize_text_field(wp_unslash($_POST['qtype'] ?? 'text'));
            $allowed_qtypes = array('text','textarea','number','date','radio','select','checkbox','label');
            if(!in_array($qtype, $allowed_qtypes, true)) $qtype = 'text';
            $options_csv = sanitize_text_field(wp_unslash($_POST['options_csv'] ?? ''));
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $requires_file = isset($_POST['requires_file']) ? 1 : 0;
            $allow_other = isset($_POST['allow_other']) ? 1 : 0;
            $sort_order = intval($_POST['sort_order'] ?? 0);
            if ($survey_id && $question_text!==''){
                // only for radio/select
                if(!in_array($qtype, array('radio','select','checkbox'), true)){
                    $allow_other = 0;
                }
                $cond_parent_id = intval($_POST['cond_parent_id'] ?? 0);
                $cond_operator  = sanitize_text_field(wp_unslash($_POST['cond_operator'] ?? ''));
                $cond_value     = $this->at_cond_value_from_post();
                $cond_action    = sanitize_text_field(wp_unslash($_POST['cond_action'] ?? ''));

                $db->insert($t->questions, array(
                    'survey_id'=>$survey_id,
                    'question_text'=>$question_text,
                    'qtype'=>$qtype,
                    'options_csv'=>$options_csv,
                    'is_required'=>$is_required,
                    'requires_file'=>$requires_file,
                    'allow_other'=>$allow_other,
                    'sort_order'=>$sort_order,
                    'cond_parent_id'=> ($cond_parent_id>0 ? $cond_parent_id : null),
                    'cond_operator'=> ($cond_operator!=='' ? $cond_operator : null),
                    'cond_value'=> ($cond_value!=='' ? $cond_value : null),
                    'cond_action'=> ($cond_action!=='' ? $cond_action : null),
                ));
                echo '<div class="updated"><p>Pertanyaan ditambahkan.</p></div>';
                $sel_survey = $survey_id;
            }
        }
        if (isset($_POST['at_action']) && $_POST['at_action']==='update_question' && check_admin_referer('at_update_question')){
            $id = intval($_POST['id'] ?? 0);
            $question_text = sanitize_text_field(wp_unslash($_POST['question_text'] ?? ''));
            $qtype = sanitize_text_field(wp_unslash($_POST['qtype'] ?? 'text'));
            $allowed_qtypes = array('text','textarea','number','date','radio','select','checkbox','label');
            if(!in_array($qtype, $allowed_qtypes, true)) $qtype = 'text';
            $options_csv = sanitize_text_field(wp_unslash($_POST['options_csv'] ?? ''));
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $requires_file = isset($_POST['requires_file']) ? 1 : 0;
            $allow_other = isset($_POST['allow_other']) ? 1 : 0;
            $sort_order = intval($_POST['sort_order'] ?? 0);
            if ($id && $question_text!==''){
                if(!in_array($qtype, array('radio','select','checkbox'), true)){
                    $allow_other = 0;
                }
                $cond_parent_id = intval($_POST['cond_parent_id'] ?? 0);
                $cond_operator  = sanitize_text_field(wp_unslash($_POST['cond_operator'] ?? ''));
                $cond_value     = $this->at_cond_value_from_post();
                $cond_action    = sanitize_text_field(wp_unslash($_POST['cond_action'] ?? ''));

                $db->update($t->questions, array(
                    'question_text'=>$question_text,
                    'qtype'=>$qtype,
                    'options_csv'=>$options_csv,
                    'is_required'=>$is_required,
                    'requires_file'=>$requires_file,
                    'allow_other'=>$allow_other,
                    'sort_order'=>$sort_order,
                    'cond_parent_id'=> ($cond_parent_id>0 ? $cond_parent_id : null),
                    'cond_operator'=> ($cond_operator!=='' ? $cond_operator : null),
                    'cond_value'=> ($cond_value!=='' ? $cond_value : null),
                    'cond_action'=> ($cond_action!=='' ? $cond_action : null),
                ), array('id'=>$id));
                echo '<div class="updated"><p>Pertanyaan diperbarui.</p></div>';
            }
        }
        if (isset($_POST['at_action']) && $_POST['at_action']==='delete_question' && check_admin_referer('at_delete_question')){
            $id = intval($_POST['id'] ?? 0);
            if($id){
                // Pertanyaan boleh dihapus jika belum ada jawaban terisi.
                // Baris jawaban kosong/NULL/berisi spasi tidak dianggap sebagai jawaban aktif.
                // Nilai "0" tetap dianggap jawaban valid karena sering dipakai sebagai skor opsi.
                $filled_answers = intval($db->get_var($db->prepare(
                    "SELECT COUNT(*) FROM {$t->answers} WHERE question_id=%d AND TRIM(COALESCE(answer_text,'')) <> ''",
                    $id
                )));
                if($filled_answers>0){
                    echo '<div class="error"><p>Tidak bisa menghapus pertanyaan karena sudah memiliki jawaban terisi.</p></div>';
                } else {
                    // Bersihkan baris jawaban kosong agar tidak meninggalkan data yatim.
                    $db->delete($t->answers, array('question_id'=>$id));
                    $db->delete($t->questions, array('id'=>$id));
                    echo '<div class="updated"><p>Pertanyaan dihapus.</p></div>';
                }
            }
        }

        $surveys=$db->get_results("SELECT id,title FROM {$t->surveys} ORDER BY id DESC");
        $groups=$db->get_results("SELECT g.id,g.name,t2.name AS template_name FROM {$t->user_groups} g JOIN {$t->id_templates} t2 ON t2.id=g.template_id ORDER BY g.id DESC");
        echo '<div class="wrap"><h1>Pertanyaan</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="'.self::SLUG.'_questions">';
        echo '<label>Pilih Master Survey: <select name="survey_id" onchange="this.form.submit()"><option value="0">-- pilih --</option>';
        foreach($surveys as $s){ echo '<option value="'.intval($s->id).'" '.selected($sel_survey,$s->id,false).'>'.esc_html($s->title).'</option>'; }
        echo '</select></label> <button class="button">Tampilkan</button></form><hr/>';

	    if($sel_survey){
	        // Build dropdown choices for conditional trigger question (so admin doesn't need to memorize IDs)
	        $cond_choices = $db->get_results($db->prepare(
	            "SELECT id, question_text, qtype, sort_order FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",
	            intval($sel_survey)
	        ));
	        $cond_select_html_new = '<select name="cond_parent_id" class="at-cond-parent" style="min-width:320px;">';
	        $cond_select_html_new .= '<option value="0">-- Pilih pertanyaan pemicu --</option>';
	        if($cond_choices){
	            foreach($cond_choices as $cq){
	                // Skip section header/label because it has no answer value.
	                if(isset($cq->qtype) && $cq->qtype === 'label') continue;
	                $label = '['.intval($cq->id).'] '.wp_strip_all_tags((string)$cq->question_text);
	                $cond_select_html_new .= '<option value="'.intval($cq->id).'">'.esc_html($label).'</option>';
	            }
	        }
	        $cond_select_html_new .= '</select>';
            echo '<h2>Tambah Pertanyaan</h2><form method="post">'; wp_nonce_field('at_save_question');
            echo '<input type="hidden" name="at_action" value="save_question"/><input type="hidden" name="survey_id" value="'.intval($sel_survey).'"/>';
            echo '<table class="form-table">
                <tr><th>Pertanyaan</th><td><input type="text" name="question_text" class="regular-text" required></td></tr>
                <tr><th>Tipe</th><td><select name="qtype" id="at_qtype_new">
                    <option value="text">Text Field</option><option value="textarea">Text Area</option>
                    <option value="number">Number</option><option value="date">Date</option>
                    <option value="radio">Radio</option><option value="select">Drop Down</option>
                    <option value="checkbox">Checkbox (Multi)</option>
                    <option value="label">Label (Section Header)</option></select></td></tr>
                <tr><th>Options (label|nilai, pisahkan koma)</th><td><input type="text" name="options_csv" class="regular-text" placeholder="Sangat Buruk|1, Buruk|2, Baik|3, Sangat Baik|4"></td></tr>
                <tr id="at_allow_other_row_new" style="display:none;"><th>Gunakan Lainnya</th><td><label><input type="checkbox" name="allow_other" value="1"> Tambahkan opsi <strong>Lainnya</strong> + input teks</label></td></tr>
                <tr><th>Wajib?</th><td><label><input type="checkbox" name="is_required" value="1"> Ya</label></td></tr>
                <tr><th>Link Bukti?</th><td><label><input type="checkbox" name="requires_file" value="1"> Wajib isi link bukti untuk pertanyaan ini</label><p class="description">Responden mengisi tautan/URL bukti, bukan upload file.</p></td></tr>
                <tr><th>Urutan</th><td><input type="number" name="sort_order" value="0"></td></tr>
                <tr><th>Conditional (opsional)</th><td>
                    <p style="margin:0 0 6px 0;"><small>Tampilkan/sembunyikan pertanyaan ini berdasarkan jawaban pertanyaan lain.</small></p>
	                    <p style="margin:0 0 6px 0;">
	                        <label>Pertanyaan Pemicu: '.$cond_select_html_new.'</label>
                        &nbsp; <label>Operator:
                            <select name="cond_operator" class="at-cond-operator">
                                <option value="">--</option>
                                <option value="eq">=</option>
                                <option value="neq">!=</option>
                                <option value="in">IN (csv)</option>
                                <option value="not_in">NOT IN (csv)</option>
                                <option value="filled">Terisi</option>
                                <option value="empty">Kosong</option>
                            </select>
                        </label>
                    </p>
                    <p style="margin:0 0 6px 0;">
                        <label>Nilai pembanding:</label><br>'.$this->at_render_cond_value_picker('').'
                    </p>
                    <p style="margin:0;">
                        <label>Aksi:
                            <select name="cond_action">
                                <option value="">show (default)</option>
                                <option value="show">show</option>
                                <option value="hide">hide</option>
                            </select>
                        </label>
                    </p>
                </td></tr>
            </table><p><button class="button button-primary">Tambah</button></p></form>';

            echo '<h2>Import Pertanyaan dari CSV</h2><form method="post" enctype="multipart/form-data">'; wp_nonce_field('at_import_questions');
            echo '<input type="hidden" name="at_action" value="import_csv"/><input type="hidden" name="survey_id" value="'.intval($sel_survey).'"/>';
            echo '<p><input type="file" name="csv" accept=".csv" required> <button class="button">Import</button> ';
            $dl = esc_url(admin_url('admin.php?page='.self::SLUG.'_questions&download_template=1&survey_id='.$sel_survey));
            echo '<a class="button button-secondary" href="'.$dl.'">Unduh Template CSV</a></p>';
			echo '<p><small>Format kolom: <code>question_text,qtype,options_csv,is_required,sort_order</code> (qtype: text, textarea, number, date, radio, select, checkbox, <strong>label</strong>). Header baris pertama opsional.</small></p></form><hr/>';

            $qs = $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",$sel_survey));
            echo '<h2>Daftar Pertanyaan</h2>';
            if($qs){
                echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Teks</th><th>Tipe</th><th>Options</th><th>Lainnya?</th><th>Wajib</th><th>Link Bukti?</th><th>Urutan</th></tr></thead><tbody>';
                foreach($qs as $q){
                    echo '<tr><td>'.intval($q->id).'</td>
                        <td>'.$this->esc($q->question_text).'</td>
                        <td>'.$this->esc($q->qtype).'</td>
                        <td>'.$this->esc($q->options_csv).'</td>
                        <td>'.((isset($q->allow_other) && intval($q->allow_other)===1)?'Ya':'Tidak').'</td>
                        <td>'.($q->is_required?'Ya':'Tidak').'</td>
                        <td>'.((isset($q->requires_file) && intval($q->requires_file)===1)?'Ya':'Tidak').'</td>
                        <td>'.intval($q->sort_order).'</td>
                        <td>
                            <details><summary>Edit</summary>
                            <form method="post" style="margin-top:8px;">'; wp_nonce_field('at_update_question');
                    echo '<input type="hidden" name="at_action" value="update_question"/><input type="hidden" name="id" value="'.intval($q->id).'"/>';
                    echo '<p><input type="text" name="question_text" class="regular-text" value="'.esc_attr($q->question_text).'" required></p>
                          <p><select name="qtype" class="at_qtype_edit" data-qid="'.intval($q->id).'">
                                <option value="text" '.selected($q->qtype,'text',false).'>Text Field</option>
                                <option value="textarea" '.selected($q->qtype,'textarea',false).'>Text Area</option>
                                <option value="number" '.selected($q->qtype,'number',false).'>Number</option>
                                <option value="date" '.selected($q->qtype,'date',false).'>Date</option>
                                <option value="radio" '.selected($q->qtype,'radio',false).'>Radio</option>
                                <option value="select" '.selected($q->qtype,'select',false).'>Drop Down</option>
                                <option value="checkbox" '.selected($q->qtype,'checkbox',false).'>Checkbox (Multi)</option>
                                <option value="label" '.selected($q->qtype,'label',false).'>Label (Section Header)</option>
                             </select></p>
                          <p><input type="text" name="options_csv" class="regular-text" value="'.esc_attr($q->options_csv).'" placeholder="label|nilai, ..."></p>
                          <p class="at_allow_other_row_edit" data-qid="'.intval($q->id).'" style="display:none;"><label><input type="checkbox" name="allow_other" value="1" '.checked((isset($q->allow_other)?intval($q->allow_other):0),1,false).'> Gunakan <strong>Lainnya</strong> + input teks</label></p>
                          <p><label><input type="checkbox" name="is_required" value="1" '.checked($q->is_required,1,false).'> Wajib</label></p>
                          <p><label><input type="checkbox" name="requires_file" value="1" '.checked((isset($q->requires_file)?intval($q->requires_file):0),1,false).'> Link bukti wajib</label><br><small>Responden mengisi tautan/URL bukti, bukan upload file.</small></p>
	                  <p><input type="number" name="sort_order" value="'.intval($q->sort_order).'"></p>';

	                  // Dropdown choices for conditional trigger question (exclude current question, skip label)
	                  $cond_select_html_edit = '<select name="cond_parent_id" class="at-cond-parent" style="min-width:320px;">';
	                  $cond_select_html_edit .= '<option value="0">-- Pilih pertanyaan pemicu --</option>';
	                  if($qs){
	                    foreach($qs as $cq){
	                      if(intval($cq->id) === intval($q->id)) continue;
	                      if(isset($cq->qtype) && $cq->qtype === 'label') continue;
	                      $label = '['.intval($cq->id).'] '.wp_strip_all_tags((string)$cq->question_text);
	                      $cond_select_html_edit .= '<option value="'.intval($cq->id).'" '.selected(intval($q->cond_parent_id ?? 0), intval($cq->id), false).'>'.esc_html($label).'</option>';
	                    }
	                  }
	                  $cond_select_html_edit .= '</select>';

	                  echo '
	                  <hr style="margin:10px 0;">
	                  <p style="margin:0 0 6px 0;"><strong>Conditional (opsional)</strong><br><small>Tampilkan/sembunyikan pertanyaan ini berdasarkan jawaban pertanyaan lain.</small></p>
	                  <p style="margin:0 0 6px 0;">
	                    <label>Pertanyaan Pemicu: '.$cond_select_html_edit.'</label>
                            &nbsp; <label>Operator:
                                <select name="cond_operator" class="at-cond-operator">
                                    <option value="" '.selected((string)($q->cond_operator ?? ''),'',false).'>--</option>
                                    <option value="eq" '.selected((string)($q->cond_operator ?? ''),'eq',false).'>=</option>
                                    <option value="neq" '.selected((string)($q->cond_operator ?? ''),'neq',false).'>!=</option>
                                    <option value="in" '.selected((string)($q->cond_operator ?? ''),'in',false).'>IN (csv)</option>
                                    <option value="not_in" '.selected((string)($q->cond_operator ?? ''),'not_in',false).'>NOT IN (csv)</option>
                                    <option value="filled" '.selected((string)($q->cond_operator ?? ''),'filled',false).'>Terisi</option>
                                    <option value="empty" '.selected((string)($q->cond_operator ?? ''),'empty',false).'>Kosong</option>
                                </select>
                            </label>
                          </p>
                          <p style="margin:0 0 6px 0;">
                            <label>Nilai pembanding:</label><br>'.$this->at_render_cond_value_picker((string)($q->cond_value ?? '')).'
                          </p>
                          <p style="margin:0 0 6px 0;">
                            <label>Aksi:
                                <select name="cond_action">
                                    <option value="" '.selected((string)($q->cond_action ?? ''),'',false).'>show (default)</option>
                                    <option value="show" '.selected((string)($q->cond_action ?? ''),'show',false).'>show</option>
                                    <option value="hide" '.selected((string)($q->cond_action ?? ''),'hide',false).'>hide</option>
                                </select>
                            </label>
                          </p>
                          <p><button class="button button-primary">Simpan</button></p></form>';

                    echo '<form method="post" style="display:inline;">'; wp_nonce_field('at_delete_question');
                    echo '<input type="hidden" name="at_action" value="delete_question"/><input type="hidden" name="id" value="'.intval($q->id).'"/>';
                    echo '<button class="button-link-delete at-del" data-msg="'.esc_attr__('Hapus pertanyaan ini?','akurasitara').'" style="color:#b00;">Hapus</button></form>';
                    echo '</details></td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p><em>Belum ada pertanyaan.</em></p>';
            }
        }
        

        $cond_options_json = wp_json_encode($this->at_cond_question_options_data(isset($qs) ? $qs : array()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<script>window.atCondQuestionOptions = '.$cond_options_json.';</script>';
        echo '<script>(function(){
            function qa(sel,root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}
            function q(sel,root){return (root||document).querySelector(sel);}
            var data = window.atCondQuestionOptions || {};
            function split(v){return (v||"").split(",").map(function(x){return x.trim();}).filter(Boolean);}
            function isMulti(op){return op==="in" || op==="not_in";}
            function needsValue(op){return op==="eq" || op==="neq" || op==="in" || op==="not_in";}
            function esc(s){return String(s==null?"":s).replace(/[&<>\"]/g,function(c){ if(c==="&") return "&amp;"; if(c==="<") return "&lt;"; if(c===">") return "&gt;"; return "&quot;"; });}
            function formOf(el){return el ? el.closest("form") : null;}
            function syncPicker(form){
                if(!form) return;
                var parent = q("select.at-cond-parent", form);
                var opSel  = q("select.at-cond-operator", form);
                var picker = q(".at-cond-value-picker", form);
                if(!parent || !opSel || !picker) return;
                var hidden = q(".at-cond-value-hidden", picker);
                var ui = q(".at-cond-value-ui", picker);
                var op = opSel.value || "";
                var pid = parent.value || "0";
                var current = split((hidden && hidden.value) || picker.getAttribute("data-current") || "");
                if(!needsValue(op)){
                    if(hidden) hidden.value = "";
                    ui.innerHTML = (op==="filled" || op==="empty") ? "<em>Operator ini tidak memerlukan nilai pembanding.</em>" : "<em>Pilih operator terlebih dahulu.</em>";
                    return;
                }
                var info = data[pid] || null;
                var opts = info && info.options ? info.options : [];
                if(!info || pid==="0"){
                    ui.innerHTML = "<em>Pilih pertanyaan pemicu terlebih dahulu.</em>";
                    return;
                }
                if(!opts.length){
                    ui.innerHTML = "<input type=\"text\" class=\"regular-text at-cond-manual-value\" value=\""+esc(current.join(","))+"\" placeholder=\"Isi nilai pembanding manual\">"+
                                   "<p class=\"description\">Pertanyaan pemicu ini bukan pertanyaan pilihan, sehingga nilai pembanding masih perlu diketik manual.</p>";
                    var txt = q(".at-cond-manual-value", picker);
                    if(txt){ txt.addEventListener("input", function(){ if(hidden) hidden.value = txt.value; }); }
                    if(hidden) hidden.value = current.join(",");
                    return;
                }
                var type = isMulti(op) ? "checkbox" : "radio";
                var html = "<div class=\"at-cond-choice-list\" style=\"margin-top:4px;display:flex;gap:10px;flex-wrap:wrap;\">";
                opts.forEach(function(o){
                    var checked = current.indexOf(String(o.value)) >= 0 ? " checked" : "";
                    html += "<label style=\"display:inline-flex;gap:5px;align-items:center;border:1px solid #ccd0d4;padding:6px 8px;border-radius:4px;background:#fff;\">"+
                            "<input type=\""+type+"\" name=\"cond_value_choices[]\" value=\""+esc(o.value)+"\""+checked+"> "+
                            "<span>"+esc(o.label)+"</span></label>";
                });
                html += "</div>";
                html += isMulti(op) ? "<p class=\"description\">Operator ini mengizinkan lebih dari satu pilihan.</p>" : "<p class=\"description\">Operator ini hanya mengizinkan satu pilihan.</p>";
                ui.innerHTML = html;
                function updateHidden(){
                    var vals = qa("input[name=\"cond_value_choices[]\"]:checked", picker).map(function(i){return i.value;});
                    if(hidden) hidden.value = vals.join(",");
                }
                qa("input[name=\"cond_value_choices[]\"]", picker).forEach(function(inp){ inp.addEventListener("change", updateHidden); });
                updateHidden();
            }
            function init(){
                qa("form").forEach(function(form){ if(q(".at-cond-value-picker", form)){ syncPicker(form); } });
                qa("select.at-cond-parent, select.at-cond-operator").forEach(function(sel){
                    sel.addEventListener("change", function(){ var form=formOf(sel); var picker=q(".at-cond-value-picker", form); if(picker){ picker.setAttribute("data-current", ""); var h=q(".at-cond-value-hidden", picker); if(h) h.value=""; } syncPicker(form); });
                });
            }
            if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded", init);} else {init();}
        })();</script>';

		// Toggle UI for "Gunakan Lainnya" on add/edit question forms.
		echo '<script>(function(){
			function q(sel,root){return (root||document).querySelector(sel);}
			function qa(sel,root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}
			// "Lainnya" supported for: radio, dropdown(select), and checkbox (multi)
			function isOtherCapable(t){ t=(t||"").toLowerCase(); return (t==="radio" || t==="select" || t==="checkbox"); }
			function showRow(row,show){ if(!row) return; row.style.display = show ? "" : "none"; }
			function toggleNew(){
				var sel = q("#at_qtype_new");
				if(!sel) return;
				var row = q("#at_allow_other_row_new");
				var ok  = isOtherCapable(sel.value);
				showRow(row, ok);
				if(!ok){ var cb=q("input[name=allow_other]", row); if(cb){ cb.checked=false; } }
			}
			function toggleEdits(){
				qa("select.at_qtype_edit").forEach(function(sel){
					var qid = sel.getAttribute("data-qid");
					var row = q(".at_allow_other_row_edit[data-qid=\""+qid+"\"]");
					var ok  = isOtherCapable(sel.value);
					showRow(row, ok);
					if(!ok){ var cb=q("input[name=allow_other]", row); if(cb){ cb.checked=false; } }
				});
			}
			function bind(){
				var newSel = q("#at_qtype_new");
				if(newSel){ newSel.addEventListener("change", function(){ toggleNew(); }); }
				qa("select.at_qtype_edit").forEach(function(sel){
					sel.addEventListener("change", function(){ toggleEdits(); });
				});
			}
			function init(){ bind(); toggleNew(); toggleEdits(); }
			if(document.readyState==="loading"){ document.addEventListener("DOMContentLoaded", init); }
			else { init(); }
		})();</script>';

echo '</div>';
    }

    /* ================= Admin: Runs ================= */
    public function page_runs(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        // Edit mode (optional)
        $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
        $edit_run = null;
        if($edit_id){
            $edit_run = $db->get_row($db->prepare("SELECT * FROM {$t->runs} WHERE id=%d", $edit_id));
            if(!$edit_run){ $edit_id = 0; }
        }

        if (isset($_POST['at_action']) && $_POST['at_action']==='delete_run' && check_admin_referer('at_delete_run')){
            $delete_id = intval($_POST['run_id'] ?? 0);
            if($delete_id){
                $resp_count = intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->responses} WHERE run_id=%d", $delete_id)));
                if($resp_count > 0){
                    echo '<div class="error"><p>Pelaksanaan tidak dapat dihapus karena sudah ada '.intval($resp_count).' responden yang mengisi.</p></div>';
                } else {
                    $deleted = $db->delete($t->runs, array('id'=>$delete_id));
                    if($deleted === false){
                        echo '<div class="error"><p>Pelaksanaan gagal dihapus. Detail database: '.$this->esc($db->last_error ?: 'Tidak diketahui').'</p></div>';
                    } elseif($deleted){
                        echo '<div class="updated"><p>Pelaksanaan berhasil dihapus.</p></div>';
                        if($edit_id === $delete_id){ $edit_id = 0; $edit_run = null; }
                    } else {
                        echo '<div class="error"><p>Pelaksanaan tidak ditemukan atau sudah dihapus.</p></div>';
                    }
                }
            }
        }

        if (isset($_POST['at_action']) && $_POST['at_action']==='recheck_old_responses' && check_admin_referer('at_recheck_old_responses')){
            $recheck_run_id = intval($_POST['run_id'] ?? 0);
            $summary = $this->at_recheck_latest_user_responses($recheck_run_id);
            echo '<div class="updated"><p>Pemeriksaan data selesai. Diperiksa: '.intval($summary['checked']).' isian terakhir responden. Menjadi completed: '.intval($summary['completed']).' response. Menjadi in progress: '.intval($summary['in_progress']).' response. Berubah status: '.intval($summary['changed']).' response.</p></div>';
        }

        if (isset($_POST['at_action']) && $_POST['at_action']==='save_run' && check_admin_referer('at_save_run')){
            $run_id   = intval($_POST['run_id'] ?? 0);
            $survey_id = intval($_POST['survey_id'] ?? 0);
            $run_name  = sanitize_text_field(wp_unslash($_POST['run_name'] ?? ''));
            $unit_id   = intval($_POST['unit_id'] ?? 0);
            $year      = intval($_POST['year'] ?? date('Y'));
            $status    = sanitize_text_field(wp_unslash($_POST['status'] ?? 'active'));
            $password  = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
            $survey_type = sanitize_text_field(wp_unslash($_POST['survey_type'] ?? 'general'));
            if(!in_array($survey_type, array('general','group'), true)) $survey_type='general';
            $group_id = intval($_POST['group_id'] ?? 0);
            if($survey_type!=='group'){ $group_id = 0; }

            $is_active  = isset($_POST['is_active']) ? 1 : 0;
            $start_date = isset($_POST['start_date']) ? $this->parse_datetime_local_to_mysql( wp_unslash($_POST['start_date']) ) : null;
            $end_date   = isset($_POST['end_date']) ? $this->parse_datetime_local_to_mysql( wp_unslash($_POST['end_date']) ) : null;

            if($survey_id && $unit_id && $year){
                $data = array(
                    'run_name'=>$run_name,
                    'survey_id'=>$survey_id,
                    'unit_id'=>$unit_id,
                    'year'=>$year,
                    'survey_type'=>$survey_type,
                    'group_id'=>($group_id?:null),
                    'status'=>$status,
                    'is_active'=>$is_active,
                    'start_date'=>$start_date,
                    'end_date'=>$end_date,
                    'password'=>$password
                );

                if($run_id){
                    $updated = $db->update($t->runs, $data, array('id'=>$run_id));
                    if($updated === false){
                        echo '<div class="error"><p>Pelaksanaan gagal diperbarui. Detail database: '.$this->esc($db->last_error ?: 'Tidak diketahui').'</p></div>';
                    } else {
                        echo '<div class="updated"><p>Pelaksanaan diperbarui.</p></div>';
                    }
                    // refresh edit mode after update
                    $edit_id = $run_id;
                    $edit_run = $db->get_row($db->prepare("SELECT * FROM {$t->runs} WHERE id=%d", $edit_id));
                } else {
                    $inserted = $db->insert($t->runs, $data);
                    if($inserted === false){
                        echo '<div class="error"><p>Pelaksanaan gagal disimpan. Detail database: '.$this->esc($db->last_error ?: 'Tidak diketahui').'</p></div>';
                    } else {
                        echo '<div class="updated"><p>Pelaksanaan disimpan.</p></div>';
                    }
                }
            }
        }

        $to_local = function($dt){
            $dt = (string)$dt;
            if(!$dt) return '';
            // MySQL datetime -> datetime-local (YYYY-MM-DDTHH:MM)
            $dt = str_replace(' ', 'T', $dt);
            return substr($dt, 0, 16);
        };

        // Form defaults
        $form = array(
            'run_id'     => $edit_id ? intval($edit_id) : 0,
            'run_name'   => $edit_id && $edit_run ? (string)$edit_run->run_name : '',
            'survey_id'  => $edit_id && $edit_run ? intval($edit_run->survey_id) : 0,
            'unit_id'    => $edit_id && $edit_run ? intval($edit_run->unit_id) : 0,
            'year'       => $edit_id && $edit_run ? intval($edit_run->year) : intval(date('Y')),
            'survey_type'=> $edit_id && $edit_run ? ($edit_run->survey_type ?: 'general') : 'general',
            'group_id'   => $edit_id && $edit_run ? intval($edit_run->group_id) : 0,
            'status'     => $edit_id && $edit_run ? ($edit_run->status ?: 'active') : 'active',
            'is_active'  => $edit_id && $edit_run ? intval($edit_run->is_active) : 1,
            'start_date' => $edit_id && $edit_run ? $to_local($edit_run->start_date) : '',
            'end_date'   => $edit_id && $edit_run ? $to_local($edit_run->end_date) : '',
            'password'   => $edit_id && $edit_run ? (string)$edit_run->password : '',
        );

        $surveys=$db->get_results("SELECT id,title FROM {$t->surveys} ORDER BY id DESC");
        $units=$db->get_results("SELECT id,name FROM {$t->units} ORDER BY id DESC");
        $groups=$db->get_results("SELECT g.id,g.name,t2.name AS template_name FROM {$t->user_groups} g JOIN {$t->id_templates} t2 ON t2.id=g.template_id ORDER BY g.id DESC");

        $runs=$db->get_results("SELECT r.*, s.title AS survey_title, u.name AS unit_name, COALESCE(rc.response_count,0) AS response_count, COALESCE(rc.in_progress_count,0) AS in_progress_count, COALESCE(rc.completed_count,0) AS completed_count
                FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id
                JOIN {$t->units} u ON u.id=r.unit_id
                LEFT JOIN (SELECT run_id, COUNT(*) AS response_count, SUM(CASE WHEN fill_status='in_progress' THEN 1 ELSE 0 END) AS in_progress_count, SUM(CASE WHEN fill_status='completed' THEN 1 ELSE 0 END) AS completed_count FROM {$t->responses} GROUP BY run_id) rc ON rc.run_id = r.id
                ORDER BY r.id DESC");

        echo '<div class="wrap"><h1>Pelaksanaan Survey</h1>';
        echo '<form method="post" style="margin:12px 0 18px 0; padding:12px; background:#fff; border:1px solid #ccd0d4; display:inline-block;">';
        wp_nonce_field('at_recheck_old_responses');
        echo '<input type="hidden" name="at_action" value="recheck_old_responses" />';
        echo '<input type="hidden" name="run_id" value="0" />';
        echo '<button type="submit" class="button" onclick="return confirm(\'Cek isian terakhir setiap responden dan perbarui status sesuai kelengkapan jawaban?\');">Cek Data Lama Semua Pelaksanaan</button>';
        echo '<p class="description" style="margin:6px 0 0 0; max-width:720px;">Memeriksa isian terakhir setiap responden berdasarkan pertanyaan wajib yang aktif/terlihat sesuai conditional logic. Jika isian terakhir sudah lengkap akan menjadi completed; jika belum lengkap menjadi in progress.</p>';
        echo '</form>';
        if($form['run_id']){
            echo '<h2>Edit Pelaksanaan</h2>';
            echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=akurasitara_runs')).'">Batal Edit</a></p>';
        } else {
            echo '<h2>Buat Pelaksanaan Baru</h2>';
        }

        echo '<form method="post">';
        wp_nonce_field('at_save_run');
        echo '<input type="hidden" name="at_action" value="save_run"/>
              <input type="hidden" name="run_id" value="'.intval($form['run_id']).'"/>
        <table class="form-table">
            <tr><th>Nama Pelaksanaan Survey</th><td><input type="text" name="run_name" class="regular-text" value="'.esc_attr($form['run_name']).'" placeholder="Contoh: Tracer Study TW 1 Tahun 2026"><p class="description">Opsional, digunakan untuk membedakan nama pelaksanaan pada daftar hasil dan ekspor.</p></td></tr>
            <tr><th>Survey</th><td><select name="survey_id" required><option value="">-- Pilih Survey --</option>';
        foreach($surveys as $s){ echo '<option value="'.intval($s->id).'" '.selected($form['survey_id'], intval($s->id), false).'>'.$this->esc($s->title).'</option>'; }
        echo '</select></td></tr>
            <tr><th>Unit Kerja</th><td><select name="unit_id" required><option value="">-- Pilih Unit --</option>';
        foreach($units as $u){ echo '<option value="'.intval($u->id).'" '.selected($form['unit_id'], intval($u->id), false).'>'.$this->esc($u->name).'</option>'; }
        echo '</select></td></tr>
            <tr><th>Tahun</th><td><input type="number" name="year" value="'.intval($form['year']).'" required></td></tr>
            <tr><th>Jenis Survey</th><td><select name="survey_type" id="at_survey_type">
                <option value="general" '.selected($form['survey_type'], 'general', false).'>Survey Umum</option>
                <option value="group" '.selected($form['survey_type'], 'group', false).'>Survey Pengguna Tertentu</option>
            </select></td></tr>
            <tr id="at_group_row"><th>Kelompok Pengguna</th><td><select name="group_id" id="at_group_id">
                <option value="">-- Pilih Kelompok --</option>';
        foreach($groups as $g){ echo '<option value="'.intval($g->id).'" '.selected($form['group_id'], intval($g->id), false).'>'.$this->esc($g->name).' ('.$this->esc($g->template_name).')</option>'; }
        echo '</select><p class="description">Muncul hanya jika jenis survey = Survey Pengguna Tertentu.</p></td></tr>
            <tr><th>Jadwal Mulai (opsional)</th><td><input type="datetime-local" name="start_date" value="'.esc_attr($form['start_date']).'" /></td></tr>
            <tr><th>Jadwal Berakhir (opsional)</th><td><input type="datetime-local" name="end_date" value="'.esc_attr($form['end_date']).'" /></td></tr>
            <tr><th>Aktif?</th><td><label><input type="checkbox" name="is_active" value="1" '.checked(intval($form['is_active']),1,false).'> Aktif</label> <p class="description">Jika tidak aktif, survey tidak dapat diisi.</p></td></tr>
            <tr><th>Password (opsional)</th><td><input type="text" name="password" value="'.esc_attr($form['password']).'" placeholder="Kosongkan jika tidak perlu"></td></tr>
            <tr><th>Status</th><td><select name="status">
                <option value="active" '.selected($form['status'], 'active', false).'>Aktif</option>
                <option value="closed" '.selected($form['status'], 'closed', false).'>Ditutup</option>
            </select> <p class="description">Status tetap disimpan, namun kontrol utama akses adalah <strong>Aktif?</strong> dan jadwal.</p></td></tr>
        </table>
        <p><button class="button button-primary">'.($form['run_id']?'Simpan Perubahan':'Simpan').'</button></p></form>
        <script>(function(){var st=document.getElementById("at_survey_type");var row=document.getElementById("at_group_row");function sync(){if(!st||!row)return;row.style.display=(st.value==="group")?"table-row":"none";} if(st){st.addEventListener("change",sync);sync();}})();</script>';

        echo '<hr/><h2>Daftar Pelaksanaan</h2>';
        if($runs){
            $nonce = wp_create_nonce('at_run_toggle');
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nama Pelaksanaan</th><th>Survey</th><th>Unit</th><th>Tahun</th><th>Status</th><th>Aktif?</th><th>Jadwal</th><th>Jenis</th><th>Kelompok</th><th>In Progress</th><th>Completed</th><th>Total Respon</th><th>Password?</th><th>Shortcode Form</th><th>Shortcode Hasil</th><th>Aksi</th></tr></thead><tbody>';
            foreach($runs as $r){
                $sc_form  = '[akurasitara_survey id="'.$r->id.'"]';
                $sc_res   = '[akurasitara_results id="'.$r->id.'"]';
                $is_active = isset($r->is_active) ? intval($r->is_active) : 1;
                $sched = '';
                if(!empty($r->start_date) || !empty($r->end_date)){
                    $sched = ($r->start_date ? $this->esc($r->start_date) : '-') . ' → ' . ($r->end_date ? $this->esc($r->end_date) : '-');
                } else {
                    $sched = '-';
                }
                echo '<tr>';
                echo '<td>'.intval($r->id).'</td>';
                echo '<td>'.($r->run_name ? $this->esc($r->run_name) : '-').'</td>';
                echo '<td>'.$this->esc($r->survey_title).'</td>';
                echo '<td>'.$this->esc($r->unit_name).'</td>';
                echo '<td>'.intval($r->year).'</td>';
                echo '<td>'.$this->esc($r->status).'</td>';

                echo '<td><label><input type="checkbox" class="at-run-active" data-id="'.intval($r->id).'" '.checked($is_active,1,false).'/> Aktif</label></td>';
                echo '<td><small>'.$sched.'</small></td>'; 

                echo '<td>'.(($r->survey_type??'general')==='group'?'Pengguna Tertentu':'Umum').'</td>';
                echo '<td>'.($r->group_id?('ID '.intval($r->group_id)):'-').'</td>';
                $response_count = isset($r->response_count) ? intval($r->response_count) : 0;
                $in_progress_count = isset($r->in_progress_count) ? intval($r->in_progress_count) : 0;
                $completed_count = isset($r->completed_count) ? intval($r->completed_count) : 0;
                echo '<td>'.intval($in_progress_count).'</td>';
                echo '<td>'.intval($completed_count).'</td>';
                echo '<td>'.intval($response_count).'</td>';
                echo '<td>'.($r->password?'Ya':'Tidak').'</td>';
                echo '<td><code>'.$sc_form.'</code></td>';
                echo '<td><code>'.$sc_res.'</code></td>';
                $edit_url = esc_url(admin_url('admin.php?page=akurasitara_runs&edit_id='.intval($r->id)));
                echo '<td><a class="button button-small" href="'.$edit_url.'">Edit</a> ';
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Cek response completed pada pelaksanaan ini dan ubah yang belum lengkap menjadi in progress?\');">';
                wp_nonce_field('at_recheck_old_responses');
                echo '<input type="hidden" name="at_action" value="recheck_old_responses" />';
                echo '<input type="hidden" name="run_id" value="'.intval($r->id).'" />';
                echo '<button type="submit" class="button button-small">Cek Data</button>';
                echo '</form> ';
                if($response_count === 0){
                    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Yakin ingin menghapus pelaksanaan survey ini?\');">';
                    wp_nonce_field('at_delete_run');
                    echo '<input type="hidden" name="at_action" value="delete_run" />';
                    echo '<input type="hidden" name="run_id" value="'.intval($r->id).'" />';
                    echo '<button type="submit" class="button button-small button-link-delete">Hapus</button>';
                    echo '</form>';
                } else {
                    echo '<button type="button" class="button button-small" disabled title="Tidak dapat dihapus karena sudah ada responden yang mengisi">Hapus</button>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Inline JS for toggles (admin only)
            echo '<script>(function($){
                var nonce = '.json_encode($nonce).';
                function post(action, id, status){
                    var data = {action: action, _ajax_nonce: nonce, id: id, status: status};
                    return $.post(ajaxurl, data);
                }
                $(document).on("change", ".at-run-active", function(){
                    var id = $(this).data("id");
                    var st = $(this).is(":checked") ? 1 : 0;
                    post("at_toggle_run_active", id, st);
                });
            })(jQuery);</script>';
        } else {
            echo '<p><em>Belum ada pelaksanaan.</em></p>';
        }
        echo '</div>';
    }


    /**
     * Deteksi delimiter CSV sederhana agar file dari Excel Indonesia (;) tetap terbaca.
     */
    private function at_detect_csv_delimiter($file_path){
        $sample = '';
        $fh = @fopen($file_path, 'r');
        if($fh){
            $sample = (string) fgets($fh, 4096);
            fclose($fh);
        }
        $candidates = array(",", ";", "\t");
        $best = ","; $best_count = -1;
        foreach($candidates as $d){
            $count = substr_count($sample, $d);
            if($count > $best_count){ $best = $d; $best_count = $count; }
        }
        return $best;
    }

    private function at_norm_import_value($v){
        $v = is_array($v) ? implode(',', $v) : (string)$v;
        $v = wp_unslash($v);
        $v = str_replace("\xEF\xBB\xBF", '', $v);
        $v = trim($v);
        // Normalize duplicate legacy values such as "20,20" into "20" for comparison.
        if($v !== '' && strpos($v, ',') !== false){
            $parts = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
            if(count($parts) >= 2){
                $uniq = array_values(array_unique($parts));
                if(count($uniq) === 1){ $v = $uniq[0]; }
            }
        }
        return $v;
    }

    private function at_csv_header_to_qid($header){
        $header = trim((string)$header);
        if(preg_match('/^Q(\d+)\s*:/i', $header, $m)) return intval($m[1]);
        if(preg_match('/^q_(\d+)$/i', $header, $m)) return intval($m[1]);
        if(preg_match('/^question_(\d+)$/i', $header, $m)) return intval($m[1]);
        return 0;
    }

    private function at_allowed_values_for_question($q){
        $vals = array();
        $pairs = $this->parse_options_pairs(isset($q->options_csv) ? $q->options_csv : '');
        foreach($pairs as $pair){
            $val = isset($pair['value_long']) ? trim((string)$pair['value_long']) : '';
            if($val !== '') $vals[$val] = true;
        }
        return $vals;
    }

    private function at_validate_import_answer($value, $q, &$message){
        $message = '';
        $value = $this->at_norm_import_value($value);
        $label = isset($q->question_text) ? trim(wp_strip_all_tags((string)$q->question_text)) : ('Q'.intval($q->id));

        if(intval($q->is_required ?? 0) === 1 && $value === ''){
            $message = 'Pertanyaan wajib "'.$label.'" tidak boleh kosong.';
            return false;
        }
        if($value === '') return true;

        $qt = (string)($q->qtype ?? 'text');
        if($qt === 'number'){
            if(!is_numeric($value)){
                $message = 'Jawaban "'.$label.'" harus berupa angka.';
                return false;
            }
            return true;
        }
        if($qt === 'date'){
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){
                $message = 'Jawaban "'.$label.'" harus berformat tanggal YYYY-MM-DD.';
                return false;
            }
            list($yy,$mm,$dd) = array_map('intval', explode('-', $value));
            if(!checkdate($mm,$dd,$yy)){
                $message = 'Jawaban tanggal "'.$label.'" tidak valid.';
                return false;
            }
            return true;
        }
        if(in_array($qt, array('radio','select','checkbox'), true)){
            // Jika "Gunakan Lainnya" aktif, nilai bebas tetap valid karena sistem menyimpan teks lainnya di answer_text.
            if(intval($q->allow_other ?? 0) === 1) return true;
            $allowed = $this->at_allowed_values_for_question($q);
            if(!$allowed) return true;
            $parts = ($qt === 'checkbox') ? array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen')) : array($value);
            foreach($parts as $part){
                if(!isset($allowed[$part])){
                    $message = 'Jawaban "'.$label.'" berisi nilai di luar opsi yang tersedia: '.$part;
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Import CSV untuk memperbarui jawaban hasil survey yang sudah ada.
     * Format paling aman: gunakan file dari tombol Export CSV, ubah kolom Q{id}: ..., lalu upload kembali.
     */
    private function at_handle_results_import_csv($run, $questions){
        if(!current_user_can('manage_options')) return;
        if(!isset($_POST['at_import_results_csv'])) return;
        check_admin_referer('at_import_results_csv');

        $run_id = intval($run->id);
        if(empty($_FILES['at_results_csv']['tmp_name']) || !is_uploaded_file($_FILES['at_results_csv']['tmp_name'])){
            echo '<div class="notice notice-error"><p>File CSV belum dipilih.</p></div>';
            return;
        }
        $file = $_FILES['at_results_csv'];
        if(!empty($file['error'])){
            echo '<div class="notice notice-error"><p>Upload CSV gagal. Kode error: '.intval($file['error']).'</p></div>';
            return;
        }
        $name = isset($file['name']) ? (string)$file['name'] : '';
        if($name && !preg_match('/\.csv$/i', $name)){
            echo '<div class="notice notice-error"><p>File harus berformat .csv.</p></div>';
            return;
        }

        global $wpdb; $t = $this->tables();
        $tmp = $file['tmp_name'];
        $delim = $this->at_detect_csv_delimiter($tmp);
        $fh = @fopen($tmp, 'r');
        if(!$fh){
            echo '<div class="notice notice-error"><p>File CSV tidak dapat dibaca.</p></div>';
            return;
        }

        $header = fgetcsv($fh, 0, $delim);
        if(!$header || !is_array($header)){
            fclose($fh);
            echo '<div class="notice notice-error"><p>Header CSV tidak ditemukan.</p></div>';
            return;
        }
        $header = array_map(array($this, 'at_norm_import_value'), $header);
        if(isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $response_idx = array_search('response_id', $header, true);
        if($response_idx === false){
            fclose($fh);
            echo '<div class="notice notice-error"><p>CSV harus memiliki kolom <code>response_id</code>. Gunakan file hasil Export CSV sebagai template.</p></div>';
            return;
        }

        $qmap = array();
        foreach($questions as $q){
            if((string)$q->qtype === 'label') continue;
            $qmap[intval($q->id)] = $q;
        }
        $q_col_map = array();
        foreach($header as $idx=>$h){
            $qid = $this->at_csv_header_to_qid($h);
            if($qid > 0){
                if(!isset($qmap[$qid])){
                    fclose($fh);
                    echo '<div class="notice notice-error"><p>CSV memuat kolom pertanyaan Q'.intval($qid).' yang tidak sesuai dengan survey pada run ini.</p></div>';
                    return;
                }
                $q_col_map[$idx] = $qid;
            }
        }
        if(!$q_col_map){
            fclose($fh);
            echo '<div class="notice notice-error"><p>Tidak ada kolom jawaban yang dapat diimpor. Kolom jawaban harus berformat <code>Q{id}: teks pertanyaan</code>.</p></div>';
            return;
        }

        $errors = array();
        $planned = array(); // rid => qid => value
        $line = 1;
        while(($row = fgetcsv($fh, 0, $delim)) !== false){
            $line++;
            if(count($row) === 1 && trim((string)$row[0]) === '') continue;
            $rid = isset($row[$response_idx]) ? intval($this->at_norm_import_value($row[$response_idx])) : 0;
            if($rid <= 0){
                $errors[] = 'Baris '.$line.': response_id tidak valid.';
                continue;
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t->responses} WHERE id=%d AND run_id=%d", $rid, $run_id));
            if(!$exists){
                $errors[] = 'Baris '.$line.': response_id '.$rid.' tidak ditemukan pada run ini.';
                continue;
            }
            foreach($q_col_map as $idx=>$qid){
                $value = isset($row[$idx]) ? sanitize_text_field($this->at_norm_import_value($row[$idx])) : '';
                // Validasi isi jawaban dimatikan sesuai permintaan.
                // Nilai dari CSV akan diterima apa adanya setelah sanitasi dasar WordPress.
                if(!isset($planned[$rid])) $planned[$rid] = array();
                $planned[$rid][$qid] = $value;
            }
        }
        fclose($fh);

        if($errors){
            echo '<div class="notice notice-error"><p><strong>Import dibatalkan.</strong> Terdapat kesalahan teknis pada CSV. Tidak ada data yang diupdate.</p><ul style="margin-left:18px;list-style:disc;">';
            foreach(array_slice($errors, 0, 25) as $e){ echo '<li>'.esc_html($e).'</li>'; }
            if(count($errors) > 25){ echo '<li>Dan '.(count($errors)-25).' error lainnya.</li>'; }
            echo '</ul></div>';
            return;
        }
        if(!$planned){
            echo '<div class="notice notice-warning"><p>Tidak ada baris data yang dapat diproses.</p></div>';
            return;
        }

        $updates = 0; $unchanged = 0; $responses_changed = array();
        $wpdb->query('START TRANSACTION');
        foreach($planned as $rid=>$answers){
            foreach($answers as $qid=>$new_val){
                $old_val = $wpdb->get_var($wpdb->prepare("SELECT answer_text FROM {$t->answers} WHERE response_id=%d AND question_id=%d ORDER BY id DESC LIMIT 1", $rid, $qid));
                $old_val = $this->at_norm_import_value($old_val);
                if($old_val === $new_val){
                    $unchanged++;
                    continue;
                }
                $wpdb->delete($t->answers, array('response_id'=>intval($rid), 'question_id'=>intval($qid)), array('%d','%d'));
                $ok = $wpdb->insert($t->answers, array(
                    'response_id'=>intval($rid),
                    'question_id'=>intval($qid),
                    'answer_text'=>$new_val,
                ), array('%d','%d','%s'));
                if($ok === false){
                    $wpdb->query('ROLLBACK');
                    echo '<div class="notice notice-error"><p>Import gagal saat menyimpan data. Tidak ada data yang diupdate.</p></div>';
                    return;
                }
                $updates++;
                $responses_changed[intval($rid)] = true;
            }
        }
        $wpdb->query('COMMIT');

        if($updates > 0){
            echo '<div class="notice notice-success"><p>Import berhasil. '.intval($updates).' jawaban diperbarui pada '.count($responses_changed).' response. '.intval($unchanged).' jawaban tidak berubah.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>CSV berhasil dibaca, tetapi tidak ada perubahan data yang ditemukan. Tidak ada jawaban yang diupdate.</p></div>';
        }
    }

function page_results(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        $run_id = isset($_GET['run_id']) ? intval($_GET['run_id']) : 0;
        $latest_only = isset($_GET['latest_only']) ? intval($_GET['latest_only']) : 0;
        $fill_status_filter = sanitize_text_field($_GET['fill_status'] ?? 'completed');
        if(!in_array($fill_status_filter, array('completed','in_progress','all'), true)) $fill_status_filter = 'completed';
        $answer_display = $this->at_answer_display_mode();
        echo '<div class="wrap"><h1>Hasil Survey</h1>';

        $runs=$db->get_results("SELECT r.id, r.run_name, s.title AS survey_title, u.name AS unit_name, r.year
                FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id JOIN {$t->units} u ON u.id=r.unit_id ORDER BY r.id DESC");

        echo '<form method="get" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="'.self::SLUG.'_results">';
        echo '<label>Pilih Pelaksanaan (Run): <select name="run_id" onchange="this.form.submit()"><option value="0">-- pilih --</option>';
        foreach($runs as $r){ echo '<option value="'.intval($r->id).'" '.selected($run_id,$r->id,false).'>'.esc_html(($r->run_name ? $r->run_name.' — ' : '').$r->survey_title.' — '.$r->unit_name.' / '.$r->year).'</option>'; }
        echo '</select></label>';
        echo '<label>Tampilkan Data: <select name="latest_only">';
        echo '<option value="0" '.selected($latest_only,0,false).'>Semua isian</option>';
        echo '<option value="1" '.selected($latest_only,1,false).'>Isian terakhir per pengguna</option>';
        echo '</select></label>';
        echo '<label>Status Pengisian: <select name="fill_status">';
        echo '<option value="completed" '.selected($fill_status_filter,'completed',false).'>Completed</option>';
        echo '<option value="in_progress" '.selected($fill_status_filter,'in_progress',false).'>In Progress</option>';
        echo '<option value="all" '.selected($fill_status_filter,'all',false).'>Semua Status</option>';
        echo '</select></label>';
        echo '<label>Tampilan Jawaban Pilihan: <select name="answer_display">';
        echo '<option value="value" '.selected($answer_display,'value',false).'>Sesuai nilai hasil</option>';
        echo '<option value="label" '.selected($answer_display,'label',false).'>Sesuai label opsi survey</option>';
        echo '</select></label>';
        echo '<button class="button">Tampilkan</button></form>';

        if(!$run_id){ echo '</div>'; return; }

        $run=$db->get_row($db->prepare("SELECT r.*, s.title AS survey_title, u.name AS unit_name FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id JOIN {$t->units} u ON u.id=r.unit_id WHERE r.id=%d",$run_id));
        if(!$run){ echo '<p><em>Run tidak ditemukan.</em></p></div>'; return; }

        // Handle delete actions (single / selected / all)
        // delete_one uses button name at_delete_one (to avoid hidden field ambiguity)
        $posted_action = '';
        if(isset($_POST['at_delete_one'])){
            $posted_action = 'delete_one';
        } elseif(isset($_POST['at_action'])){
            $posted_action = sanitize_text_field($_POST['at_action']);
        }

        if($posted_action && in_array($posted_action, array('delete_one','delete_selected','delete_all'), true)){
            check_admin_referer('at_manage_results');
            $action = $posted_action;
            $deleted = 0;

            // Helper: build placeholders
            $ph = function($n){ return implode(',', array_fill(0, max(1,intval($n)), '%d')); };

            if($action==='delete_one'){
                $rid = isset($_POST['at_delete_one']) ? intval($_POST['at_delete_one']) : 0;
                if($rid>0){
                    $db->query($db->prepare("DELETE FROM {$t->answers} WHERE response_id=%d", $rid));
                    $deleted += (int)$db->query($db->prepare("DELETE FROM {$t->responses} WHERE id=%d AND run_id=%d", $rid, $run_id));
                }
            }
            if($action==='delete_selected'){
                $ids = isset($_POST['resp_ids']) && is_array($_POST['resp_ids']) ? array_map('intval', (array)$_POST['resp_ids']) : array();
                $ids = array_values(array_filter($ids));
                if($ids){
                    // Ensure ids belong to run
                    $in = $ph(count($ids));
                    $valid = $db->get_col($db->prepare("SELECT id FROM {$t->responses} WHERE run_id=%d AND id IN ($in)", array_merge(array($run_id), $ids)));
                    $valid = array_map('intval', (array)$valid);
                    if($valid){
                        $in2 = $ph(count($valid));
                        $db->query($db->prepare("DELETE FROM {$t->answers} WHERE response_id IN ($in2)", $valid));
                        $deleted += (int)$db->query($db->prepare("DELETE FROM {$t->responses} WHERE run_id=%d AND id IN ($in2)", array_merge(array($run_id), $valid)));
                    }
                }
            }
            if($action==='delete_all'){
                $all_ids = $db->get_col($db->prepare("SELECT id FROM {$t->responses} WHERE run_id=%d", $run_id));
                $all_ids = array_map('intval', (array)$all_ids);
                if($all_ids){
                    $in3 = $ph(count($all_ids));
                    $db->query($db->prepare("DELETE FROM {$t->answers} WHERE response_id IN ($in3)", $all_ids));
                }
                $deleted += (int)$db->query($db->prepare("DELETE FROM {$t->responses} WHERE run_id=%d", $run_id));
            }

            if($deleted>0){
                echo '<div class="notice notice-success"><p>Berhasil menghapus '.$deleted.' data hasil survey.</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>Tidak ada data yang dihapus.</p></div>';
            }
        }

        $qs = $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",$run->survey_id));

        // Import CSV edit hasil: validasi isi jawaban dimatikan; update hanya jika ada perubahan.
        $this->at_handle_results_import_csv($run, $qs);

        if($latest_only && $this->is_run_group($run)){
            $responses = $db->get_results($db->prepare("
                SELECT r1.* 
                FROM {$t->responses} r1
                INNER JOIN (
                    SELECT user_id, MAX(id) AS max_id
                    FROM {$t->responses}
                    WHERE run_id=%d AND ".($fill_status_filter==='all' ? "1=1" : $db->prepare("fill_status=%s", $fill_status_filter))." AND user_id IS NOT NULL AND user_id > 0
                    GROUP BY user_id
                ) x ON x.max_id = r1.id
                ORDER BY r1.id ASC
            ", $run_id));
        } else {
            if($fill_status_filter==='all'){
                $responses = $db->get_results($db->prepare("SELECT * FROM {$t->responses} WHERE run_id=%d ORDER BY id ASC",$run_id));
            } else {
                $responses = $db->get_results($db->prepare("SELECT * FROM {$t->responses} WHERE run_id=%d AND fill_status=%s ORDER BY id ASC",$run_id,$fill_status_filter));
            }
        }

        echo '<h2>'.($run->run_name ? $this->esc($run->run_name).' — ' : '').$this->esc($run->survey_title).' — '.$this->esc($run->unit_name).' / '.$run->year.'</h2>';
        echo '<p style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">'
            .'<a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page='.self::SLUG.'_results&export=1&run_id='.$run_id.'&latest_only='.$latest_only.'&fill_status='.$fill_status_filter.'&answer_display='.$answer_display)).'">Export CSV</a>'
            .'<span class="description">'.($latest_only ? 'Mode data: hanya isian terakhir per pengguna.' : 'Mode data: semua isian survey.').' Status: '.esc_html($fill_status_filter).'</span>'
            .'</p>';

        echo '<div class="postbox" style="padding:14px; margin:12px 0; max-width:980px;">';
        echo '<h3 style="margin-top:0;">Import Edit Hasil Survey dari CSV</h3>';
        echo '<p class="description">Gunakan file dari tombol <strong>Export CSV</strong>, ubah nilai pada kolom jawaban <code>Q{id}: ...</code>, lalu upload kembali. Validasi isi jawaban dimatikan; sistem hanya memastikan <code>response_id</code> sesuai run ini, membaca kolom pertanyaan, lalu mengupdate data yang berubah.</p>';
        echo '<form method="post" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
        wp_nonce_field('at_import_results_csv');
        echo '<input type="hidden" name="at_import_results_csv" value="1" />';
        echo '<input type="hidden" name="run_id" value="'.intval($run_id).'" />';
        echo '<input type="file" name="at_results_csv" accept=".csv,text/csv" required />';
        echo '<button type="submit" class="button button-primary">Import Edit CSV</button>';
        echo '</form>';
        echo '</div>';

        if(!$responses){ echo '<p><em>Belum ada respon.</em></p></div>'; return; }

        $id_cols = $this->get_result_identity_elements_for_run($run); // group-only; returns [] if not applicable

        // Bulk actions form
        echo '<form method="post" onsubmit="return confirm(this.dataset.confirmMsg || \'Lanjutkan?\');" data-confirm-msg="Lanjutkan proses hapus?">';
        wp_nonce_field('at_manage_results');
        echo '<input type="hidden" name="run_id" value="'.intval($run_id).'" />';

        echo '<p style="margin:10px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">'
            .'<button type="submit" class="button" name="at_action" value="delete_selected" onclick="this.form.dataset.confirmMsg=\'Hapus data yang dipilih?\'">Hapus Terpilih</button>'
            .'<button type="submit" class="button button-link-delete" name="at_action" value="delete_all" onclick="this.form.dataset.confirmMsg=\'Hapus SEMUA data hasil survey pada run ini?\'">Hapus Semua</button>'
            .'<span class="description">Gunakan checkbox untuk memilih beberapa data.</span>'
            .'</p>';

        echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr>'
            .'<th style="width:40px;"><input type="checkbox" id="at_check_all" onclick="document.querySelectorAll(\'input[name=\\"resp_ids[]\\"]\').forEach(function(cb){cb.checked=this.checked;}.bind(this));" /></th>'
            .'<th>Response ID</th><th>Status Pengisian</th><th>Waktu Mulai/Submit</th><th>Waktu Completed</th><th>IP</th><th>ID Unit Kerja</th><th>Nama Unit Kerja</th>';
        if($this->is_run_group($run)){
            echo '<th>Username</th>';
            foreach($id_cols as $el){ echo '<th>'.$this->esc($el->field_label).'</th>'; }
        }
        foreach($qs as $q){ echo '<th>'.$this->esc($q->question_text).'</th>'; }
        echo '<th style="min-width:140px;">Aksi</th>';
        echo '</tr></thead><tbody>';
        foreach($responses as $resp){
            $rid = intval($resp->id);
            $unit_id_val = '';
            $unit_name_val = '';
            if(intval($resp->user_id) > 0){
                $urow = $db->get_row($db->prepare("SELECT u.unit_id, un.name AS unit_name FROM {$t->users} u LEFT JOIN {$t->units} un ON un.id = u.unit_id WHERE u.id=%d", intval($resp->user_id)));
                if($urow){
                    $unit_id_val = isset($urow->unit_id) ? $urow->unit_id : '';
                    $unit_name_val = isset($urow->unit_name) ? $urow->unit_name : '';
                }
            }
            if($unit_id_val === '' && isset($run->unit_id)) $unit_id_val = $run->unit_id;
            if($unit_name_val === '' && isset($run->unit_name)) $unit_name_val = $run->unit_name;
            echo '<tr>'
                .'<td><input type="checkbox" name="resp_ids[]" value="'.$rid.'" /></td>'
                .'<td>'.$rid.'</td><td>'.$this->esc($resp->fill_status).'</td><td>'.$this->esc($resp->submitted_at).'</td><td>'.$this->esc($resp->completed_at).'</td><td>'.$this->esc($resp->ip_address).'</td><td>'.$this->esc($unit_id_val).'</td><td>'.$this->esc($unit_name_val).'</td>';
            if($this->is_run_group($run)){
                $uname = $db->get_var($db->prepare("SELECT username FROM {$t->users} WHERE id=%d", intval($resp->user_id)));
                echo '<td>'.$this->esc($uname).'</td>';
                foreach($id_cols as $el){
                    $v = $db->get_var($db->prepare("SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", intval($resp->user_id), intval($el->id)));
                    echo '<td>'.$this->esc($v).'</td>';
                }
            }
            foreach($qs as $q){
                $ans_row = $db->get_row($db->prepare("SELECT answer_text, file_url, file_name FROM {$t->answers} WHERE response_id=%d AND question_id=%d ORDER BY id DESC LIMIT 1",$resp->id,$q->id));
                $ans = $ans_row ? (string)$ans_row->answer_text : '';
                $cell = $this->esc($ans);
                if($ans_row && !empty($ans_row->file_url)){
                    $fn = !empty($ans_row->file_name) ? $ans_row->file_name : basename($ans_row->file_url);
                    $cell .= '<br><a href="'.esc_url($ans_row->file_url).'" target="_blank" rel="noopener">Buka bukti</a>';
                }
                echo '<td>'.$cell.'</td>';
            }
            $edit_url = admin_url('admin.php?page='.self::SLUG.'_results_edit&run_id='.$run_id.'&response_id='.$rid);
            echo '<td>'
                .'<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a> '
                .'<button type="submit" class="button button-small button-link-delete" name="at_delete_one" value="'.$rid.'" onclick="this.form.dataset.confirmMsg=\'Hapus data response ini?\'">Hapus</button>'
                .'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</form>';
        echo '</div>';
    }

	/**
	 * Admin: edit satu response (hasil survey) lalu simpan perubahan jawaban.
	 */
	function page_results_edit(){
		if( !current_user_can('manage_options') && !$this->is_data_processor() ) wp_die('Akses ditolak');
		global $wpdb; $t = $this->tables();

		$run_id = isset($_GET['run_id']) ? intval($_GET['run_id']) : 0;
		$rid    = isset($_GET['response_id']) ? intval($_GET['response_id']) : (isset($_GET['rid']) ? intval($_GET['rid']) : 0);

		if(!$run_id || !$rid){
			echo '<div class="wrap"><h1>Edit Hasil Survey</h1><p>Parameter tidak lengkap.</p></div>';
			return;
		}

		$run = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t->runs} WHERE id=%d", $run_id), ARRAY_A );
		$response = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t->responses} WHERE id=%d AND run_id=%d", $rid, $run_id), ARRAY_A );

		if(!$run || !$response){
			echo '<div class="wrap"><h1>Edit Hasil Survey</h1><p>Data tidak ditemukan.</p></div>';
			return;
		}

        if(!current_user_can('manage_options')){
            $processor_unit_id = $this->get_processor_unit_id();
            if(!$processor_unit_id || !$this->response_belongs_to_unit($rid, $processor_unit_id)){
                wp_die('Akses ditolak. Pengolah Data hanya dapat mengedit data pada unitnya sendiri.');
            }
        }

		$survey_id = intval($run['survey_id']);
		$survey = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t->surveys} WHERE id=%d", $survey_id), ARRAY_A );
		$questions = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC", $survey_id), ARRAY_A );

		
	if(isset($_POST['at_update']) && check_admin_referer('at_update_result')){
		// Update answers only. Admin edits raw values; we write them straight to DB.
		$posted = (isset($_POST['qraw']) && is_array($_POST['qraw'])) ? $_POST['qraw'] : array();

		foreach($questions as $q){
			$qid = (int)$q['id'];
			if(!array_key_exists($qid, $posted)) continue;

			$raw = trim( wp_unslash( (string)$posted[$qid] ) );


			// NOTE: Tabel `akurasitara_answers` pada plugin ini hanya menyimpan:
			// response_id, question_id, answer_text.
			// Jadi edit hasil survey cukup mengubah `answer_text` (tanpa kolom run_id/answer_value/other_text).
			// IMPORTANT: answers table historically has no unique constraint on (response_id, question_id).
			// Using REPLACE can therefore create duplicate rows. We delete first, then insert exactly one row.
			$wpdb->delete(
				$t->answers,
				array(
					'response_id' => $rid,
					'question_id' => $qid,
				),
				array('%d','%d')
			);

			$wpdb->insert(
				$t->answers,
				array(
					'response_id' => $rid,
					'question_id' => $qid,
					'answer_text' => $raw,
				),
				array('%d','%d','%s')
			);
		}

        // Setelah edit disimpan, cek ulang kelengkapan pertanyaan wajib sesuai alur conditional.
        // Jika semua pertanyaan wajib yang tampil sudah terisi, status menjadi completed; jika belum, tetap/menjadi in_progress.
        $new_status = $this->at_recheck_single_response_status($rid, $survey_id);
        $response = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t->responses} WHERE id=%d AND run_id=%d", $rid, $run_id), ARRAY_A );
        if($new_status === 'completed'){
            echo '<div class="updated"><p>Perubahan disimpan. Status pengisian otomatis diperbarui menjadi <strong>Completed</strong>.</p></div>';
        } else {
            echo '<div class="updated notice-warning"><p>Perubahan disimpan. Status pengisian masih <strong>In Progress</strong> karena masih ada pertanyaan wajib yang belum terisi.</p></div>';
        }
	}

		// Load answers (take newest row per question_id)
		// NOTE: answers table may contain duplicates (legacy, no unique constraint).
		$ans_rows = $wpdb->get_results(
			$wpdb->prepare("SELECT id, question_id, answer_text FROM {$t->answers} WHERE response_id=%d ORDER BY id DESC", $rid),
			ARRAY_A
		);
		$ans_map = array();
		foreach($ans_rows as $ar){
			$qid = intval($ar['question_id']);
			// Keep only the newest row per question_id
			if (!isset($ans_map[$qid])) {
				$ans_map[$qid] = $ar;
			}
		}

		echo '<div class="wrap">';
		echo '<h1>Edit Hasil Survey</h1>';
		echo '<p><strong>Run:</strong> '.esc_html($survey ? $survey['title'] : ('#'.$survey_id)).' &nbsp; | &nbsp; <strong>Response ID:</strong> '.intval($rid).'</p>';

		echo '<form method="post">';
		wp_nonce_field('at_update_result');
		echo '<input type="hidden" name="at_update" value="1" />';

        // Identitas pengisi (khusus survey pengguna tertentu / kelompok)
        $run_obj = (object)$run;
        if($this->is_run_group($run_obj)){
            $uid_resp = intval($response['user_id'] ?? 0);
            if($uid_resp>0){
                $id_cols = $this->get_result_identity_elements_for_run($run_obj);
                $uname = $wpdb->get_var($wpdb->prepare("SELECT username FROM {$t->users} WHERE id=%d", $uid_resp));
                echo '<h2 style="margin-top:18px;">Identitas Pengisi</h2>';
                echo '<table class="widefat striped" style="max-width:900px;">';
                echo '<tbody>';
                echo '<tr><th style="width:240px;">Username</th><td>'.esc_html($uname).'</td></tr>';
                foreach($id_cols as $el){
                    $val = $wpdb->get_var($wpdb->prepare(
                        "SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d",
                        $uid_resp,
                        intval($el->id)
                    ));
                    echo '<tr><th>'.esc_html($el->field_label).'</th><td>'.esc_html($val).'</td></tr>';
                }
                echo '</tbody></table>';
                echo '<p class="description">Catatan: identitas di atas bersifat tampilan saja dan tidak dapat diubah dari halaman edit hasil.</p>';
            }
        }

foreach($questions as $q){
	$qid = intval($q['id']);
	$label = isset($q['question_text']) ? (string)$q['question_text'] : (isset($q['label']) ? (string)$q['label'] : ('Q'.$qid));

		// Current display value: gunakan answer_text (karena memang hanya itu yang disimpan).
		$cur = '';
		if(isset($ans_map[$qid]) && is_array($ans_map[$qid])){
			$cur = trim(isset($ans_map[$qid]['answer_text']) ? (string)$ans_map[$qid]['answer_text'] : '');
		}
			// Normalize legacy duplicated values like "20,20" or "20, 20" into "20".
			if($cur !== '' && strpos($cur, ',') !== false){
				$parts = array_values(array_filter(array_map('trim', explode(',', $cur)), 'strlen'));
				if(count($parts) >= 2){
					$uniq = array_values(array_unique($parts));
					if(count($uniq) === 1){
						$cur = $uniq[0];
					}
				}
			}

	echo '<div style="margin:12px 0; padding:12px; background:#fff; border:1px solid #ddd;">';
	echo '<label><strong>'.esc_html($label).'</strong></label><br/>';
	echo '<input type="text" class="regular-text" name="qraw['.$qid.']" value="'.esc_attr($cur).'" style="min-width:320px" />';
	echo '</div>';
}

echo '<p><button class="button button-primary" type="submit">Simpan Perubahan</button> ';
		echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=akurasitara_results&run_id='.$run_id)).'">Kembali</a></p>';
		echo '</form>';
		echo '</div>';
	}


    private function get_resume_response_id($run, $explicit_rid = 0){
        $db = $this->db(); $t = $this->tables();
        $run_id = intval($run->id);
        $user_id = $this->user_id_get_current();

        if($explicit_rid > 0){
            $row = $db->get_row($db->prepare("SELECT id, user_id, fill_status FROM {$t->responses} WHERE id=%d AND run_id=%d", $explicit_rid, $run_id));
            if($row && (string)$row->fill_status !== 'completed'){
                if($this->is_run_group($run)){
                    if($user_id > 0 && intval($row->user_id) === intval($user_id)) return intval($row->id);
                    return 0;
                }
                return intval($row->id);
            }
            return 0;
        }

        if($this->is_run_group($run) && $user_id > 0){
            return intval($db->get_var($db->prepare(
                "SELECT id FROM {$t->responses} WHERE run_id=%d AND user_id=%d AND fill_status='in_progress' ORDER BY id DESC LIMIT 1",
                $run_id, $user_id
            )));
        }

        $cookie_key = 'akurasitara_rid_' . $run_id;
        $cookie_rid = isset($_COOKIE[$cookie_key]) ? intval($_COOKIE[$cookie_key]) : 0;
        if($cookie_rid > 0){
            return intval($db->get_var($db->prepare(
                "SELECT id FROM {$t->responses} WHERE id=%d AND run_id=%d AND fill_status='in_progress' ORDER BY id DESC LIMIT 1",
                $cookie_rid, $run_id
            )));
        }
        return 0;
    }

    private function get_response_page_index($survey_id, $response_id, $sections){
        $response_id = intval($response_id);
        if($response_id <= 0 || empty($sections)) return 1;
        $db = $this->db(); $t = $this->tables();
        $answered = $db->get_col($db->prepare("SELECT question_id FROM {$t->answers} WHERE response_id=%d AND answer_text IS NOT NULL AND TRIM(answer_text)<>''", $response_id));
        if(!$answered) return 1;
        $answered = array_map('intval', $answered);
        $last = 1;
        foreach($sections as $idx=>$sec){
            $qids = isset($sec['qids']) ? array_map('intval', $sec['qids']) : array();
            if(array_intersect($answered, $qids)) $last = intval($idx) + 1;
        }
        $next = min(count($sections), $last + 1);
        return max(1, $next);
    }


    private function at_answer_is_filled($value){
        if(is_array($value)){
            foreach($value as $v){ if($this->at_answer_is_filled($v)) return true; }
            return false;
        }
        $value = trim((string)$value);
        // Nilai "0" tetap dianggap jawaban valid.
        return $value !== '';
    }

    private function at_conditional_match($answer, $operator, $expected){
        $answer = trim((string)$answer);
        $operator = trim((string)$operator);
        $expected = trim((string)$expected);
        $answer_parts = array_values(array_filter(array_map('trim', explode(',', $answer)), function($v){ return $v !== ''; }));
        $expected_parts = array_values(array_filter(array_map('trim', explode(',', $expected)), function($v){ return $v !== ''; }));

        if($operator === 'filled') return $this->at_answer_is_filled($answer);
        if($operator === 'empty')  return !$this->at_answer_is_filled($answer);
        if($operator === 'eq')     return $answer === $expected;
        if($operator === 'neq')    return $answer !== $expected;
        if($operator === 'in'){
            if(!$expected_parts) return false;
            if($answer_parts){
                foreach($answer_parts as $ap){ if(in_array($ap, $expected_parts, true)) return true; }
                return false;
            }
            return in_array($answer, $expected_parts, true);
        }
        if($operator === 'not_in'){
            if(!$expected_parts) return true;
            if($answer_parts){
                foreach($answer_parts as $ap){ if(in_array($ap, $expected_parts, true)) return false; }
                return true;
            }
            return !in_array($answer, $expected_parts, true);
        }
        return true;
    }

    private function at_question_visible_by_flow($question, $answers, $visibility_map = array()){
        $parent_id = isset($question->cond_parent_id) ? intval($question->cond_parent_id) : 0;
        $operator  = isset($question->cond_operator) ? trim((string)$question->cond_operator) : '';
        if($parent_id <= 0 || $operator === '') return true;

        // Jika pertanyaan pemicu sendiri tersembunyi, pertanyaan turunannya juga dianggap tidak aktif dalam alur.
        if(isset($visibility_map[$parent_id]) && !$visibility_map[$parent_id]) return false;

        $parent_answer = array_key_exists($parent_id, $answers) ? (string)$answers[$parent_id] : '';
        $expected = isset($question->cond_value) ? (string)$question->cond_value : '';
        $matched = $this->at_conditional_match($parent_answer, $operator, $expected);
        $action = isset($question->cond_action) ? trim((string)$question->cond_action) : 'show';
        if($action === 'hide') return !$matched;
        return $matched;
    }


    private function at_normalize_submitted_answer_value($qid){
        $qid = intval($qid);
        $key = 'q_'.$qid;
        $val = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        if(is_array($val)){
            $val = implode(',', array_map('sanitize_text_field', $val));
        } else {
            $val = sanitize_text_field($val);
        }

        // Support "Lainnya" consistently with the save routine.
        $okey = $key.'_other';
        $oval = isset($_POST[$okey]) ? sanitize_text_field(wp_unslash($_POST[$okey])) : '';
        if($val === '__other__'){
            $val = $oval;
        } elseif($oval !== '' && strpos(','.$val.',', ',__other__,') !== false){
            $parts = array_filter(array_map('trim', explode(',', $val)));
            $parts = array_values(array_diff($parts, array('__other__')));
            $parts[] = $oval;
            $val = implode(',', $parts);
        } elseif(strpos(','.$val.',', ',__other__,') !== false){
            $parts = array_filter(array_map('trim', explode(',', $val)));
            $parts = array_values(array_diff($parts, array('__other__')));
            $val = implode(',', $parts);
        }
        return $val;
    }



    private function at_question_requires_file($q){
        return (isset($q->requires_file) && intval($q->requires_file) === 1);
    }

    private function at_evidence_link_key($qid){
        return 'qlink_'.intval($qid);
    }

    private function at_submitted_evidence_link_raw($qid){
        $key = $this->at_evidence_link_key($qid);
        if(!isset($_POST[$key])) return null;
        return trim((string)wp_unslash($_POST[$key]));
    }

    private function at_evidence_link_is_valid($url){
        $url = trim((string)$url);
        if($url === '') return false;
        $parts = wp_parse_url($url);
        if(empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http','https'), true)) return false;
        if(empty($parts['host'])) return false;
        return true;
    }

    private function at_handle_evidence_link($qid){
        $raw = $this->at_submitted_evidence_link_raw($qid);
        if($raw === null) return null;
        if($raw === ''){
            return array('file_url'=>'', 'file_path'=>null, 'file_name'=>null, 'file_mime'=>null, 'file_size'=>null);
        }
        $url = esc_url_raw($raw, array('http','https'));
        if(!$this->at_evidence_link_is_valid($url)){
            wp_die('Link bukti tidak valid. Gunakan URL lengkap yang diawali http:// atau https://.', 'Link bukti tidak valid', array('back_link'=>true));
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        return array(
            'file_url'  => $url,
            'file_path' => null,
            'file_name' => $host ? sanitize_text_field($host) : 'Link bukti',
            'file_mime' => 'text/uri-list',
            'file_size' => null,
        );
    }

    private function at_ensure_secure_evidence_upload_dir(){
        $uploads = wp_upload_dir();
        if(!empty($uploads['error']) || empty($uploads['basedir'])) return false;
        $dir = trailingslashit($uploads['basedir']) . 'akurasitara-evidence';
        if(!file_exists($dir)){
            wp_mkdir_p($dir);
        }
        if(!is_dir($dir) || !is_writable($dir)) return false;

        // Apache/LiteSpeed: cegah eksekusi script dan cegah directory listing.
        $htaccess = <<<HTACCESS
Options -Indexes

<FilesMatch "\.(php|php[0-9]*|phtml|phar|cgi|pl|py|rb|asp|aspx|jsp|sh|shtml)$">
    Require all denied
</FilesMatch>

RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py .rb .asp .aspx .jsp .sh .shtml
RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py .rb .asp .aspx .jsp .sh .shtml
HTACCESS;
        $htaccess_path = trailingslashit($dir) . '.htaccess';
        if(!file_exists($htaccess_path) || trim((string)@file_get_contents($htaccess_path)) !== trim($htaccess)){
            @file_put_contents($htaccess_path, $htaccess);
        }

        // IIS fallback.
        $webconfig = <<<'WEBCONFIG'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <directoryBrowse enabled="false" />
    <security>
      <requestFiltering>
        <fileExtensions allowUnlisted="true">
          <add fileExtension=".php" allowed="false" />
          <add fileExtension=".php3" allowed="false" />
          <add fileExtension=".php4" allowed="false" />
          <add fileExtension=".php5" allowed="false" />
          <add fileExtension=".php7" allowed="false" />
          <add fileExtension=".php8" allowed="false" />
          <add fileExtension=".phtml" allowed="false" />
          <add fileExtension=".phar" allowed="false" />
          <add fileExtension=".cgi" allowed="false" />
          <add fileExtension=".pl" allowed="false" />
          <add fileExtension=".py" allowed="false" />
          <add fileExtension=".rb" allowed="false" />
          <add fileExtension=".asp" allowed="false" />
          <add fileExtension=".aspx" allowed="false" />
          <add fileExtension=".jsp" allowed="false" />
          <add fileExtension=".sh" allowed="false" />
          <add fileExtension=".shtml" allowed="false" />
        </fileExtensions>
      </requestFiltering>
    </security>
  </system.webServer>
</configuration>
WEBCONFIG;
        $webconfig_path = trailingslashit($dir) . 'web.config';
        if(!file_exists($webconfig_path) || trim((string)@file_get_contents($webconfig_path)) !== trim($webconfig)){
            @file_put_contents($webconfig_path, $webconfig);
        }

        $index_path = trailingslashit($dir) . 'index.html';
        if(!file_exists($index_path)){
            @file_put_contents($index_path, '');
        }
        return true;
    }

    public function at_evidence_upload_dir($dirs){
        $sub = '/akurasitara-evidence';
        $dirs['subdir'] = $dirs['subdir'] . $sub;
        $dirs['path']   = $dirs['path'] . $sub;
        $dirs['url']    = $dirs['url'] . $sub;
        return $dirs;
    }

    private function at_get_missing_required_questions_for_submission($survey_id, $response_id, $current_qids, $check_all=false){
        $db=$this->db(); $t=$this->tables();
        $survey_id = intval($survey_id);
        $response_id = intval($response_id);
        $current_qids = array_values(array_unique(array_filter(array_map('intval', (array)$current_qids))));
        $current_lookup = array();
        foreach($current_qids as $qid){ $current_lookup[$qid] = true; }
        if($survey_id <= 0) return array();

        $questions = $db->get_results($db->prepare(
            "SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ));
        if(!$questions) return array();

        $answers = array();
        $answer_files = array();
        if($response_id > 0){
            $ans_rows = $db->get_results($db->prepare(
                "SELECT question_id, answer_text, file_url FROM {$t->answers} WHERE response_id=%d",
                $response_id
            ));
            if($ans_rows){
                foreach($ans_rows as $ar){
                    $qid_ar = intval($ar->question_id);
                    $answers[$qid_ar] = (string)$ar->answer_text;
                    $answer_files[$qid_ar] = (string)($ar->file_url ?? '');
                }
            }
        }

        // The current page POST is the source of truth, including intentionally cleared answers.
        foreach($current_qids as $qid){
            $answers[$qid] = $this->at_normalize_submitted_answer_value($qid);
        }

        $missing = array();
        $visible = array();
        foreach($questions as $q){
            $qid = intval($q->id);
            if((string)$q->qtype === 'label'){
                $visible[$qid] = true;
                continue;
            }
            $is_visible = $this->at_question_visible_by_flow($q, $answers, $visible);
            $visible[$qid] = $is_visible;

            $must_check = $check_all ? true : isset($current_lookup[$qid]);
            if($must_check && $is_visible){
                $requires_file = $this->at_question_requires_file($q);
                $must_answer = (intval($q->is_required) === 1) || $requires_file;
                if($must_answer){
                    $val = array_key_exists($qid, $answers) ? $answers[$qid] : '';
                    if(!$this->at_answer_is_filled($val)){
                        $missing[] = array(
                            'id' => $qid,
                            'text' => (string)$q->question_text,
                        );
                    }
                }
                if($requires_file){
                    $posted_link = isset($current_lookup[$qid]) ? $this->at_submitted_evidence_link_raw($qid) : null;
                    if($posted_link !== null){
                        $has_link = trim((string)$posted_link) !== '';
                    }else{
                        $has_link = !empty($answer_files[$qid]);
                    }
                    if(!$has_link){
                        $missing[] = array(
                            'id' => $qid,
                            'text' => 'Link bukti: '.(string)$q->question_text,
                        );
                    }
                }
            }
        }
        return $missing;
    }

    private function at_block_submission_if_required_missing($missing){
        if(empty($missing)) return;
        $items = array();
        foreach($missing as $m){
            $items[] = '<li>'.esc_html((string)$m['text']).'</li>';
        }
        $msg = '<p>Jawaban belum dapat dilanjutkan/dikirim karena masih ada pertanyaan wajib yang belum diisi.</p>'
             . '<p>Silakan kembali ke halaman survey dan lengkapi pertanyaan berikut:</p>'
             . '<ul style="list-style:disc;margin-left:20px">'.implode('', $items).'</ul>';
        wp_die($msg, 'Pertanyaan wajib belum lengkap', array('back_link'=>true));
    }

    private function at_get_unanswered_required_questions_by_flow($response_id, $survey_id){
        $db=$this->db(); $t=$this->tables();
        $response_id = intval($response_id); $survey_id = intval($survey_id);
        if($response_id <= 0 || $survey_id <= 0) return array();

        $questions = $db->get_results($db->prepare(
            "SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ));
        if(!$questions) return array();

        $ans_rows = $db->get_results($db->prepare(
            "SELECT question_id, answer_text, file_url FROM {$t->answers} WHERE response_id=%d",
            $response_id
        ));
        $answers = array();
        $answer_files = array();
        if($ans_rows){
            foreach($ans_rows as $ar){
                $qid_ar = intval($ar->question_id);
                $answers[$qid_ar] = (string)$ar->answer_text;
                $answer_files[$qid_ar] = (string)($ar->file_url ?? '');
            }
        }

        $missing = array();
        $visible = array();
        foreach($questions as $q){
            $qid = intval($q->id);
            if((string)$q->qtype === 'label'){
                $visible[$qid] = true;
                continue;
            }

            $is_visible = $this->at_question_visible_by_flow($q, $answers, $visible);
            $visible[$qid] = $is_visible;

            if($is_visible){
                $requires_file = $this->at_question_requires_file($q);
                $must_answer = (intval($q->is_required) === 1) || $requires_file;
                if($must_answer){
                    $val = array_key_exists($qid, $answers) ? $answers[$qid] : '';
                    if(!$this->at_answer_is_filled($val)){
                        $missing[] = array(
                            'id' => $qid,
                            'text' => (string)$q->question_text,
                        );
                    }
                }
                if($requires_file && empty($answer_files[$qid])){
                    $missing[] = array(
                        'id' => $qid,
                        'text' => 'Link bukti: '.(string)$q->question_text,
                    );
                }
            }
        }
        return $missing;
    }

    private function at_response_missing_required_by_flow($response_id, $survey_id){
        return !empty($this->at_get_unanswered_required_questions_by_flow($response_id, $survey_id));
    }

    private function at_recheck_single_response_status($response_id, $survey_id){
        $db=$this->db(); $t=$this->tables();
        $response_id = intval($response_id);
        $survey_id = intval($survey_id);
        if($response_id <= 0 || $survey_id <= 0) return 'in_progress';

        $missing = $this->at_get_unanswered_required_questions_by_flow($response_id, $survey_id);
        if(empty($missing)){
            $db->update(
                $t->responses,
                array(
                    'fill_status'  => 'completed',
                    'completed_at' => current_time('mysql'),
                    'submitted_at' => current_time('mysql'),
                ),
                array('id'=>$response_id),
                array('%s','%s','%s'),
                array('%d')
            );
            return 'completed';
        }

        $db->update(
            $t->responses,
            array(
                'fill_status'  => 'in_progress',
                'completed_at' => null,
            ),
            array('id'=>$response_id),
            array('%s','%s'),
            array('%d')
        );
        return 'in_progress';
    }

    private function at_recheck_latest_user_responses($run_id = 0){
        $db=$this->db(); $t=$this->tables();
        $run_id = intval($run_id);

        // Ambil hanya isian TERAKHIR per responden pada pelaksanaan survey yang sama.
        // Ini mencegah status lama (misalnya in_progress) mengalahkan isian terbaru yang sudah lengkap.
        // Untuk survey umum/anonymous yang tidak memiliki user_id, setiap response tetap dicek satu per satu.
        if($run_id > 0){
            $responses = $db->get_results($db->prepare(
                "SELECT r.id, r.survey_id, r.fill_status
                 FROM {$t->responses} r
                 INNER JOIN (
                    SELECT user_id, run_id, MAX(id) AS latest_id
                    FROM {$t->responses}
                    WHERE run_id=%d AND user_id IS NOT NULL AND user_id > 0
                    GROUP BY user_id, run_id
                 ) x ON x.latest_id = r.id
                 UNION ALL
                 SELECT r2.id, r2.survey_id, r2.fill_status
                 FROM {$t->responses} r2
                 WHERE r2.run_id=%d AND (r2.user_id IS NULL OR r2.user_id = 0)
                 ORDER BY id ASC",
                $run_id,
                $run_id
            ));
        } else {
            $responses = $db->get_results(
                "SELECT r.id, r.survey_id, r.fill_status
                 FROM {$t->responses} r
                 INNER JOIN (
                    SELECT user_id, run_id, MAX(id) AS latest_id
                    FROM {$t->responses}
                    WHERE user_id IS NOT NULL AND user_id > 0
                    GROUP BY user_id, run_id
                 ) x ON x.latest_id = r.id
                 UNION ALL
                 SELECT r2.id, r2.survey_id, r2.fill_status
                 FROM {$t->responses} r2
                 WHERE r2.user_id IS NULL OR r2.user_id = 0
                 ORDER BY id ASC"
            );
        }

        $checked = 0; $changed = 0; $completed = 0; $in_progress = 0;
        if($responses){
            foreach($responses as $resp){
                $checked++;
                $old_status = (string)($resp->fill_status ?? '');
                $new_status = $this->at_recheck_single_response_status(intval($resp->id), intval($resp->survey_id));
                if($new_status === 'completed') $completed++;
                else $in_progress++;
                if($old_status !== $new_status) $changed++;
            }
        }
        return array('checked'=>$checked, 'changed'=>$changed, 'completed'=>$completed, 'in_progress'=>$in_progress);
    }

    // Backward compatibility untuk pemanggilan lama.
    private function at_recheck_old_completed_responses($run_id = 0){
        return $this->at_recheck_latest_user_responses($run_id);
    }

function shortcode_render_form($atts){
        $at_debug = (isset($_GET['at_debug']) && $_GET['at_debug']=='1');
        if($at_debug){ @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); @error_reporting(E_ALL); }
        // Front-end styling: keep the survey form looking consistent regardless of WP theme.
        if(function_exists('wp_enqueue_style')){
            wp_enqueue_style(
                'akurasitara-form',
                plugins_url('assets/akurasitara-form.css', __FILE__),
                array(),
                '1.9.3'
            );
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $atts   = shortcode_atts(array('id'=>0), $atts, 'akurasitara_survey');
            $run_id = intval($atts['id']);
            return '<div class="akurasitara-preview">[AkurasiTara] Form Survey (Run ID: '.esc_html($run_id).')</div>';
        }
        $atts = shortcode_atts(array('id'=>0), $atts, 'akurasitara_survey');
        $run_id = intval($atts['id']);
        if(!$run_id) return '<div class="akurasitara-error">Run ID tidak valid.</div>';

        $db=$this->db(); $t=$this->tables();
        $run = $db->get_row($db->prepare("SELECT r.*, s.title, s.description FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id WHERE r.id=%d",$run_id));
        if(!$run) return '<div class="akurasitara-error">Pelaksanaan tidak ditemukan.</div>';
        $reason='';
        if(!$this->run_is_currently_open($run, $reason)){
            return '<div class="akurasitara-info">'.$this->esc($reason).'</div>';
        }
        if(isset($_GET['at_thanks']) && $_GET['at_thanks']=='1'){ return '<div class="akurasitara-info">Terima kasih, jawaban Anda telah selesai dikirim.</div>'; }
        if(!$this->require_password($run)) return '';

        // IMPORTANT: If this run is restricted to a user group, force login BEFORE showing the survey form.
        // Without this, the form would still render and only fail silently on submit.
        if(!$this->require_group_login($run)) return '';

        // After login, user must confirm identity (and may edit fields marked editable)
        if(!$this->require_identity_confirmation($run)) return '';

        $questions=$db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC",$run->survey_id));
        if(!$questions) return '<div class="akurasitara-info">Belum ada pertanyaan.</div>';

        $sections = array();
        $current  = array('title'=> '', 'q'=> array(), 'qids'=> array());
        foreach($questions as $q){
            if($q->qtype==='label'){
                if(!empty($current['q'])){ $sections[] = $current; }
                $current = array('title'=> (string)$q->question_text, 'q'=> array(), 'qids'=> array());
                continue;
            }
            $current['q'][] = $q;
            $current['qids'][] = $q->id;
        }
        if(!empty($current['q'])){ $sections[] = $current; }
        if(!$sections){ return '<div class="akurasitara-info">Tidak ada pertanyaan untuk diisi.</div>'; }

        $total_pages = count($sections);
        $page_idx = isset($_GET['at_page']) ? max(1, intval($_GET['at_page'])) : 1;
        if($page_idx > $total_pages) { $page_idx = $total_pages; }
        $explicit_rid = isset($_GET['rid']) ? intval($_GET['rid']) : 0;
        $response_id = $this->get_resume_response_id($run, $explicit_rid);
        if(!$explicit_rid && $response_id > 0 && !isset($_GET['at_page'])){
            $page_idx = $this->get_response_page_index($run->survey_id, $response_id, $sections);
        }
        if($page_idx > $total_pages) { $page_idx = $total_pages; }
        $sec = $sections[$page_idx-1];

        // Prefill existing answers when navigating between pages (rid)
        $ans_map = array();
        if($response_id && !empty($sec['qids'])){
            $placeholders = implode(',', array_fill(0, count($sec['qids']), '%d'));
            $sql = "SELECT question_id, answer_text, file_url, file_name FROM {$t->answers} WHERE response_id=%d AND question_id IN ($placeholders)";
            $params = array_merge(array($response_id), array_map('intval', $sec['qids']));
            $prepared = call_user_func_array(array($db,'prepare'), array_merge(array($sql), $params));
            $rows = $db->get_results($prepared);
            if($rows){
                foreach($rows as $r){ $ans_map[intval($r->question_id)] = array('answer_text'=>(string)$r->answer_text, 'file_url'=>(string)($r->file_url ?? ''), 'file_name'=>(string)($r->file_name ?? '')); }
            }
        }


        ob_start();
        echo '<div class="akurasitara-survey at-scope">';
        echo '<div class="at-card">';
        echo '<div class="at-head">';
        echo '<div class="at-title">'.$this->esc($run->title).'</div>';
        if(!empty($run->description)){
            echo '<div class="at-desc">'.$this->esc($run->description).'</div>';
        }
        echo '<div class="at-progress" aria-label="Progress">';
        echo '<span class="at-progress__pill">Bagian '.intval($page_idx).' / '.intval($total_pages).'</span>';
        if($response_id){ echo '<span class="at-progress__pill">Status: in progress</span>'; }
        echo '</div>';
        echo '</div>'; // head

        echo '<div class="akurasitara-section at-section">';
        echo '<div class="at-section__title">'.($sec['title']!=='' ? $this->esc($sec['title']) : 'Bagian '.$page_idx).'</div>';
        echo '<form method="post" class="at-form" novalidate>'; wp_nonce_field('at_submit');
        // Preserve unlock / user token across paging & submit.
        // NOTE: Do not overwrite $unlock with an empty value when query param is missing.
        $unlock = isset($_GET['at_unlock']) ? sanitize_text_field( wp_unslash($_GET['at_unlock']) ) : $this->get_current_unlock();
        if($unlock!==''){ echo '<input type="hidden" name="at_unlock" value="'.esc_attr($unlock).'"/>'; }
        $utok = isset($_GET['at_user_token']) ? sanitize_text_field( wp_unslash($_GET['at_user_token']) ) : $this->user_token_get_current();
        if($utok!==''){ echo '<input type="hidden" name="at_user_token" value="'.esc_attr($utok).'"/>'; }

        // Carry explicit current URL to avoid missing referer
        $scheme = is_ssl() ? 'https://' : 'http://';
        $req_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $cur_url = esc_url_raw($scheme.$host.$req_uri);
        // Extra query args we must carry when generating Back/Next links.
        $extra = array();
        if($unlock!=='') $extra['at_unlock'] = $unlock;
        if($utok!=='')   $extra['at_user_token'] = $utok;
        if(isset($_GET['at_debug'])){
            $extra['at_debug'] = sanitize_text_field( wp_unslash($_GET['at_debug']) );
        }
        echo '<input type="hidden" name="return_to" value="'.esc_attr($cur_url).'"/>';

        echo '<input type="hidden" name="at_submit" value="1"/>';
        echo '<input type="hidden" name="run_id" value="'.intval($run_id).'"/>';
        echo '<input type="hidden" name="page_idx" value="'.intval($page_idx).'"/>';
        echo '<input type="hidden" name="total_pages" value="'.intval($total_pages).'"/>';
        echo '<input type="hidden" name="section_qids" value="'.esc_attr(implode(',', $sec['qids'])).'"/>';
        if($response_id){ echo '<input type="hidden" name="response_id" value="'.intval($response_id).'"/>'; }

        foreach($sec['q'] as $q){
            $name = 'q_'.$q->id;
            $req  = $q->is_required ? 'required' : '';
            $ans_item = isset($ans_map[intval($q->id)]) ? $ans_map[intval($q->id)] : null;
            $cur  = is_array($ans_item) ? (string)($ans_item['answer_text'] ?? '') : (string)($ans_item ?? '');
            $cur_file_url = is_array($ans_item) ? (string)($ans_item['file_url'] ?? '') : '';
            $cur_file_name = is_array($ans_item) ? (string)($ans_item['file_name'] ?? '') : '';
            $cond_parent_id = isset($q->cond_parent_id) ? intval($q->cond_parent_id) : 0;
            $cond_op   = isset($q->cond_operator) ? (string)$q->cond_operator : '';
            $cond_val  = isset($q->cond_value) ? (string)$q->cond_value : '';
            $cond_act  = isset($q->cond_action) ? (string)$q->cond_action : '';
            $cond_attr = '';
            if($cond_parent_id>0 && $cond_op!==''){
                $cond_attr = ' data-cond-parent="'.esc_attr($cond_parent_id).'" data-cond-op="'.esc_attr($cond_op).'" data-cond-val="'.esc_attr($cond_val).'" data-cond-action="'.esc_attr($cond_act).'"';
            }
            $evidence_attr = (isset($q->requires_file) && intval($q->requires_file)===1) ? ' data-evidence-required="1"' : '';
            echo '<div class="akurasitara-q at-field" id="at_qwrap_'.intval($q->id).'" data-qid="'.intval($q->id).'"'.$cond_attr.$evidence_attr.'>';
            echo '<label class="at-label">'.$this->esc($q->question_text);
            if($q->is_required || (isset($q->requires_file) && intval($q->requires_file)===1)){ echo ' <span class="at-required" aria-hidden="true">*</span>'; }
            echo '</label>';
            switch($q->qtype){
                case 'textarea':
                    echo '<textarea class="at-control" data-qid="'.intval($q->id).'" name="'.esc_attr($name).'" rows="4" '.$req.'>'.esc_textarea($cur).'</textarea>'; break;
                case 'number':
                    // HTML5 numeric input (server-side validation will still apply)
                    echo '<input class="at-control" data-qid="'.intval($q->id).'" type="number" inputmode="numeric" name="'.esc_attr($name).'" value="'.esc_attr($cur).'" '.$req.' />';
                    break;
                case 'date':
                    // HTML5 date picker (YYYY-MM-DD)
                    echo '<input class="at-control" data-qid="'.intval($q->id).'" type="date" name="'.esc_attr($name).'" value="'.esc_attr($cur).'" '.$req.' />';
                    break;
				case 'checkbox':
					$pairs=$this->parse_options_pairs($q->options_csv);
					$vals = array(); foreach($pairs as $pp){ $vals[] = (string)$pp['value']; }
					$cur_parts = ($cur!=='') ? array_values(array_filter(array_map('trim', explode(',', $cur)))) : array();
					$other_text = '';
					foreach($cur_parts as $cp){ if($cp!=='' && !in_array($cp, $vals, true)){ $other_text = $cp; break; } }
					$cur_has_other = ($other_text!=='');
					$has_other_opt = false;
					foreach($pairs as $pp){ if(isset($pp['value']) && (string)$pp['value']==='__other__'){ $has_other_opt = true; break; } }
					$i=0;
					echo '<div class="at-options" role="group" aria-label="'.esc_attr($q->question_text).'">';
						foreach($pairs as $p){
							$req_one = ($req && $i===0) ? 'required' : '';
							// Tampilkan hanya label (value tetap disimpan di database).
							echo '<label class="at-option"><input data-qid="'.intval($q->id).'" type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($p['value']).'" '.(in_array((string)$p['value'], $cur_parts, true) ? 'checked ' : '').$req_one.'> <span class="at-option__text">'.$this->esc($p['label']).'</span></label>';
							$i++;
						}
					
					if((isset($q->allow_other) && intval($q->allow_other)===1) && !$has_other_opt){
						$has_other_opt = true;
						$req_one = ($req && $i===0) ? 'required' : '';
							echo '<label class="at-option"><input data-qid="'.intval($q->id).'" type="checkbox" name="'.esc_attr($name).'[]" value="__other__" '.($cur_has_other ? 'checked ' : '').$req_one.'> <span class="at-option__text">Lainnya</span></label>';
					}
					echo '</div>';
						if($has_other_opt){
                            echo '<div class="at-other" id="at_other_wrap_'.intval($q->id).'" style="display:none;">'
                                .'<input class="at-control" data-qid="'.intval($q->id).'" data-at-other-input="1" type="text" name="'.esc_attr($name).'_other" placeholder="Tulis jawaban lainnya" value="'.esc_attr($cur_has_other ? $other_text : '').'" '.($cur_has_other ? '' : 'disabled').' />'
                                .'</div>';
                        }
break;
                case 'radio':
                    $pairs=$this->parse_options_pairs($q->options_csv);
                    $vals = array(); foreach($pairs as $pp){ $vals[] = (string)$pp['value']; }
                    $cur_is_other = ($cur!=='' && !in_array($cur, $vals, true));
	                    $other_text = $cur_is_other ? $cur : '';
	                    $cur_has_other = ($cur_is_other || $cur==='__other__');
                    // If admin manually adds an option with value "__other__", enable other-text UI too.
                    $has_other_opt = false;
                    foreach($pairs as $pp){ if(isset($pp['value']) && (string)$pp['value']==='__other__'){ $has_other_opt = true; break; } }
                    echo '<div class="at-options" role="radiogroup" aria-label="'.esc_attr($q->question_text).'">';
						foreach($pairs as $p){
							// Tampilkan hanya label (value tetap disimpan di database).
							echo '<label class="at-option"><input data-qid="'.intval($q->id).'" type="radio" name="'.esc_attr($name).'" value="'.esc_attr($p['value']).'" '.((string)$p['value']===$cur ? 'checked ' : '').$req.'> <span class="at-option__text">'.$this->esc($p['label']).'</span></label>';
						}
                    if((isset($q->allow_other) && intval($q->allow_other)===1) && !$has_other_opt){
                        $has_other_opt = true;
							echo '<label class="at-option"><input data-qid="'.intval($q->id).'" type="radio" name="'.esc_attr($name).'" value="__other__" '.(($cur_is_other || $cur==='__other__') ? 'checked ' : '').$req.'> <span class="at-option__text">Lainnya</span></label>';
                    }
                    echo '</div>';
                    if($has_other_opt){
                        echo '<div class="at-other" id="at_other_wrap_'.intval($q->id).'" style="display:none;">
                                <input class="at-control" data-qid="'.intval($q->id).'" data-at-other-input="1" type="text" name="'.esc_attr($name).'_other" placeholder="Tulis jawaban lainnya" value="'.esc_attr($other_text).'" '.($cur_has_other ? '' : 'disabled').'>
                              </div>';
                    }
                    break;
                case 'select':
                    $pairs=$this->parse_options_pairs($q->options_csv);
                    $vals = array(); foreach($pairs as $pp){ $vals[] = (string)$pp['value']; }
                    $cur_is_other = ($cur!=='' && !in_array($cur, $vals, true));
                    $has_other_opt = false;
                    foreach($pairs as $pp){ if(isset($pp['value']) && (string)$pp['value']==='__other__'){ $has_other_opt = true; break; } }
                    echo '<select class="at-control" data-qid="'.intval($q->id).'" name="'.esc_attr($name).'" '.$req.'><option value="">-- pilih --</option>';
						foreach($pairs as $p){
							// Tampilkan hanya label (value tetap disimpan di database).
							echo '<option value="'.esc_attr($p['value']).'" '.((string)$p['value']===$cur ? 'selected' : '').'>'.$this->esc($p['label']).'</option>';
						}
                    if((isset($q->allow_other) && intval($q->allow_other)===1) && !$has_other_opt){
                        $has_other_opt = true;
                        echo '<option value="__other__" '.(($cur_is_other || $cur==='__other__') ? 'selected' : '').'>Lainnya</option>';
                    }
                    echo '</select>';
                    if($has_other_opt){
                        echo '<div class="at-other" id="at_other_wrap_'.intval($q->id).'" style="display:none;">
                                <input class="at-control" data-qid="'.intval($q->id).'" data-at-other-input="1" type="text" name="'.esc_attr($name).'_other" placeholder="Tulis jawaban lainnya" value="'.esc_attr($cur_is_other ? $cur : '').'" '.(($cur_is_other || $cur==='__other__') ? '' : 'disabled').'>
                              </div>';
                    }
                    break;
                default:
                    echo '<input class="at-control" data-qid="'.intval($q->id).'" type="text" name="'.esc_attr($name).'" value="'.esc_attr($cur).'" '.$req.' />';
            }
            if(isset($q->requires_file) && intval($q->requires_file)===1){
                $link_required = ($cur_file_url === '') ? 'required' : '';
                echo '<div class="at-evidence" style="margin-top:10px;">';
                echo '<label class="at-label" style="font-weight:600;">Link bukti <span class="at-required" aria-hidden="true">*</span></label>';
                if($cur_file_url !== ''){
                    echo '<p class="description" style="margin:4px 0;">Bukti saat ini: <a href="'.esc_url($cur_file_url).'" target="_blank" rel="noopener">Buka bukti</a>.</p>';
                }
                echo '<input class="at-control" data-qid="'.intval($q->id).'" data-at-evidence-input="1" type="url" name="qlink_'.intval($q->id).'" placeholder="https://..." value="'.esc_attr($cur_file_url).'" '.$link_required.' />';
                echo '<p class="description" style="margin-top:4px;">Masukkan URL lengkap yang diawali <code>https://</code> atau <code>http://</code>. Tidak ada upload file ke server.</p>';
                echo '</div>';
            }
            echo '</div>'; // field
        }

        // Captcha only on final submit
        if($page_idx >= $total_pages){
            $this->at_render_captcha_field('submit_run_' . intval($run_id));
        }

        $btn = ($page_idx < $total_pages) ? 'Lanjut' : 'Kirim';
        $prev_url = '';
        if($page_idx > 1){
            // Build Back link safely (PHP8+: array + null would TypeError).
            $args = array('at_page' => ($page_idx-1));
            if($response_id){ $args['rid'] = $response_id; }
            if(!isset($extra) || !is_array($extra)) { $extra = array(); }
            $args = array_merge($args, $extra);
            $prev_url = add_query_arg($args, $cur_url);
        }
        echo '<div class="at-actions">';
        if($prev_url){
            echo '<a class="at-btn at-btn-secondary" href="'.esc_url($prev_url).'">Kembali</a>';
        }
        echo '<button type="submit" class="at-btn at-btn-primary">'.$btn.'</button></div>';
        echo '</form></div>'; // section
        echo '</div>'; // card
        echo '</div>'; // survey
        // Conditional logic (front-end)
        echo <<<'ATJS'
<script>(function(){
    function q(sel,root){return (root||document).querySelector(sel);}
    function qa(sel,root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}

    function getValue(qid){
        // IMPORTANT: only read from actual form controls (wrapper also has data-qid)
        var els = qa('input[data-qid="'+qid+'"],select[data-qid="'+qid+'"],textarea[data-qid="'+qid+'"]');
        if(!els.length) return '';
        var el0 = els[0];
        if(el0.type==='radio'){
            var checked = q('input[data-qid="'+qid+'"][type=radio]:checked');
            return checked ? (checked.value||'') : '';
        }
        if(el0.type==='checkbox'){
            // for checkbox groups, return comma-joined checked values
            var checks = qa('input[data-qid="'+qid+'"][type=checkbox]:checked');
            return checks.map(function(c){return c.value||'';}).filter(Boolean).join(',');
        }
        return (el0.value||'').trim();
    }
    function splitCsv(s){return (s||'').split(',').map(function(x){return x.trim();}).filter(Boolean);}
    function evalCond(parentId,op,val){
        var v = getValue(parentId);
        if(op==='filled') return v!==''; 
        if(op==='empty') return v===''; 
        if(op==='eq') return v===String(val); 
        if(op==='neq') return v!==String(val);
        if(op==='in'){ var arr=splitCsv(val); return arr.indexOf(v)!==-1; }
        if(op==='not_in'){ var arr2=splitCsv(val); return arr2.indexOf(v)===-1; }
        return true;
    }
    function setVisible(wrap,show){
        if(!wrap) return;
        wrap.style.display = show ? '' : 'none';
        var inputs = qa('input,select,textarea', wrap);
        inputs.forEach(function(el){
            if(!el.dataset.atReq && el.hasAttribute('required')) el.dataset.atReq='1';
            if(!show){
                el.disabled = true;
                if(el.dataset.atReq==='1') el.removeAttribute('required');
                if(el.type==='radio' || el.type==='checkbox'){ el.checked=false; }
                else { el.value=''; }
            }else{
                el.disabled = false;
                if(el.dataset.atReq==='1') el.setAttribute('required','required');
            }
        });
    }
    function hasParentControl(pid){
        return !!q('input[data-qid="'+pid+'"],select[data-qid="'+pid+'"],textarea[data-qid="'+pid+'"]');
    }
    function applyAll(){
        qa('.akurasitara-q[data-cond-parent][data-cond-op]').forEach(function(wrap){
            var parentId = wrap.getAttribute('data-cond-parent');
            var op = wrap.getAttribute('data-cond-op')||'';
            var val = wrap.getAttribute('data-cond-val')||'';
            var act = (wrap.getAttribute('data-cond-action')||'show');
            var ok = evalCond(parentId, op, val);
            var show = (act==='hide') ? !ok : ok;
            if(!hasParentControl(parentId)) show = true; // parent not on this page
            setVisible(wrap, show);
        });
    }
    function bind(){
        var parentIds = {};
        qa('.akurasitara-q[data-cond-parent]').forEach(function(w){ parentIds[w.getAttribute('data-cond-parent')]=1; });
        Object.keys(parentIds).forEach(function(pid){
            qa('input[data-qid="'+pid+'"],select[data-qid="'+pid+'"],textarea[data-qid="'+pid+'"]').forEach(function(el){
                el.addEventListener('change', applyAll);
                el.addEventListener('input', applyAll);
            });
        });
    }
    function applyOtherForQ(qid){
        var otherWrap = document.getElementById('at_other_wrap_'+qid);
        if(!otherWrap) return;

        // detect controller: select value OR radio checked OR checkbox group includes __other__
        var show = false;
        var sel = q('select[data-qid="'+qid+'"]');
        if(sel){
            show = (String(sel.value||'') === '__other__');
        } else {
            var r = q('input[data-qid="'+qid+'"][type=radio]:checked');
            if(r){
                show = (String(r.value||'') === '__other__');
            } else {
                // checkbox group: robust detection
                show = !!q('input[data-qid="'+qid+'"][type=checkbox][value="__other__"]:checked');
            }
        }

        otherWrap.style.display = show ? '' : 'none';
        var inp = q('input[data-at-other-input][data-qid="'+qid+'"]', otherWrap);
        if(inp){
            inp.disabled = !show;
            if(!show){ inp.value=''; inp.removeAttribute('required'); }
            else {
                // if main question is required, require the other text too
                var anyReq = !!q('input[data-qid="'+qid+'"][required],select[data-qid="'+qid+'"][required],textarea[data-qid="'+qid+'"][required]');
                if(anyReq) inp.setAttribute('required','required');
            }
        }
    }

    function bindOther(){
        // bind on all qids that have other input
        qa('input[data-at-other-input]').forEach(function(inp){
            var qid = inp.getAttribute('data-qid');
            if(!qid) return;
            qid = String(qid);
            // radio/select controls
            qa('input[data-qid="'+qid+'"],select[data-qid="'+qid+'"]').forEach(function(el){
                el.addEventListener('change', function(){ applyOtherForQ(qid); applyAll(); });
                el.addEventListener('input', function(){ applyOtherForQ(qid); applyAll(); });
            });
            applyOtherForQ(qid);
        });

        // Safety net: event delegation for checkbox "__other__" toggle.
        // Some themes/plugins can interfere with direct listeners; delegation ensures the other text input
        // always appears when "Lainnya" is checked.
        document.addEventListener('change', function(e){
            var t = e.target;
            if(!t || !t.matches) return;
            if(t.matches('input[type=checkbox][data-qid][value="__other__"]')){
                applyOtherForQ(String(t.getAttribute('data-qid')||''));
            }
        });
    }
    function isWrapVisible(wrap){
        return !!(wrap && wrap.style.display !== 'none');
    }
    function labelText(wrap){
        var lab = q('.at-label', wrap);
        return lab ? (lab.textContent||'Pertanyaan wajib').replace('*','').trim() : 'Pertanyaan wajib';
    }
    function hasConditionalParentOnThisPage(wrap){
        if(!wrap || !wrap.hasAttribute('data-cond-parent')) return true;
        return hasParentControl(wrap.getAttribute('data-cond-parent'));
    }
    function validateRequiredBeforeSubmit(e){
        applyAll();
        var missing = [];
        qa('.akurasitara-q').forEach(function(wrap){
            if(!isWrapVisible(wrap)) return;
            // If the trigger question is on another page, server-side flow validation remains the source of truth.
            // This avoids blocking users because of legacy cross-page conditional rendering behavior.
            if(!hasConditionalParentOnThisPage(wrap)) return;
            var requiredMark = q('.at-required', wrap);
            if(!requiredMark) return;
            var qid = wrap.getAttribute('data-qid');
            if(!qid) return;
            var val = getValue(qid);
            if(val === '__other__'){
                var oi = q('input[data-at-other-input][data-qid="'+qid+'"]', wrap);
                val = oi ? (oi.value||'').trim() : '';
            } else if(val.split(',').indexOf('__other__') !== -1){
                var oi2 = q('input[data-at-other-input][data-qid="'+qid+'"]', wrap);
                if(!oi2 || !(oi2.value||'').trim()){
                    missing.push(labelText(wrap));
                    return;
                }
            }
            if(!val){ missing.push(labelText(wrap)); }
            if(wrap.getAttribute('data-evidence-required') === '1') {
                var li = q('input[data-at-evidence-input][data-qid="'+qid+'"]', wrap);
                var link = li ? (li.value || '').trim() : '';
                if(!link){
                    missing.push(labelText(wrap) + ' - link bukti');
                } else if(!/^https?:\/\//i.test(link)){
                    missing.push(labelText(wrap) + ' - link bukti harus diawali http:// atau https://');
                }
            }
        });
        if(missing.length){
            e.preventDefault();
            alert('Mohon lengkapi pertanyaan wajib berikut sebelum melanjutkan/mengirim:\n\n- ' + missing.join('\n- '));
            var first = qa('.akurasitara-q').filter(function(wrap){ return missing.indexOf(labelText(wrap)) !== -1; })[0];
            if(first && first.scrollIntoView){ first.scrollIntoView({behavior:'smooth', block:'center'}); }
            return false;
        }
        return true;
    }
    function bindSubmitValidation(){
        var form = q('form.at-form');
        if(form){ form.addEventListener('submit', validateRequiredBeforeSubmit); }
    }
    function init(){ bind(); bindOther(); applyAll(); bindSubmitValidation(); }
    if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();</script>
ATJS;

        return ob_get_clean();
    }
public 
function maybe_handle_submission(){
        $at_debug = (isset($_GET['at_debug']) && $_GET['at_debug']=='1');
        if($at_debug){ @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); @error_reporting(E_ALL); }
        try {
        // Identity confirmation step (after group-login, before answering survey)
        if(isset($_POST['at_profile_confirm'])){
            $run_id = isset($_POST['run_id']) ? intval($_POST['run_id']) : 0;
            $tok = isset($_POST['at_user_token']) ? sanitize_text_field(wp_unslash($_POST['at_user_token'])) : '';
            if($run_id && $tok!==''){
                $db=$this->db(); $t=$this->tables();
                $run = $db->get_row($db->prepare("SELECT * FROM {$t->runs} WHERE id=%d", $run_id));
                if($run && $this->is_run_group($run)){
                    $uid = $this->user_token_verify($run_id, $tok);
                    if($uid>0){
                        // update only editable elements
                        $group = $db->get_row($db->prepare("SELECT * FROM {$t->user_groups} WHERE id=%d", intval($run->group_id)));
                        if($group){
                            $elements = $db->get_results($db->prepare("SELECT * FROM {$t->id_elements} WHERE template_id=%d AND editable_by_user=1 ORDER BY sort_order ASC, id ASC", intval($group->template_id)));
                            foreach($elements as $el){
                                $fname = 'id_el_'.intval($el->id);
                                if(!isset($_POST[$fname])) continue;
                                $val = wp_unslash($_POST[$fname]);
                                $val = ($el->field_type==='textarea') ? sanitize_textarea_field($val) : sanitize_text_field($val);
                                $exists = $db->get_var($db->prepare("SELECT id FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", intval($uid), intval($el->id)));
                                if($exists){
                                    $db->update($t->user_identity, array('value_long'=>$val), array('id'=>intval($exists)));
                                } else {
                                    $db->insert($t->user_identity, array('user_id'=>intval($uid),'element_id'=>intval($el->id),'value_long'=>$val));
                                }
                            }
                        }
                        // mark token confirmed
                        $store = $this->user_token_get_store($tok);
                        $store['confirmed']=1;
                        $this->user_token_set_store($tok, $store);
                    }
                }
            }
            $return_to = isset($_POST['return_to']) ? esc_url_raw(wp_unslash($_POST['return_to'])) : '';
            if($return_to && $tok!==''){
                $return_to = add_query_arg('at_user_token', $tok, $return_to);
            }
            if($return_to){
                wp_safe_redirect($return_to);
                exit;
            }
            return;
        }

        if(isset($_POST['at_submit'])){
            if(!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'at_submit')) return;
            $run_id = isset($_POST['run_id']) ? intval($_POST['run_id']) : 0;
            if(!$run_id) return;
            $db=$this->db(); $t=$this->tables();
            $run = $db->get_row($db->prepare("SELECT * FROM {$t->runs} WHERE id=%d",$run_id));
            $reason='';
            if(!$this->run_is_currently_open($run, $reason)) return;
            $user_id = 0;
            if(isset($run->survey_type) && $run->survey_type==='group' && !empty($run->group_id)){
                $tok = isset($_POST['at_user_token']) ? sanitize_text_field(wp_unslash($_POST['at_user_token'])) : '';
                $user_id = $tok ? $this->user_token_verify($run_id, $tok) : 0;
                if($user_id<=0){ return; }
            }

            $page_idx    = isset($_POST['page_idx']) ? max(1, intval($_POST['page_idx'])) : 1;
            $total_pages = isset($_POST['total_pages']) ? max(1, intval($_POST['total_pages'])) : 1;
            $section_csv = isset($_POST['section_qids']) ? (string)$_POST['section_qids'] : '';
            $qids = array();
            foreach(explode(',', $section_csv) as $x){ $x = intval($x); if($x>0){ $qids[]=$x; } }

            $response_id = isset($_POST['response_id']) ? intval($_POST['response_id']) : 0;

            // Server-side guard: do not allow moving forward or final submit when visible required questions are empty.
            // On final submit, check the full flow; on intermediate pages, check the current section only.
            $missing_required = $this->at_get_missing_required_questions_for_submission($run->survey_id, $response_id, $qids, ($page_idx >= $total_pages));
            $this->at_block_submission_if_required_missing($missing_required);

            // Captcha validation only on final submit. Run it after required-question validation so captcha is not consumed
            // when the user only needs to complete missing required answers.
            if($page_idx >= $total_pages){
                $cap_tok = sanitize_text_field( wp_unslash($_POST['at_captcha_token'] ?? '') );
                $cap_ans = sanitize_text_field( wp_unslash($_POST['at_captcha_answer'] ?? '') );
                if(!$this->at_captcha_verify($cap_tok, $cap_ans, 'submit_run_' . intval($run_id))){
                    wp_die('Captcha salah. Silakan kembali dan coba lagi.', 'Captcha tidak valid', array('back_link'=>true));
                }
            }
            if(!$response_id){
                $db->insert($t->responses, array(
                    'run_id'=>$run_id,
                    'unit_id'=>$run->unit_id,
                    'survey_id'=>$run->survey_id,
                    'year'=>$run->year,
                    'ip_address'=>$this->client_ip(),
                    'fill_status'=>'in_progress',
                    'completed_at'=>null,
                    'user_id'=>($user_id?:null),
                    'submitted_at'=>current_time('mysql')
                ));
                $response_id = intval($db->insert_id);
            }
            if($response_id > 0 && !headers_sent()){
                @setcookie('akurasitara_rid_' . intval($run_id), (string)$response_id, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
            }

            if($qids){
                // Fetch qtype map for server-side validation (avoid relying on client-side HTML5 only)
                $placeholders = implode(',', array_fill(0, count($qids), '%d'));
                $sqlq = "SELECT id, qtype, question_text, requires_file FROM {$t->questions} WHERE survey_id=%d AND id IN ($placeholders)";
                $params = array_merge(array($run->survey_id), $qids);
                // wpdb::prepare() is variadic; use call_user_func_array for max compatibility.
                $prepared = call_user_func_array(array($db,'prepare'), array_merge(array($sqlq), $params));
                $qrows = $db->get_results($prepared);
                $qmap = array();
                if($qrows){
                    foreach($qrows as $qr){ $qmap[intval($qr->id)] = $qr; }
                }
                foreach($qids as $qid){
                    $key='q_'.$qid;
                    $val = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
                    if(is_array($val)){ $val = implode(',', array_map('sanitize_text_field',$val)); }
                    else { $val = sanitize_text_field($val); }

                    // Type validation for new qtypes: number & date
                    $qt = isset($qmap[$qid]) ? (string)$qmap[$qid]->qtype : '';
                    if($qt==='number'){
                        if($val!=='' && !is_numeric($val)){
                            wp_die('Jawaban untuk pertanyaan "'.esc_html((string)$qmap[$qid]->question_text).'" harus berupa angka.', 'Input tidak valid', array('back_link'=>true));
                        }
                    }elseif($qt==='date'){
                        if($val!==''){
                            // Expect YYYY-MM-DD (HTML5 date) but still validate strictly
                            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)){
                                wp_die('Jawaban untuk pertanyaan "'.esc_html((string)$qmap[$qid]->question_text).'" harus berupa tanggal (YYYY-MM-DD).', 'Input tidak valid', array('back_link'=>true));
                            }
                            list($yy,$mm,$dd) = array_map('intval', explode('-', $val));
                            if(!checkdate($mm,$dd,$yy)){
                                wp_die('Jawaban untuk pertanyaan "'.esc_html((string)$qmap[$qid]->question_text).'" tidak valid.', 'Input tidak valid', array('back_link'=>true));
                            }
                        }
                    }

                    // Support "Lainnya" for radio/select/checkbox: store text directly in the same answer column
                    $okey = $key.'_other';
                    $oval = isset($_POST[$okey]) ? sanitize_text_field( wp_unslash($_POST[$okey]) ) : '';
                    if($val==='__other__'){
                        $val = $oval;
                    }elseif($oval!=='' && strpos(','.$val.',', ',__other__,')!==false){
                        // checkbox CSV contains __other__
                        $parts = array_filter(array_map('trim', explode(',', $val)));
                        $parts = array_values(array_diff($parts, array('__other__')));
                        $parts[] = $oval;
                        $val = implode(',', $parts);
                    }elseif(strpos(','.$val.',', ',__other__,')!==false){
                        // __other__ checked but empty other text -> just remove marker
                        $parts = array_filter(array_map('trim', explode(',', $val)));
                        $parts = array_values(array_diff($parts, array('__other__')));
                        $val = implode(',', $parts);
                    }

                    $existing_file = $db->get_row($db->prepare(
                        "SELECT file_url, file_path, file_name, file_mime, file_size FROM {$t->answers} WHERE response_id=%d AND question_id=%d ORDER BY id DESC LIMIT 1",
                        $response_id, $qid
                    ), ARRAY_A);
                    $file_data = is_array($existing_file) ? $existing_file : array(
                        'file_url'=>null, 'file_path'=>null, 'file_name'=>null, 'file_mime'=>null, 'file_size'=>null
                    );
                    $new_link = $this->at_handle_evidence_link($qid);
                    if(is_array($new_link)){
                        $file_data = $new_link;
                    }
                    $requires_file = (isset($qmap[$qid]) && isset($qmap[$qid]->requires_file) && intval($qmap[$qid]->requires_file) === 1);
                    if($requires_file && empty($file_data['file_url'])){
                        wp_die('Link bukti untuk pertanyaan "'.esc_html((string)$qmap[$qid]->question_text).'" wajib diisi.', 'Link bukti wajib', array('back_link'=>true));
                    }

                    $db->delete($t->answers, array('response_id'=>$response_id,'question_id'=>$qid));
                    $db->insert($t->answers, array(
                        'response_id'=>$response_id,
                        'question_id'=>$qid,
                        'answer_text'=>$val,
                        'file_url'=>isset($file_data['file_url']) ? $file_data['file_url'] : null,
                        'file_path'=>isset($file_data['file_path']) ? $file_data['file_path'] : null,
                        'file_name'=>isset($file_data['file_name']) ? $file_data['file_name'] : null,
                        'file_mime'=>isset($file_data['file_mime']) ? $file_data['file_mime'] : null,
                        'file_size'=>isset($file_data['file_size']) ? intval($file_data['file_size']) : null,
                    ));
                }
            }

            if($response_id > 0){
                if($page_idx >= $total_pages){
                    $db->update($t->responses, array(
                        'fill_status'=>'completed',
                        'completed_at'=>current_time('mysql'),
                        'submitted_at'=>current_time('mysql')
                    ), array('id'=>$response_id, 'run_id'=>$run_id));
                    if(!headers_sent()){
                        @setcookie('akurasitara_rid_' . intval($run_id), '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
                    }
                } else {
                    $db->update($t->responses, array('fill_status'=>'in_progress'), array('id'=>$response_id, 'run_id'=>$run_id));
                }
            }

            $base = isset($_POST['return_to']) ? esc_url_raw($_POST['return_to']) : '';
            if (!$base) { $base = wp_get_referer(); }
            if (!$base && function_exists('get_permalink')) { $base = get_permalink(); }
            if (!$base) { $base = home_url(); }
            $base = remove_query_arg(array('at_page','rid','at_thanks','at_pass'), $base);
            $unlock = isset($_POST['at_unlock']) ? sanitize_text_field( wp_unslash($_POST['at_unlock']) ) : ( isset($_GET['at_unlock']) ? sanitize_text_field( wp_unslash($_GET['at_unlock']) ) : '' );
            $utok = isset($_POST['at_user_token']) ? sanitize_text_field( wp_unslash($_POST['at_user_token']) ) : '';
            $extra = array();
            if($unlock!=='') $extra['at_unlock'] = $unlock;
            if($utok!=='')   $extra['at_user_token'] = $utok;

            if($page_idx < $total_pages){
                $next = add_query_arg(array('at_page'=>$page_idx+1,'rid'=>$response_id) + $extra, $base);
                wp_redirect($next);
            }else{
                $done = add_query_arg(array('at_thanks'=>'1') + $extra, $base);
                wp_redirect($done);
            }
            exit;
        }

        } catch (\Throwable $e) {
            $show = $at_debug || (is_user_logged_in() && current_user_can('manage_options'));
            $msg = 'Terjadi kesalahan (AkurasiTara).';
            if($show){
                $msg .= '<br><code>' . esc_html($e->getMessage()) . '</code><br><small>' . esc_html(basename($e->getFile())) . ':' . intval($e->getLine()) . '</small>';
            }
            if(function_exists('error_log')){
                error_log('[AkurasiTara] fatal: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            }
            wp_die($msg, 'AkurasiTara Error', array('back_link'=>true));
        }

    }

/* ================= Shortcode: Results (front-end charts) ================= */
    public function shortcode_render_results($atts){
        $atts = shortcode_atts(array('id'=>0,'chart'=>'bar'), $atts, 'akurasitara_results');
        $run_id = intval($atts['id']);
        if(!$run_id) return '<div class="akurasitara-error">Run ID tidak valid.</div>';

        $db=$this->db(); $t=$this->tables();
        $run=$db->get_row($db->prepare("SELECT r.*, s.title FROM {$t->runs} r JOIN {$t->surveys} s ON s.id=r.survey_id WHERE r.id=%d",$run_id));
        if(!$run) return '<div class="akurasitara-error">Pelaksanaan tidak ditemukan.</div>';

		$qs=$db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d AND qtype IN ('radio','select','checkbox') ORDER BY sort_order ASC, id ASC",$run->survey_id));
		if(!$qs) return '<div class="akurasitara-info">Tidak ada pertanyaan (radio/select/checkbox) untuk ditampilkan.</div>';

        $chart = in_array($atts['chart'], array('bar','pie','doughnut'), true) ? $atts['chart'] : 'bar';

        ob_start();
        echo '<div class="akurasitara-results-charts"><h3>'.$this->esc($run->title).'</h3>';
		$head_unit_id = $this->get_unit_role_id_for_current_user();
        foreach($qs as $q){
			$data = $this->aggregate_counts($run_id,$q, $head_unit_id);
            $chart_id = 'at_sc_chart_'.$q->id.'_'.wp_generate_password(6,false,false);
            echo '<h4>'.$this->esc($q->question_text).'</h4>';
            echo $this->at_render_bar_chart_html($data);
}
        echo '</div>';
        return ob_get_clean();
    }

    /* ================= Identity Template CRUD ================= */
    public function page_id_templates(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        if(isset($_POST['at_action']) && $_POST['at_action']==='save_id_template' && check_admin_referer('at_save_id_template')){
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $id = intval($_POST['id'] ?? 0);
            if($name!==''){
                if($id){
                    $db->update($t->id_templates, array('name'=>$name), array('id'=>$id));
                    echo '<div class="updated"><p>Template diperbarui.</p></div>';
                } else {
                    $db->insert($t->id_templates, array('name'=>$name));
                    echo '<div class="updated"><p>Template ditambahkan.</p></div>';
                }
            }
        }
        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_del_id_template')){
            $id=intval($_GET['delete']);
            if($id){
                $db->delete($t->id_templates, array('id'=>$id));
                $db->delete($t->id_elements, array('template_id'=>$id));
                echo '<div class="updated"><p>Template dihapus.</p></div>';
            }
        }

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->id_templates} WHERE id=%d",$edit_id)) : null;

        $rows=$db->get_results("SELECT * FROM {$t->id_templates} ORDER BY id DESC");
        echo '<div class="wrap"><h1>Template Identitas Pengguna</h1>';

        echo '<h2>'.($edit?'Edit Template':'Tambah Template').'</h2><form method="post">';
        wp_nonce_field('at_save_id_template');
        echo '<input type="hidden" name="at_action" value="save_id_template"/>';
        if($edit){ echo '<input type="hidden" name="id" value="'.intval($edit->id).'"/>'; }
        echo '<table class="form-table"><tr><th>Nama Template</th><td><input type="text" name="name" value="'.esc_attr($edit?$edit->name:'').'" required style="min-width:320px"></td></tr></table>';
        echo '<p><button class="button button-primary">Simpan</button></p></form>';
        echo '<hr/><h2>Daftar Template</h2>';
        if($rows){
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nama</th></tr></thead><tbody>';
            foreach($rows as $r){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_id_templates&edit='.$r->id);
                $del_url  = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_id_templates&delete='.$r->id), 'at_del_id_template');
                $elm_url  = admin_url('admin.php?page='.self::SLUG.'_id_elements&template_id='.$r->id);
                echo '<tr><td>'.intval($r->id).'</td><td>'.$this->esc($r->name).'</td><td>
                    <a class="button" href="'.$edit_url.'">Edit</a>
                    <a class="button" href="'.$elm_url.'">Tambah Elemen Identitas</a>
                    <a class="button at-del" href="'.$del_url.'">Hapus</a>
                </td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Belum ada template.</p>';
        }
        echo '</div>';
    }

    /* ================= Identity Elements CRUD ================= */
    public function page_id_elements(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        $template_id = intval($_GET['template_id'] ?? 0);
        if(!$template_id){
            echo '<div class="wrap"><h1>Elemen Identitas</h1><p>Template belum dipilih. Buka dari menu Template Identitas → tombol "Tambah Elemen Identitas".</p></div>';
            return;
        }
        $template = $db->get_row($db->prepare("SELECT * FROM {$t->id_templates} WHERE id=%d",$template_id));
        if(!$template){ echo '<div class="wrap"><h1>Elemen Identitas</h1><p>Template tidak ditemukan.</p></div>'; return; }

        if(isset($_POST['at_action']) && $_POST['at_action']==='save_id_element' && check_admin_referer('at_save_id_element')){
            $id = intval($_POST['id'] ?? 0);
            $label = sanitize_text_field(wp_unslash($_POST['field_label'] ?? ''));
            $type  = sanitize_text_field(wp_unslash($_POST['field_type'] ?? 'text'));
            if(!in_array($type, array('text','select','textarea'), true)) $type='text';
            $opts  = '';
            if($type==='select'){
                $opts = sanitize_textarea_field(wp_unslash($_POST['options_csv'] ?? ''));
            }
            $editable = isset($_POST['editable_by_user']) ? 1 : 0;
            $showres  = isset($_POST['show_in_results']) ? 1 : 0;
            $sort  = intval($_POST['sort_order'] ?? 0);
            if($label!==''){
                $data=array('template_id'=>$template_id,'field_label'=>$label,'field_type'=>$type,'options_csv'=>$opts,'editable_by_user'=>$editable,'show_in_results'=>$showres,'sort_order'=>$sort);
                if($id){ $db->update($t->id_elements,$data,array('id'=>$id)); echo '<div class="updated"><p>Elemen diperbarui.</p></div>'; }
                else { $db->insert($t->id_elements,$data); echo '<div class="updated"><p>Elemen ditambahkan.</p></div>'; }
            }
        }
        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_del_id_element')){
            $id=intval($_GET['delete']);
            if($id){ $db->delete($t->id_elements,array('id'=>$id,'template_id'=>$template_id)); echo '<div class="updated"><p>Elemen dihapus.</p></div>'; }
        }

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->id_elements} WHERE id=%d AND template_id=%d",$edit_id,$template_id)) : null;

        $rows=$db->get_results($db->prepare("SELECT * FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC, id ASC",$template_id));

        echo '<div class="wrap"><h1>Elemen Identitas - '.$this->esc($template->name).'</h1>';
        echo '<p><a class="button" href="'.admin_url('admin.php?page='.self::SLUG.'_id_templates').'">← Kembali ke Template</a></p>';

        echo '<h2>'.($edit?'Edit Elemen':'Tambah Elemen').'</h2><form method="post">';
        wp_nonce_field('at_save_id_element');
        echo '<input type="hidden" name="at_action" value="save_id_element"/>';
        if($edit){ echo '<input type="hidden" name="id" value="'.intval($edit->id).'"/>'; }
        echo '<table class="form-table">
            <tr><th>Nama Identitas</th><td><input type="text" name="field_label" value="'.esc_attr($edit?$edit->field_label:'').'" required style="min-width:320px"></td></tr>
            <tr><th>Jenis Isian</th><td>
                <select name="field_type" id="at_id_field_type">
                    <option value="text" '.selected($edit?$edit->field_type:'text','text',false).'>Text Field</option>
                    <option value="select" '.selected($edit?$edit->field_type:'text','select',false).'>Drop Down</option>
                    <option value="textarea" '.selected($edit?$edit->field_type:'text','textarea',false).'>Text Area</option>
                </select>
            </td></tr>
            <tr id="at_id_opts_row"><th>Opsi Drop Down</th><td>
                <textarea name="options_csv" rows="3" style="min-width:420px" placeholder="Contoh: Laki-laki|L, Perempuan|P">'.esc_textarea($edit?$edit->options_csv:'').'</textarea>
                <p class="description">Format: label|value, dipisah koma.</p>
            </td></tr>
            <tr><th>Pengaturan</th><td>
                <label><input type="checkbox" name="editable_by_user" value="1" '.checked($edit?intval($edit->editable_by_user):0,1,false).'> Bisa diedit pengguna?</label><br/>
                <label><input type="checkbox" name="show_in_results" value="1" '.checked($edit?intval($edit->show_in_results):0,1,false).'> Tampilkan di halaman hasil (khusus survey pengguna tertentu)</label>
            </td></tr>
            <tr><th>Urutan</th><td><input type="number" name="sort_order" value="'.esc_attr($edit?$edit->sort_order:0).'"></td></tr>
        </table>
        <p><button class="button button-primary">Simpan</button></p></form>
        <script>(function(){var t=document.getElementById("at_id_field_type");var r=document.getElementById("at_id_opts_row");function s(){if(!t||!r)return; r.style.display=(t.value==="select")?"table-row":"none";} if(t){t.addEventListener("change",s);s();}})();</script>';

        echo '<hr/><h2>Daftar Elemen</h2>';
        if($rows){
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nama</th><th>Tipe</th><th>Options</th><th>Edit?</th><th>Tampil di Hasil?</th><th>Urutan</th></tr></thead><tbody>';
            foreach($rows as $r){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_id_elements&template_id='.$template_id.'&edit='.$r->id);
                $del_url  = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_id_elements&template_id='.$template_id.'&delete='.$r->id), 'at_del_id_element');
                echo '<tr><td>'.intval($r->id).'</td><td>'.$this->esc($r->field_label).'</td><td>'.$this->esc($r->field_type).'</td><td>'.$this->esc($r->options_csv).'</td><td>'.(intval($r->editable_by_user)?'Ya':'-').'</td><td>'.(intval($r->show_in_results)?'Ya':'-').'</td><td>'.intval($r->sort_order).'</td>
                <td><a class="button" href="'.$edit_url.'">Edit</a> <a class="button at-del" href="'.$del_url.'">Hapus</a></td></tr>';
            }
            echo '</tbody></table>';
        } else { echo '<p>Belum ada elemen.</p>'; }
        echo '</div>';
    }

    /* ================= User Groups CRUD ================= */
    public function page_user_groups(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        $templates = $db->get_results("SELECT id,name FROM {$t->id_templates} ORDER BY id DESC");

        if(isset($_POST['at_action']) && $_POST['at_action']==='save_user_group' && check_admin_referer('at_save_user_group')){
            $id = intval($_POST['id'] ?? 0);
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $template_id = intval($_POST['template_id'] ?? 0);
            if($name!=='' && $template_id){
                $data=array('name'=>$name,'template_id'=>$template_id);
                if($id){ $db->update($t->user_groups,$data,array('id'=>$id)); echo '<div class="updated"><p>Kelompok diperbarui.</p></div>'; }
                else { $db->insert($t->user_groups,$data); echo '<div class="updated"><p>Kelompok ditambahkan.</p></div>'; }
            }
        }
        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_del_user_group')){
            $id=intval($_GET['delete']);
            if($id){ $db->delete($t->user_groups,array('id'=>$id)); echo '<div class="updated"><p>Kelompok dihapus.</p></div>'; }
        }

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->user_groups} WHERE id=%d",$edit_id)) : null;

        $rows=$db->get_results("SELECT g.*, t2.name AS template_name FROM {$t->user_groups} g JOIN {$t->id_templates} t2 ON t2.id=g.template_id ORDER BY g.id DESC");

        echo '<div class="wrap"><h1>Kelompok Pengguna</h1>';
        echo '<h2>'.($edit?'Edit Kelompok':'Tambah Kelompok').'</h2><form method="post">';
        wp_nonce_field('at_save_user_group');
        echo '<input type="hidden" name="at_action" value="save_user_group"/>';
        if($edit){ echo '<input type="hidden" name="id" value="'.intval($edit->id).'"/>'; }
        echo '<table class="form-table">
            <tr><th>Nama Kelompok</th><td><input type="text" name="name" value="'.esc_attr($edit?$edit->name:'').'" required style="min-width:320px"></td></tr>
            <tr><th>Template Identitas</th><td><select name="template_id" required><option value="">-- Pilih Template --</option>';
        foreach($templates as $tp){
            $sel = ($edit && intval($edit->template_id)===intval($tp->id)) ? 'selected' : '';
            echo '<option value="'.intval($tp->id).'" '.$sel.'>'.$this->esc($tp->name).'</option>';
        }
        echo '</select></td></tr></table><p><button class="button button-primary">Simpan</button></p></form>';

        echo '<hr/><h2>Daftar Kelompok</h2>';
        if($rows){
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nama</th><th>Template</th></tr></thead><tbody>';
            foreach($rows as $r){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_user_groups&edit='.$r->id);
                $del_url  = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_user_groups&delete='.$r->id), 'at_del_user_group');
                $users_url = admin_url('admin.php?page='.self::SLUG.'_users&group_id='.$r->id);
                echo '<tr><td>'.intval($r->id).'</td><td>'.$this->esc($r->name).'</td><td>'.$this->esc($r->template_name).'</td><td>
                    <a class="button" href="'.$edit_url.'">Edit</a>
                    <a class="button" href="'.$users_url.'">Lihat Pengguna</a>
                    <a class="button at-del" href="'.$del_url.'">Hapus</a>
                </td></tr>';
            }
            echo '</tbody></table>';
        } else { echo '<p>Belum ada kelompok.</p>'; }
        echo '</div>';
    }

    /* ================= Users CRUD (with identity fields) ================= */
    public function page_users(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();

        $group_id = intval($_GET['group_id'] ?? 0);
        if(!$group_id){
            echo '<div class="wrap"><h1>Pengguna</h1><p>Kelompok belum dipilih. Buka dari menu Kelompok Pengguna → tombol "Lihat Pengguna".</p></div>';
            return;
        }
        
        // Notifikasi hasil import CSV (dari admin-post handler)
        if (isset($_GET['import_done']) && $_GET['import_done']=='1') {
            $res = get_transient('at_import_result_'.get_current_user_id());
            if ($res) {
                delete_transient('at_import_result_'.get_current_user_id());
                $msg = 'Import pengguna selesai. Berhasil: '.intval($res['imported']).' | Dilewati: '.intval($res['skipped']);
                echo '<div class=\"notice notice-success\"><p><strong>'.$msg.'</strong></p></div>';
                if (!empty($res['errors'])) {
                    echo '<div class=\"notice notice-warning\"><p><strong>Catatan (maks 20):</strong></p><ul>';
                    foreach ($res['errors'] as $e) {
                        echo '<li>'.esc_html($e).'</li>';
                    }
                    echo '</ul></div>';
                }
            }
        }
        if (isset($_GET['import_failed']) && $_GET['import_failed']=='1') {
            $res = get_transient('at_import_error_'.get_current_user_id());
            if ($res) {
                delete_transient('at_import_error_'.get_current_user_id());
                echo '<div class="notice notice-error"><p><strong>Import pengguna gagal.</strong></p>';
                if (!empty($res['message'])) {
                    echo '<p>'.nl2br(esc_html($res['message'])).'</p>';
                }
                if (!empty($res['trace'])) {
                    echo '<details style="margin-top:8px"><summary>Detail teknis</summary><pre style="white-space:pre-wrap;max-height:280px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4">'.esc_html($res['trace']).'</pre></details>';
                }
                echo '</div>';
            }
        }
$group = $db->get_row($db->prepare("SELECT g.*, t2.name AS template_name FROM {$t->user_groups} g JOIN {$t->id_templates} t2 ON t2.id=g.template_id WHERE g.id=%d",$group_id));
        if(!$group){ echo '<div class="wrap"><h1>Pengguna</h1><p>Kelompok tidak ditemukan.</p></div>'; return; }

        $elements = $db->get_results($db->prepare("SELECT * FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC, id ASC", intval($group->template_id)));

        $units = $db->get_results("SELECT id,name FROM {$t->units} ORDER BY name ASC");

// Download template CSV for this group
if(isset($_GET['at_download_users_template']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_download_users_template')){
    $filename = 'template-import-pengguna-kelompok-'.$group_id.'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output', 'w');

    // Comment lines with unit reference
    fputs($out, "# Referensi Unit Kerja (unit_id):\n");
    foreach($units as $u){
        fputs($out, "# ".$u->id." = ".$u->name."\n");
    }
    fputs($out, "#\n");
    fputs($out, "# Format opsi dropdown identitas: Label|Value (contoh: Laki-laki|L)\n");
    fputs($out, "#\n");

    $headers = array('username','password','unit_id');
    foreach($elements as $el){
        // gunakan kolom id_{element_id} agar stabil walau nama berubah
        $headers[] = 'id_'.$el->id;
    }
    fputcsv($out, $headers);
    // contoh satu baris
    $sample = array('contohuser','password123', ($units ? $units[0]->id : '' ));
    foreach($elements as $el){ $sample[] = ''; }
    fputcsv($out, $sample);
    fclose($out);
    exit;
}

if(isset($_POST['at_action']) && $_POST['at_action']==='delete_selected_users' && check_admin_referer('at_bulk_users')){
            $ids = isset($_POST['user_ids']) ? array_map('intval', (array)$_POST['user_ids']) : array();
            $ids = array_values(array_filter($ids));
            if($ids){
                $ph = implode(',', array_fill(0, count($ids), '%d'));
                $params = array_merge(array($group_id), $ids);
                $sql = $db->prepare("SELECT id FROM {$t->users} WHERE group_id=%d AND id IN ($ph)", $params);
                $valid_ids = array_map('intval', (array)$db->get_col($sql));
                if($valid_ids){
                    $ph2 = implode(',', array_fill(0, count($valid_ids), '%d'));
                    $db->query($db->prepare("DELETE FROM {$t->user_identity} WHERE user_id IN ($ph2)", $valid_ids));
                    $params2 = array_merge(array($group_id), $valid_ids);
                    $db->query($db->prepare("DELETE FROM {$t->users} WHERE group_id=%d AND id IN ($ph2)", $params2));
                    echo '<div class="updated"><p>'.count($valid_ids).' pengguna berhasil dihapus.</p></div>';
                } else {
                    echo '<div class="error"><p>Tidak ada pengguna valid yang dipilih.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Pilih minimal satu pengguna yang akan dihapus.</p></div>';
            }
        }

        if(isset($_POST['at_action']) && $_POST['at_action']==='delete_all_users' && check_admin_referer('at_bulk_users')){
            $user_ids = array_map('intval', (array)$db->get_col($db->prepare("SELECT id FROM {$t->users} WHERE group_id=%d", $group_id)));
            if($user_ids){
                $ph = implode(',', array_fill(0, count($user_ids), '%d'));
                $db->query($db->prepare("DELETE FROM {$t->user_identity} WHERE user_id IN ($ph)", $user_ids));
            }
            $db->delete($t->users, array('group_id'=>$group_id));
            echo '<div class="updated"><p>Semua pengguna pada kelompok ini berhasil dihapus.</p></div>';
        }

        if(isset($_POST['at_action']) && $_POST['at_action']==='save_user' && check_admin_referer('at_save_user')){
            $id = intval($_POST['id'] ?? 0);
            $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
            $password = (string)(wp_unslash($_POST['password'] ?? ''));
            $unit_id = intval($_POST['unit_id'] ?? 0);
            if($unit_id<=0) $unit_id = null;
            if($username!==''){
                if($id){
                    $data=array('username'=>$username,'group_id'=>$group_id,'unit_id'=>$unit_id);
                    if($password!==''){ $data['password_hash'] = wp_hash_password($password); }
                    $db->update($t->users,$data,array('id'=>$id,'group_id'=>$group_id));
                    foreach($elements as $el){
                        $k='id_'.$el->id;
                        $val = isset($_POST[$k]) ? wp_unslash($_POST[$k]) : '';
                        if(is_array($val)) $val = implode(',', array_map('sanitize_text_field',$val));
                        else $val = sanitize_text_field($val);
                        $db->delete($t->user_identity,array('user_id'=>$id,'element_id'=>$el->id));
                        $db->insert($t->user_identity,array('user_id'=>$id,'element_id'=>$el->id,'value_long'=>$val));
                    }
                    echo '<div class="updated"><p>Pengguna diperbarui.</p></div>';
                } else {
                    if($password===''){ echo '<div class="error"><p>Password wajib untuk pengguna baru.</p></div>'; }
                    else {
                        $db->insert($t->users,array('group_id'=>$group_id,'unit_id'=>$unit_id,'username'=>$username,'password_hash'=>wp_hash_password($password)));
                        $new_id=intval($db->insert_id);
                        foreach($elements as $el){
                            $k='id_'.$el->id;
                            $val = isset($_POST[$k]) ? wp_unslash($_POST[$k]) : '';
                            if(is_array($val)) $val = implode(',', array_map('sanitize_text_field',$val));
                            else $val = sanitize_text_field($val);
                            $db->insert($t->user_identity,array('user_id'=>$new_id,'element_id'=>$el->id,'value_long'=>$val));
                        }
                        echo '<div class="updated"><p>Pengguna ditambahkan.</p></div>';
                    }
                }
            }
        }

        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_del_user')){
            $id=intval($_GET['delete']);
            if($id){
                $db->delete($t->users,array('id'=>$id,'group_id'=>$group_id));
                $db->delete($t->user_identity,array('user_id'=>$id));
                echo '<div class="updated"><p>Pengguna dihapus.</p></div>';
            }
        }

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->users} WHERE id=%d AND group_id=%d",$edit_id,$group_id)) : null;
        $edit_vals = array();
        if($edit){
            $vals = $db->get_results($db->prepare("SELECT element_id,value_long FROM {$t->user_identity} WHERE user_id=%d",$edit->id));
            foreach($vals as $v){ $edit_vals[intval($v->element_id)] = (string)$v->value_long; }
        }

        $rows=$db->get_results($db->prepare("SELECT u.*, un.name AS unit_name FROM {$t->users} u LEFT JOIN {$t->units} un ON un.id=u.unit_id WHERE u.group_id=%d ORDER BY u.id DESC",$group_id));


        $identity_map = array();
        if($rows && $elements){
            $user_ids = array_map(function($r){ return intval($r->id); }, $rows);
            $ph = implode(',', array_fill(0, count($user_ids), '%d'));
            $idents = $db->get_results($db->prepare("SELECT ui.user_id, ui.element_id, ui.value_long FROM {$t->user_identity} ui WHERE ui.user_id IN ($ph)", $user_ids));
            foreach((array)$idents as $it){
                $uid = intval($it->user_id);
                $eid = intval($it->element_id);
                if(!isset($identity_map[$uid])) $identity_map[$uid] = array();
                $identity_map[$uid][$eid] = (string)$it->value_long;
            }
        }

        echo '<div class="wrap"><h1>Pengguna - '.$this->esc($group->name).' <span style="font-weight:normal;color:#666">('.$this->esc($group->template_name).')</span></h1>';
        echo '<p><a class="button" href="'.admin_url('admin.php?page='.self::SLUG.'_user_groups').'">← Kembali ke Kelompok</a></p>';


// === Import Pengguna via CSV ===
$dl_url = wp_nonce_url(
    admin_url('admin.php?page='.self::SLUG.'_users&group_id='.$group_id.'&at_download_users_template=1'),
    'at_download_users_template'
);

echo '<hr style="margin:18px 0" />';
echo '<h2>Impor Pengguna (CSV)</h2>';
echo '<p>Gunakan template agar kolom sesuai dengan template identitas kelompok ini.</p>';
echo '<p><a class="button button-secondary" href="'.$dl_url.'">Unduh Template CSV</a></p>';

echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
wp_nonce_field('at_import_users_csv');
echo '<input type="hidden" name="action" value="at_import_users_csv"/>';
echo '<input type="hidden" name="group_id" value="' . intval($group_id) . '"/>';
echo '<p><label>File CSV: <input type="file" name="users_csv" accept=".csv" required></label></p>';
echo '<p><button type="submit" class="button button-primary">Import</button></p>';
echo '</form>';



        echo '<h2>'.($edit?'Edit Pengguna':'Tambah Pengguna').'</h2><form method="post">';
        wp_nonce_field('at_save_user');
        echo '<input type="hidden" name="at_action" value="save_user"/>';
        if($edit){ echo '<input type="hidden" name="id" value="'.intval($edit->id).'"/>'; }
        echo '<table class="form-table">
            <tr><th>Username</th><td><input type="text" name="username" value="'.esc_attr($edit?$edit->username:'').'" required style="min-width:320px"></td></tr>
            <tr><th>Unit Kerja</th><td><select name="unit_id" required style="min-width:320px"><option value="">-- Pilih Unit Kerja --</option>';
            $sel_unit = $edit ? intval($edit->unit_id) : 0;
            foreach($units as $u){
                echo '<option value="'.intval($u->id).'" '.selected($sel_unit,intval($u->id),false).'>'.esc_html($u->name).'</option>';
            }
        echo '</select></td></tr>
            <tr><th>Password</th><td><input type="password" name="password" value="" '.($edit?'':'required').' style="min-width:320px"> <p class="description">'.($edit?'Kosongkan jika tidak diubah.':'Wajib diisi.').'</p></td></tr>
        </table>';

        if($elements){
            echo '<h3>Identitas</h3><table class="form-table">';
            foreach($elements as $el){
                $val = $edit ? ($edit_vals[intval($el->id)] ?? '') : '';
                echo '<tr><th>'.$this->esc($el->field_label).'</th><td>';
                if($el->field_type==='textarea'){
                    echo '<textarea name="id_'.intval($el->id).'" rows="3" style="min-width:420px">'.esc_textarea($val).'</textarea>';
                } elseif($el->field_type==='select'){
                    $opts=$this->parse_options_pairs($el->options_csv);
                    echo '<select name="id_'.intval($el->id).'"><option value="">-- Pilih --</option>';
                    foreach($opts as $op){
                        $val_trim = trim((string)$val);
                        // parse_options_pairs() returns keys: label, value_long
                        $op_val   = trim((string)($op['value_long'] ?? ''));
                        $op_label = trim((string)$op['label']);

                        // value stored could be: value-only (preferred), label-only, or legacy "Label|Value"
                        $is_sel = false;
                        if($val_trim !== ''){
                            $raw = $val_trim;
                            if(strpos($raw,'|')!==false){
                                $parts = explode('|',$raw);
                                $raw = trim(end($parts));
                            }
                            // normalize whitespace & case
                            $norm_val  = strtolower(trim(preg_replace('/\s+/', ' ', (string)$raw)));
                            $norm_oval = strtolower(trim(preg_replace('/\s+/', ' ', (string)$op_val)));
                            $norm_olab = strtolower(trim(preg_replace('/\s+/', ' ', (string)$op_label)));
                            $is_sel = ($norm_val !== '') && ($norm_val === $norm_oval || $norm_val === $norm_olab);
                        }

                        $sel_attr = $is_sel ? ' selected="selected"' : '';
                        echo '<option value="'.esc_attr($op_val).'"'.$sel_attr.'>'.$this->esc($op_label).'</option>';
                    }
                    echo '</select>';
                } else {
                    echo '<input type="text" name="id_'.intval($el->id).'" value="'.esc_attr($val).'" style="min-width:320px">';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="description">Template identitas belum memiliki elemen.</p>';
        }

        echo '<p><button class="button button-primary">Simpan</button></p></form>';

        echo '<hr/><h2>Daftar Pengguna</h2>';
        if($rows){
            echo '<form method="post" onsubmit="if(this.dataset.confirmMsg){return confirm(this.dataset.confirmMsg);} return true;">';
            wp_nonce_field('at_bulk_users');
            echo '<input type="hidden" name="at_action" value="" />';
            echo '<p style="margin:0 0 12px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">'
                .'<button type="submit" class="button" onclick="this.form.at_action.value=&quot;delete_selected_users&quot;; this.form.dataset.confirmMsg=&quot;Hapus pengguna yang dipilih?&quot;;">Hapus Terpilih</button>'
                .'<button type="submit" class="button button-link-delete" onclick="this.form.at_action.value=&quot;delete_all_users&quot;; this.form.dataset.confirmMsg=&quot;Hapus SEMUA pengguna pada kelompok ini?&quot;;">Hapus Semua Data</button>'
                .'<span class="description">Gunakan checkbox untuk memilih beberapa pengguna.</span>'
                .'</p>';
            echo '<div style="overflow:auto; max-width:100%;">';
            echo '<table class="widefat striped"><thead><tr><th style="width:36px"><input type="checkbox" onclick="var c=this.checked; this.closest(&quot;table&quot;).querySelectorAll(&quot;tbody input[name=\&quot;user_ids[]\&quot;]&quot;).forEach(function(el){ el.checked=c; });"></th><th>ID</th><th>Username</th><th>Unit Kerja</th>';
            foreach($elements as $el){
                echo '<th>'.$this->esc($el->field_label).'</th>';
            }
            echo '<th>Aksi</th></tr></thead><tbody>';
            foreach($rows as $r){
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_users&group_id='.$group_id.'&edit='.$r->id);
                $del_url  = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_users&group_id='.$group_id.'&delete='.$r->id), 'at_del_user');
                echo '<tr><td><input type="checkbox" name="user_ids[]" value="'.intval($r->id).'"></td><td>'.intval($r->id).'</td><td>'.$this->esc($r->username).'</td><td>'.$this->esc($r->unit_name ?? '').'</td>';
                foreach($elements as $el){
                    $raw_val = isset($identity_map[intval($r->id)][intval($el->id)]) ? (string)$identity_map[intval($r->id)][intval($el->id)] : '';
                    $show_val = $raw_val;
                    if($el->field_type === 'select' && $raw_val !== ''){
                        $opts = $this->parse_options_pairs($el->options_csv);
                        foreach($opts as $op){
                            $op_val = trim((string)($op['value_long'] ?? ''));
                            $op_lab = trim((string)($op['label'] ?? ''));
                            if((string)$raw_val === $op_val || (string)$raw_val === $op_lab){
                                $show_val = $op_lab;
                                break;
                            }
                        }
                    }
                    echo '<td>'.$this->esc($show_val).'</td>';
                }
                echo '<td>
                    <a class="button" href="'.$edit_url.'">Edit</a>
                    <a class="button at-del" href="'.$del_url.'">Hapus</a>
                </td></tr>';
            }
            echo '</tbody></table></div></form>';
        } else { echo '<p>Belum ada pengguna.</p>'; }
        echo '</div>';
    }
    public function handle_import_users_csv() {
        $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
        $redirect = add_query_arg(array(
            'page' => self::SLUG.'_users',
            'group_id' => $group_id,
        ), admin_url('admin.php'));

        try {
            if (!current_user_can('manage_options')) {
                throw new Exception('Unauthorized');
            }

            if ($group_id <= 0) {
                throw new Exception('group_id tidak valid.');
            }

            $nonce_action = 'at_import_users_csv';
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $nonce_action)) {
                throw new Exception('Nonce invalid.');
            }

            if (!isset($_FILES['users_csv']) || !is_array($_FILES['users_csv'])) {
                throw new Exception('File CSV tidak ditemukan.');
            }
            if ((int)($_FILES['users_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = (int)($_FILES['users_csv']['error'] ?? UPLOAD_ERR_NO_FILE);
                throw new Exception('Upload file CSV gagal. Kode error upload: '.$code);
            }

            $tmp = (string)($_FILES['users_csv']['tmp_name'] ?? '');
            if ($tmp === '' || !file_exists($tmp)) {
                throw new Exception('File temporary CSV tidak ditemukan.');
            }

            $fh = fopen($tmp, 'r');
            if (!$fh) {
                throw new Exception('Gagal membuka file CSV.');
            }

            $sample_line = fgets($fh);
            if ($sample_line === false) {
                fclose($fh);
                throw new Exception('CSV kosong.');
            }
            $candidates = array(
                ','  => substr_count($sample_line, ','),
                ';'  => substr_count($sample_line, ';'),
                "	" => substr_count($sample_line, "	"),
                '|'  => substr_count($sample_line, '|'),
            );
            arsort($candidates);
            $delimiter = array_key_first($candidates);
            if (!is_string($delimiter) || $delimiter === '') { $delimiter = ','; }
            rewind($fh);

            $header = fgetcsv($fh, 0, $delimiter);
            if (!$header) {
                fclose($fh);
                throw new Exception('CSV kosong atau header tidak terbaca.');
            }
            $normalize_header = function($h){
                $h = (string)$h;
                $h = trim($h);
                $h = preg_replace('/^ï»¿/u', '', $h);
                $h = preg_replace('/^ï»¿/', '', $h);
                if (strpos($h, '|') !== false) {
                    $parts = explode('|', $h, 2);
                    $h = trim($parts[0]);
                }
                $h = strtolower($h);
                $h = preg_replace('/\s+/', '_', $h);
                $h = preg_replace('/[^a-z0-9_]/', '', $h);
                return $h;
            };
            $header = array_map($normalize_header, $header);

            $t = $this->tables();
            global $wpdb;

            $template_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT template_id FROM {$t->user_groups} WHERE id=%d",
                $group_id
            ));
            $template = array();
            if ($template_id > 0) {
                $template = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, field_label AS label, field_type AS type, options_csv AS options FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC, id ASC",
                    $template_id
                ));
            }

            $imported = 0;
            $skipped  = 0;
            $errors   = array();

            $rownum = 1;
            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                $rownum++;
                if (isset($row[0]) && is_string($row[0])) {
                    $first = trim($row[0]);
                    if ($first !== '' && strpos($first, '#') === 0) {
                        continue;
                    }
                }
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;

                $data = array();
                foreach ($header as $i => $col) {
                    $data[$col] = isset($row[$i]) ? trim((string)$row[$i]) : '';
                }

                $pick = function(array $source, array $keys){
                    foreach ($keys as $k) {
                        if (array_key_exists($k, $source) && trim((string)$source[$k]) !== '') {
                            return trim((string)$source[$k]);
                        }
                    }
                    return '';
                };

                $username_raw = $pick($data, array('username','user_name','user','nim','no_induk'));
                $password     = $pick($data, array('password','pass','passwd','kata_sandi'));
                $unit_raw     = $pick($data, array('unit_id','unit','unitkerja','unit_kerja','id_unit'));
                $username     = trim($username_raw);
                $unit_id      = is_numeric($unit_raw) ? intval($unit_raw) : 0;

                if ($username === '' || $password === '' || $unit_raw === '' || $unit_id <= 0) {
                    $skipped++;
                    $errors[] = "Baris {$rownum}: kolom wajib (username,password,unit_id) tidak lengkap. Terbaca => username='" . esc_html($username_raw) . "', password_len=" . strlen((string)$password) . ", unit_id='" . esc_html($unit_raw) . "'. Header: " . implode(', ', $header);
                    continue;
                }

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$t->users} WHERE username=%s",
                    $username
                ));
                if ($existing) {
                    $skipped++;
                    $errors[] = "Baris {$rownum}: username '{$username}' sudah ada, baris dilewati.";
                    continue;
                }

                $ok = $wpdb->insert($t->users, array(
                    'group_id'      => $group_id,
                    'unit_id'       => $unit_id,
                    'username'      => $username,
                    'password_hash' => wp_hash_password($password),
                ));
                if (!$ok) {
                    $skipped++;
                    $errors[] = "Baris {$rownum}: gagal menyimpan user '{$username}' - {$wpdb->last_error}";
                    continue;
                }
                $user_row_id = intval($wpdb->insert_id);

                if ($template) {
                    foreach ($template as $el) {
                        $label_raw = (string)$el->label;
                        $label_key = $normalize_header($label_raw);
                        $label_key_loose = strtolower(trim($label_raw));
                        $legacy_key = 'id_'.$el->id;
                        $val = '';
                        if ($label_key !== '' && isset($data[$label_key])) {
                            $val = trim((string)$data[$label_key]);
                        } elseif ($label_key_loose !== '' && isset($data[$label_key_loose])) {
                            $val = trim((string)$data[$label_key_loose]);
                        } elseif (isset($data[$legacy_key])) {
                            $val = trim((string)$data[$legacy_key]);
                        }
                        if ($val === '') continue;

                        if ((string)$el->type === 'select' && !empty($el->options)) {
                            $raw_opts = (string)$el->options;
                            $allowed_values = array();
                            $label_to_value = array();
                            foreach (explode(',', $raw_opts) as $opt) {
                                $opt = trim($opt);
                                if ($opt === '') continue;
                                $parts = explode('|', $opt, 2);
                                if (count($parts) === 2) {
                                    $lbl = trim($parts[0]);
                                    $v   = trim($parts[1]);
                                } else {
                                    $lbl = trim($parts[0]);
                                    $v   = trim($parts[0]);
                                }
                                if ($v !== '') $allowed_values[] = $v;
                                if ($lbl !== '') $label_to_value[strtolower($lbl)] = $v;
                            }
                            if (strpos($val, '|') !== false) {
                                $p = explode('|', $val, 2);
                                $val = trim($p[1]);
                            } else {
                                $lk = strtolower($val);
                                if ($val !== '' && isset($label_to_value[$lk])) {
                                    $val = $label_to_value[$lk];
                                }
                            }
                            if ($val !== '' && !in_array($val, $allowed_values, true)) {
                                $skipped++;
                                $errors[] = "Baris {$rownum}: nilai tidak valid untuk '{$el->label}'. Isi dengan salah satu VALUE yang tersedia.";
                                continue 2;
                            }
                        }

                        $meta_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", $user_row_id, $el->id));
                        if ($meta_id) {
                            $wpdb->update($t->user_identity, array('value_long' => $val), array('id' => $meta_id));
                        } else {
                            $wpdb->insert($t->user_identity, array(
                                'user_id'    => $user_row_id,
                                'element_id' => $el->id,
                                'value_long' => $val,
                            ));
                        }
                    }
                }

                $imported++;
            }

            fclose($fh);

            $result = array(
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => array_slice($errors, 0, 20),
            );
            set_transient('at_import_result_'.get_current_user_id(), $result, 120);
            wp_safe_redirect(add_query_arg(array(
                'page' => self::SLUG.'_users',
                'group_id' => $group_id,
                'import_done' => 1,
            ), admin_url('admin.php')));
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $trace = $e->getTraceAsString();
            set_transient('at_import_error_'.get_current_user_id(), array(
                'message' => $message,
                'trace' => $trace,
            ), 300);
            wp_safe_redirect(add_query_arg(array(
                'page' => self::SLUG.'_users',
                'group_id' => $group_id,
                'import_failed' => 1,
            ), admin_url('admin.php')));
            exit;
        }
    }


    /* ================= Monitoring Pengisian (Admin + Kepala Unit) ================= */
    public function page_participation(){
        if(!current_user_can('read')) return;

        $db=$this->db(); $t=$this->tables();
        $is_admin = current_user_can('manage_options');
        $head_unit_id = $this->get_unit_role_id_for_current_user();

        if(!$is_admin && !$head_unit_id){
            echo '<div class="wrap"><h1>Monitoring Pengisian Survey</h1><p><em>Akun Anda belum ditetapkan sebagai Kepala Unit atau Pengolah Data.</em></p></div>';
            return;
        }

        $page = sanitize_text_field($_GET['page'] ?? self::SLUG.'_participation');

        if($is_admin){
            $runs = $db->get_results("
                SELECT r.id, r.year, r.survey_type, r.unit_id, r.group_id,
                       s.title AS survey_title, u.name AS unit_name, g.name AS group_name
                FROM {$t->runs} r
                JOIN {$t->surveys} s ON s.id=r.survey_id
                JOIN {$t->units} u ON u.id=r.unit_id
                LEFT JOIN {$t->user_groups} g ON g.id=r.group_id
                WHERE r.survey_type<>'general' AND r.group_id IS NOT NULL
                ORDER BY r.id DESC
            ");
        } else {
            $runs = $db->get_results($db->prepare("
                SELECT r.id, r.year, r.survey_type, r.unit_id, r.group_id,
                       s.title AS survey_title, u.name AS unit_name, g.name AS group_name
                FROM {$t->runs} r
                JOIN {$t->surveys} s ON s.id=r.survey_id
                JOIN {$t->units} u ON u.id=r.unit_id
                LEFT JOIN {$t->user_groups} g ON g.id=r.group_id
                WHERE r.survey_type<>'general'
                  AND r.group_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM {$t->users} uu WHERE uu.group_id=r.group_id AND uu.unit_id=%d)
                ORDER BY r.id DESC
            ", $head_unit_id));
        }

        $run_id = intval($_GET['run_id'] ?? ($_POST['run_id'] ?? 0));
        $status = sanitize_text_field($_GET['status'] ?? ($_POST['status'] ?? 'all'));
        if(!in_array($status, array('all','completed','in_progress','not_started','filled','not_filled'), true)) $status = 'all';
        // kompatibilitas filter lama
        if($status==='filled') $status = 'completed';
        if($status==='not_filled') $status = 'not_started';
        $unit_filter = intval($_GET['uf'] ?? ($_POST['uf'] ?? 0));
        if(!$is_admin){
            $unit_filter = $head_unit_id;
        }

        echo '<div class="wrap"><h1>Monitoring Pengisian Survey</h1>';

        echo '<form method="get" style="margin:10px 0 16px;">';
        echo '<input type="hidden" name="page" value="'.esc_attr($page).'"/>';
        echo '<label>Pilih Pelaksanaan (Run): <select name="run_id" onchange="this.form.submit()">';
        echo '<option value="0">-- pilih --</option>';
        foreach($runs as $r){
            $label = $r->survey_title.' — '.$r->unit_name.' / '.$r->year.' (Kelompok: '.($r->group_name?:('#'.$r->group_id)).')';
            echo '<option value="'.intval($r->id).'" '.selected($run_id,$r->id,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label> ';
        echo '<noscript><button class="button">Tampilkan</button></noscript>';
        echo '</form>';

        if(!$run_id){
            echo '<p><em>Pilih pelaksanaan survey untuk melihat daftar target pengguna dan progres pengisian.</em></p></div>';
            return;
        }

        $run = $db->get_row($db->prepare("
            SELECT r.*, s.title AS survey_title, u.name AS unit_name, g.name AS group_name
            FROM {$t->runs} r
            JOIN {$t->surveys} s ON s.id=r.survey_id
            JOIN {$t->units} u ON u.id=r.unit_id
            LEFT JOIN {$t->user_groups} g ON g.id=r.group_id
            WHERE r.id=%d
        ", $run_id));
        if(!$run || $run->survey_type==='general' || !$run->group_id){
            echo '<p><em>Run ini bukan survey pengguna tertentu.</em></p></div>';
            return;
        }

        if(!$is_admin){
            $ok = intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$t->users} uu WHERE uu.group_id=%d AND uu.unit_id=%d", $run->group_id, $head_unit_id)));
            if($ok<=0){
                echo '<p><em>Anda tidak memiliki akses ke pelaksanaan ini.</em></p></div>';
                return;
            }
        }

        // Setiap halaman monitoring dibuka, status isian terakhir per responden disinkronkan ulang.
        // Acuan status monitoring adalah response terbaru pada run yang sama.
        $this->at_recheck_latest_user_responses($run_id);

        $wa_setting = $this->wa_get_active_setting_for_run($run_id);

        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['at_monitor_wa_nonce']) && wp_verify_nonce($_POST['at_monitor_wa_nonce'], 'at_monitor_wa_'.$run_id)){
            $allowed_sql = "SELECT id FROM {$t->users} WHERE group_id=%d";
            $allowed_args = array(intval($run->group_id));
            if($unit_filter){
                $allowed_sql .= " AND unit_id=%d";
                $allowed_args[] = intval($unit_filter);
            }
            if(!$is_admin){
                $allowed_sql .= " AND unit_id=%d";
                $allowed_args[] = intval($head_unit_id);
            }
            $allowed_ids = array_map('intval', (array)$db->get_col($db->prepare($allowed_sql, $allowed_args)));
            $allowed_lookup = array_fill_keys($allowed_ids, true);

            $to_send = array();
            if(isset($_POST['send_single_user_id'])){
                $candidate = intval($_POST['send_single_user_id']);
                if(isset($allowed_lookup[$candidate])) $to_send[] = $candidate;
            } elseif(isset($_POST['send_bulk_wa'])){
                foreach((array)($_POST['user_ids'] ?? array()) as $uid){
                    $uid = intval($uid);
                    if(isset($allowed_lookup[$uid])) $to_send[] = $uid;
                }
            }

            if(!$wa_setting){
                echo '<div class="notice notice-error"><p>Belum ada setting WA-Survey aktif untuk pelaksanaan ini. Silakan buat/aktifkan setting WA-Survey terlebih dahulu.</p></div>';
            } elseif(!$to_send){
                echo '<div class="notice notice-warning"><p>Tidak ada pengguna yang dipilih untuk dikirimi WA.</p></div>';
            } else {
                $send = $this->wa_send_to_users_with_setting($run, $wa_setting, $to_send);
                echo '<div class="notice notice-success"><p>Pengiriman WA selesai. Berhasil: '.intval($send['ok']).' | Dilewati: '.intval($send['skip']).' | Gagal: '.intval($send['fail']).'</p></div>';
                if(!empty($send['notes'])){
                    echo '<div class="notice notice-warning"><p><strong>Catatan:</strong></p><ul>';
                    foreach($send['notes'] as $n){
                        echo '<li>'.$this->esc($n).'</li>';
                    }
                    echo '</ul></div>';
                }
            }
        }

        echo '<div style="margin:10px 0 12px;padding:10px;background:#fff;border:1px solid #ddd;border-radius:8px;">';
        echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">';
        echo '<input type="hidden" name="page" value="'.esc_attr($page).'"/>';
        echo '<input type="hidden" name="run_id" value="'.intval($run_id).'"/>';

        if($is_admin){
            $units = $db->get_results("SELECT * FROM {$t->units} ORDER BY name ASC");
            echo '<label>Unit Kerja<br/><select name="uf">';
            echo '<option value="0" '.selected($unit_filter,0,false).'>Semua Unit</option>';
            foreach($units as $u){
                echo '<option value="'.intval($u->id).'" '.selected($unit_filter,$u->id,false).'>'.$this->esc($u->name).'</option>';
            }
            echo '</select></label>';
        } else {
            $unit_name = $db->get_var($db->prepare("SELECT name FROM {$t->units} WHERE id=%d", $head_unit_id));
            echo '<label>Unit Kerja<br/><input type="text" class="regular-text" value="'.$this->esc($unit_name).'" disabled/></label>';
        }

        echo '<label>Status Pengisian<br/><select name="status">';
        echo '<option value="all" '.selected($status,'all',false).'>Semua</option>';
        echo '<option value="completed" '.selected($status,'completed',false).'>Completed</option>';
        echo '<option value="in_progress" '.selected($status,'in_progress',false).'>In Progress</option>';
        echo '<option value="not_started" '.selected($status,'not_started',false).'>Belum Mulai</option>';
        echo '</select></label>';

        echo '<button class="button button-primary">Filter</button>';
        echo '</form></div>';

        $where = "WHERE us.group_id=%d";
        $args = array($run->group_id);

        $template_id = intval($db->get_var($db->prepare("SELECT template_id FROM {$t->user_groups} WHERE id=%d", $run->group_id)));
        $id_elements = array();
        if($template_id){
            $id_elements = $db->get_results($db->prepare(
                "SELECT id, field_label FROM {$t->id_elements} WHERE template_id=%d AND show_in_results=1 ORDER BY sort_order ASC, id ASC",
                $template_id
            ));
        }

        if($unit_filter){
            $where .= " AND us.unit_id=%d";
            $args[] = $unit_filter;
        }
        if(!$is_admin){
            $where .= " AND us.unit_id=%d";
            $args[] = $head_unit_id;
        }

        // Ambil hanya respons TERAKHIR per responden untuk pelaksanaan yang sama.
        // Ini penting jika satu responden pernah mengisi lebih dari satu kali:
        // status monitoring harus mengikuti isian terakhir, bukan status isian lama.
        $sql = "
            SELECT us.id, us.username, us.unit_id, un.name AS unit_name,
                   lr.id AS response_id,
                   lr.survey_id AS response_survey_id,
                   lr.fill_status AS fill_status,
                   lr.submitted_at AS submitted_at,
                   lr.completed_at AS completed_at
            FROM {$t->users} us
            LEFT JOIN {$t->units} un ON un.id=us.unit_id
            LEFT JOIN (
                SELECT r1.*
                FROM {$t->responses} r1
                INNER JOIN (
                    SELECT user_id, MAX(id) AS latest_id
                    FROM {$t->responses}
                    WHERE run_id=%d AND user_id IS NOT NULL AND user_id > 0
                    GROUP BY user_id
                ) x ON x.latest_id = r1.id
                WHERE r1.run_id=%d
            ) lr ON lr.user_id = us.id
            {$where}
            ORDER BY un.name ASC, us.username ASC
        ";
        array_unshift($args, $run_id, $run_id);
        $users = $db->get_results($db->prepare($sql, $args));

        $identity_map = array();
        if($id_elements && $users){
            $user_ids = array();
            foreach($users as $uu){ $user_ids[] = intval($uu->id); }
            $element_ids = array();
            foreach($id_elements as $ie){ $element_ids[] = intval($ie->id); }

            if($user_ids && $element_ids){
                $uid_ph = implode(',', array_fill(0, count($user_ids), '%d'));
                $eid_ph = implode(',', array_fill(0, count($element_ids), '%d'));
                $sqlI = "SELECT user_id, element_id, value_long FROM {$t->user_identity} WHERE user_id IN ($uid_ph) AND element_id IN ($eid_ph)";
                $idents = $db->get_results($db->prepare($sqlI, array_merge($user_ids, $element_ids)));
                foreach($idents as $it){
                    $uid = intval($it->user_id);
                    $eid = intval($it->element_id);
                    if(!isset($identity_map[$uid])) $identity_map[$uid] = array();
                    $identity_map[$uid][$eid] = (string)$it->value_long;
                }
            }
        }

        $total = 0; $completed = 0; $in_progress = 0; $not_started = 0;
        $rows = array();
        foreach($users as $u){
            $total++;
            $current_status = !empty($u->response_id) ? (string)$u->fill_status : 'not_started';
            if($current_status === 'completed') $completed++;
            elseif($current_status === 'in_progress') $in_progress++;
            else { $current_status = 'not_started'; $not_started++; }
            $u->current_fill_status = $current_status;

            if($status==='completed' && $current_status!=='completed') continue;
            if($status==='in_progress' && $current_status!=='in_progress') continue;
            if($status==='not_started' && $current_status!=='not_started') continue;
            $rows[] = $u;
        }

        $filled = $completed;
        $not_filled = $in_progress + $not_started;
        $pct = ($total>0) ? round(($completed/$total)*100, 1) : 0;
        $wa_logs_map = $this->wa_get_latest_logs_for_run_users($run_id, wp_list_pluck((array)$rows, 'id'));

        echo '<h2 style="margin-top:14px;">'.$this->esc($run->survey_title).' — '.$this->esc($run->unit_name).' / '.$this->esc($run->year).'</h2>';
        echo '<p><strong>Kelompok Pengguna:</strong> '.$this->esc($run->group_name?:('#'.$run->group_id)).'</p>';
        echo '<p><strong>Total target:</strong> '.intval($total).' | <strong>Completed:</strong> '.intval($completed).' ('.$pct.'%) | <strong>In Progress:</strong> '.intval($in_progress).' | <strong>Belum Mulai:</strong> '.intval($not_started).'</p>';
        if($wa_setting){
            echo '<p><strong>WA-Survey aktif:</strong> '.$this->esc($wa_setting->name).' | <strong>Kolom No WA:</strong> '.$this->esc($wa_setting->wa_field_key).'</p>';
        } else {
            echo '<p><em>Belum ada setting WA-Survey aktif untuk pelaksanaan ini.</em></p>';
        }

        echo $this->at_render_donut_status_html($filled, $not_filled);

        echo '<h2>Daftar Pengguna Target</h2>';
        echo '<form method="post">';
        wp_nonce_field('at_monitor_wa_'.$run_id, 'at_monitor_wa_nonce');
        echo '<input type="hidden" name="page" value="'.esc_attr($page).'"/>';
        echo '<input type="hidden" name="run_id" value="'.intval($run_id).'"/>';
        echo '<input type="hidden" name="status" value="'.$this->esc($status).'"/>';
        echo '<input type="hidden" name="uf" value="'.intval($unit_filter).'"/>';

        echo '<p style="margin:8px 0 12px;">';
        echo '<button type="submit" name="send_bulk_wa" value="1" class="button button-primary" '.disabled(!$wa_setting, true, false).'>Kirim WA Terpilih</button> ';
        echo '<span style="color:#666;">Centang beberapa pengguna untuk kirim pesan sesuai template WA-Survey aktif.</span>';
        echo '</p>';

        echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr>';
        echo '<th style="width:36px;"><input type="checkbox" id="at-check-all-users"/></th>';
        echo '<th style="width:50px;">No</th><th>Username</th>';
        if($id_elements){
            foreach($id_elements as $ie){
                echo '<th>'.$this->esc($ie->field_label).'</th>';
            }
        }
        echo '<th>Unit Kerja</th><th>Status</th><th>Waktu Isi</th><th>Pertanyaan Wajib Belum Dijawab</th><th>Status WA</th><th>Waktu WA</th><th>Link Isian Survey</th><th>Aksi</th>';
        echo '</tr></thead><tbody>';

        if(!$rows){
            $colspan = 11 + ($id_elements ? count($id_elements) : 0);
            echo '<tr><td colspan="'.intval($colspan).'"><em>Tidak ada data sesuai filter.</em></td></tr>';
        } else {
            $no=0;
            foreach($rows as $u){
                $no++;
                $uid = intval($u->id);
                $current_status = isset($u->current_fill_status) ? (string)$u->current_fill_status : (!empty($u->response_id) ? (string)$u->fill_status : 'not_started');
                $is_filled = ($current_status === 'completed');
                $link = $this->wa_issue_user_link($run, $uid);
                echo '<tr>';
                echo '<td><input type="checkbox" name="user_ids[]" value="'.$uid.'"/></td>';
                echo '<td>'.intval($no).'</td>';
                echo '<td>'.$this->esc($u->username).'</td>';
                if($id_elements){
                    foreach($id_elements as $ie){
                        $v = $identity_map[$uid][intval($ie->id)] ?? '';
                        $v = trim((string)$v);
                        echo '<td>'.($v!=='' ? $this->esc($v) : '-').'</td>';
                    }
                }
                echo '<td>'.$this->esc($u->unit_name ?: '-').'</td>';
                if($current_status==='completed'){
                    $status_badge = '<span style="color:#0a7;font-weight:600;">Completed</span>';
                    $time_text = !empty($u->completed_at) ? esc_html($u->completed_at) : esc_html($u->submitted_at);
                } elseif($current_status==='in_progress'){
                    $status_badge = '<span style="color:#b26a00;font-weight:600;">In Progress</span>';
                    $time_text = !empty($u->submitted_at) ? esc_html($u->submitted_at) : '-';
                } else {
                    $status_badge = '<span style="color:#b00;">Belum Mulai</span>';
                    $time_text = '-';
                }
                echo '<td>'.$status_badge.'</td>';
                echo '<td>'.$time_text.'</td>';
                $missing_questions = array();
                if(!empty($u->response_id) && $current_status === 'in_progress'){
                    $missing_questions = $this->at_get_unanswered_required_questions_by_flow(
                        intval($u->response_id),
                        !empty($u->response_survey_id) ? intval($u->response_survey_id) : intval($run->survey_id)
                    );
                }
                echo '<td>';
                if($current_status === 'completed'){
                    echo '<span style="color:#666;">-</span>';
                } elseif($current_status === 'not_started'){
                    echo '<span style="color:#666;">Belum mulai mengisi</span>';
                } elseif(empty($missing_questions)){
                    echo '<span style="color:#0a7;font-weight:600;">Tidak ada</span>';
                } else {
                    echo '<details><summary><strong>'.intval(count($missing_questions)).' pertanyaan</strong></summary>';
                    echo '<ol style="margin:6px 0 0 18px;max-width:360px;">';
                    foreach($missing_questions as $mq){
                        $q_text = trim((string)($mq['text'] ?? ''));
                        if($q_text === '') $q_text = 'Pertanyaan ID '.intval($mq['id'] ?? 0);
                        echo '<li>'.$this->esc($q_text).'</li>';
                    }
                    echo '</ol></details>';
                }
                echo '</td>';
                $wa_log = $wa_logs_map[$uid] ?? null;
                $wa_status = $wa_log ? strtolower((string)$wa_log->send_status) : '';
                if($wa_status==='sent'){
                    $wa_badge = '<span style="color:#0a7;font-weight:600;">Terkirim</span>';
                } elseif($wa_status==='failed'){
                    $wa_badge = '<span style="color:#b00;font-weight:600;">Gagal</span>';
                } elseif($wa_status==='invalid'){
                    $wa_badge = '<span style="color:#b26a00;font-weight:600;">No WA tidak valid</span>';
                } else {
                    $wa_badge = '<span style="color:#666;">Belum</span>';
                }
                echo '<td>'.$wa_badge.'</td>';
                echo '<td>'.(!empty($wa_log->sent_at) ? esc_html($wa_log->sent_at) : '-').'</td>';
                echo '<td>';
                if($link!==''){
                    echo '<input type="text" readonly value="'.$this->esc($link).'" style="min-width:320px;max-width:100%;"/><br/>';
                    echo '<a class="button" href="'.esc_url($link).'" target="_blank" rel="noopener">Buka Link</a>';
                } else {
                    echo '-';
                }
                echo '</td>';
                echo '<td>';
                echo '<button type="submit" name="send_single_user_id" value="'.$uid.'" class="button" '.disabled(!$wa_setting, true, false).'>Kirim WA</button>';
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
        echo '</form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var all=document.getElementById("at-check-all-users"); if(!all) return; all.addEventListener("change",function(){document.querySelectorAll("input[name=\\"user_ids[]\\"]").forEach(function(cb){cb.checked=all.checked;});});});</script>';

        echo '</div>';
    }


    /**
     * Render donut status (filled vs not filled) as self-contained SVG (no external JS/CSS).
     * Used on "Monitoring Pengisian Survey" page.
     *
     * @param int $filled
     * @param int $not_filled
     * @return string
     */
    public function at_render_donut_status_html($filled, $not_filled) {
        $filled = intval($filled);
        $not_filled = intval($not_filled);
        $total = max(0, $filled + $not_filled);

        // Avoid divide-by-zero; show empty ring.
        $pct = ($total > 0) ? round(($filled / $total) * 100) : 0;

        $r = 44; // radius
        $c = 2 * M_PI * $r;
        $filled_len = ($total > 0) ? ($c * ($filled / $total)) : 0;
        $gap_len = max(0, $c - $filled_len);

        $html  = '<div class="at-scope" style="max-width:360px;margin:14px 0 18px;">';
        $html .= '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">';

        $html .= '<div style="width:140px;height:140px;position:relative;">';
        $html .= '<svg width="140" height="140" viewBox="0 0 120 120" aria-label="Status pengisian">';
        // Background ring
        $html .= '<circle cx="60" cy="60" r="'.$r.'" fill="none" stroke="#e5e7eb" stroke-width="14"></circle>';
        // Filled ring
        // Start at top by rotating -90deg around center.
        $html .= '<circle cx="60" cy="60" r="'.$r.'" fill="none" stroke="#2563eb" stroke-width="14" stroke-linecap="round"';
        $html .= ' stroke-dasharray="'.round($filled_len,2).' '.round($gap_len,2).'" transform="rotate(-90 60 60)"></circle>';
        $html .= '</svg>';
        // Center label
        $html .= '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;">';
        $html .= '<div style="font-size:28px;font-weight:800;line-height:1;color:#111827;">'.intval($pct).'%</div>';
        $html .= '<div style="font-size:12px;color:#6b7280;margin-top:6px;">Terisi</div>';
        $html .= '</div>';
        $html .= '</div>';

        // Legend / numbers
        $html .= '<div style="min-width:180px;">';
        $html .= '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        $html .= '<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:#2563eb;"></span>';
        $html .= '<span><strong>Sudah mengisi</strong>: '.intval($filled).'</span>';
        $html .= '</div>';
        $html .= '<div style="display:flex;align-items:center;gap:8px;">';
        $html .= '<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:#e5e7eb;border:1px solid #d1d5db;"></span>';
        $html .= '<span><strong>Belum mengisi</strong>: '.intval($not_filled).'</span>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div></div>';

        return $html;
    }


    /* ================= WA Survey settings + Fonnte ================= */
    private function wa_tokenize_label($label){
        $k = sanitize_title((string)$label);
        $k = str_replace('-', '_', $k);
        return $k !== '' ? $k : 'field';
    }

    private function wa_get_group_runs(){
        $db=$this->db(); $t=$this->tables();
        return (array)$db->get_results("SELECT r.*, s.title AS survey_title, un.name AS unit_name, g.name AS group_name
            FROM {$t->runs} r
            JOIN {$t->surveys} s ON s.id=r.survey_id
            LEFT JOIN {$t->units} un ON un.id=r.unit_id
            LEFT JOIN {$t->user_groups} g ON g.id=r.group_id
            WHERE r.survey_type='group' AND r.group_id IS NOT NULL
            ORDER BY r.id DESC");
    }

    private function wa_get_run($run_id){
        $db=$this->db(); $t=$this->tables();
        return $db->get_row($db->prepare("SELECT r.*, s.title AS survey_title, un.name AS unit_name, g.name AS group_name
            FROM {$t->runs} r
            JOIN {$t->surveys} s ON s.id=r.survey_id
            LEFT JOIN {$t->units} un ON un.id=r.unit_id
            LEFT JOIN {$t->user_groups} g ON g.id=r.group_id
            WHERE r.id=%d", intval($run_id)));
    }

    private function wa_get_run_fields($run_id){
        $run = $this->wa_get_run($run_id);
        if(!$run || $run->survey_type !== 'group' || empty($run->group_id)) return array('run'=>null,'all'=>array(),'wa'=>array());
        $db=$this->db(); $t=$this->tables();
        $group = $db->get_row($db->prepare("SELECT * FROM {$t->user_groups} WHERE id=%d", intval($run->group_id)));
        if(!$group) return array('run'=>$run,'all'=>array(),'wa'=>array());
        $els = (array)$db->get_results($db->prepare("SELECT * FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC,id ASC", intval($group->template_id)));
        $all = array(
            'username' => 'Username',
            'unit_id' => 'ID Unit Kerja',
            'unit_name' => 'Nama Unit Kerja',
            'link_survey' => 'Link Pengisian Survey',
        );
        $wa = array('username' => 'Username', 'unit_id' => 'ID Unit Kerja', 'unit_name' => 'Nama Unit Kerja');
        foreach($els as $el){
            $key = $this->wa_tokenize_label($el->field_label);
            $base = $key; $i=2;
            while(isset($all[$key])){ $key = $base.'_'.$i; $i++; }
            $all[$key] = (string)$el->field_label;
            $wa[$key] = (string)$el->field_label;
        }
        return array('run'=>$run,'all'=>$all,'wa'=>$wa);
    }

    private function wa_find_run_page_url($run_id){
        $posts = get_posts(array(
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            's' => 'akurasitara_survey',
            'orderby' => 'ID',
            'order' => 'DESC',
        ));
        foreach((array)$posts as $p){
            $c = (string)$p->post_content;
            if($c === '') continue;
            $pattern = "/\[akurasitara_survey[^\]]*id\s*=\s*[\"'']?".intval($run_id)."[\"'']?[^\]]*\]/i";
            $ok = preg_match($pattern, $c);
            if($ok){
                $u = get_permalink($p);
                if($u) return $u;
            }
        }
        return '';
    }

    private function wa_issue_run_unlock($run){
        if(empty($run->password)) return '';
        $unlock = wp_generate_password(20, false, false);
        set_transient('akurasitara_unlock_' . $unlock, intval($run->id), 7 * DAY_IN_SECONDS);
        return $unlock;
    }

    private function wa_issue_user_link($run, $user_id){
        $page_url = $this->wa_find_run_page_url($run->id);
        if(!$page_url) return '';
        $tok = wp_generate_password(24, false, false);
        set_transient('akurasitara_user_' . $tok, array('run_id'=>intval($run->id),'user_id'=>intval($user_id)), 7 * DAY_IN_SECONDS);
        $args = array('at_user_token'=>$tok, 'at_page'=>1);
        if(!empty($run->password)){
            $unlock = $this->wa_issue_run_unlock($run);
            if($unlock!=='') $args['at_unlock'] = $unlock;
        }
        return add_query_arg($args, $page_url);
    }

    private function wa_get_user_values($run, $user_id){
        $db=$this->db(); $t=$this->tables();
        $fields = $this->wa_get_run_fields($run->id);
        $vals = array();
        $user = $db->get_row($db->prepare("SELECT u.*, un.name AS unit_name FROM {$t->users} u LEFT JOIN {$t->units} un ON un.id=u.unit_id WHERE u.id=%d", intval($user_id)));
        if(!$user) return $vals;
        $vals['username'] = (string)$user->username;
        $vals['unit_id'] = (string)intval($user->unit_id);
        $vals['unit_name'] = (string)($user->unit_name ?? '');
        $vals['link_survey'] = (string)$this->wa_issue_user_link($run, $user_id);
        $group = $db->get_row($db->prepare("SELECT * FROM {$t->user_groups} WHERE id=%d", intval($run->group_id)));
        if($group){
            $els = (array)$db->get_results($db->prepare("SELECT * FROM {$t->id_elements} WHERE template_id=%d ORDER BY sort_order ASC,id ASC", intval($group->template_id)));
            $baseKeys = array('username'=>1,'unit_id'=>1,'unit_name'=>1,'link_survey'=>1);
            foreach($els as $el){
                $key = $this->wa_tokenize_label($el->field_label);
                $base = $key; $i=2;
                while(isset($baseKeys[$key])){ $key = $base.'_'.$i; $i++; }
                $baseKeys[$key]=1;
                $v = $db->get_var($db->prepare("SELECT value_long FROM {$t->user_identity} WHERE user_id=%d AND element_id=%d", intval($user_id), intval($el->id)));
                $vals[$key] = (string)$v;
            }
        }
        return $vals;
    }

    private function wa_get_active_setting_for_run($run_id){
        $db=$this->db(); $t=$this->tables();
        return $db->get_row($db->prepare(
            "SELECT * FROM {$t->wa_settings} WHERE run_id=%d AND is_active=1 ORDER BY updated_at DESC, id DESC LIMIT 1",
            intval($run_id)
        ));
    }

    private function wa_send_to_users_with_setting($run, $setting, $user_ids){
        $db=$this->db(); $t=$this->tables();
        $result = array('ok'=>0,'skip'=>0,'fail'=>0,'notes'=>array());
        $user_ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
        if(!$run || !$setting || empty($user_ids)) return $result;

        foreach($user_ids as $uid){
            $vals = $this->wa_get_user_values($run, $uid);
            $raw_phone = $vals[$setting->wa_field_key] ?? '';
            $phone = $this->wa_normalize_phone($raw_phone, $setting->country_code);
            if($phone===''){
                $result['skip']++;
                $this->wa_log_send($run->id, $uid, $setting->id ?? 0, '', 'invalid', null, 'Nomor WA kosong/tidak valid');
                if(count($result['notes'])<15) $result['notes'][]='User ID '.intval($uid).': nomor WA kosong/tidak valid.';
                continue;
            }
            $msg = $this->wa_render_template($setting->message_template, $vals);
            $resp = $this->wa_send_via_fonnte($setting->fonnte_token, $phone, $msg, array(
                'delay_seconds'=>$setting->delay_seconds,
                'schedule_at'=>$setting->schedule_at,
            ));
            if(is_wp_error($resp)){
                $result['fail']++;
                $this->wa_log_send($run->id, $uid, $setting->id ?? 0, $phone, 'failed', null, $resp->get_error_message());
                if(count($result['notes'])<15) $result['notes'][]='User ID '.intval($uid).': '.$resp->get_error_message();
                continue;
            }
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $json = json_decode($body, true);
            if($code>=200 && $code<300 && (!is_array($json) || !isset($json['status']) || $json['status'])){
                $result['ok']++;
                $this->wa_log_send($run->id, $uid, $setting->id ?? 0, $phone, 'sent', $code, $body);
            } else {
                $result['fail']++;
                $this->wa_log_send($run->id, $uid, $setting->id ?? 0, $phone, 'failed', $code, $body);
                if(count($result['notes'])<15) $result['notes'][]='User ID '.intval($uid).': '.substr((string)$body,0,180);
            }
        }
        return $result;
    }


    private function wa_log_send($run_id, $user_id, $setting_id, $phone, $status, $response_code=null, $response_body=''){
        $db=$this->db(); $t=$this->tables();
        $db->insert($t->wa_logs, array(
            'run_id' => intval($run_id),
            'user_id' => intval($user_id),
            'setting_id' => $setting_id ? intval($setting_id) : null,
            'phone' => (string)$phone,
            'send_status' => sanitize_key((string)$status),
            'response_code' => is_null($response_code) ? null : intval($response_code),
            'response_body' => is_scalar($response_body) ? (string)$response_body : wp_json_encode($response_body),
            'sent_at' => current_time('mysql'),
        ), array('%d','%d','%d','%s','%s','%d','%s','%s'));
    }

    private function wa_get_latest_logs_for_run_users($run_id, $user_ids){
        $db=$this->db(); $t=$this->tables();
        $user_ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
        if(!$run_id || empty($user_ids)) return array();
        $ph = implode(',', array_fill(0, count($user_ids), '%d'));
        $sql = "SELECT l.* FROM {$t->wa_logs} l INNER JOIN (
                    SELECT user_id, MAX(id) AS max_id
                    FROM {$t->wa_logs}
                    WHERE run_id=%d AND user_id IN ($ph)
                    GROUP BY user_id
                ) x ON x.max_id = l.id";
        $rows = $db->get_results($db->prepare($sql, array_merge(array(intval($run_id)), $user_ids)));
        $out = array();
        foreach((array)$rows as $r){ $out[intval($r->user_id)] = $r; }
        return $out;
    }

    private function wa_normalize_phone($raw, $country_code='62'){
        $s = preg_replace('/\D+/', '', (string)$raw);
        if($s==='') return '';
        $cc = preg_replace('/\D+/', '', (string)$country_code);
        if($cc==='') $cc='62';
        if(strpos($s, '0') === 0) return $cc . substr($s,1);
        if(strpos($s, $cc) === 0) return $s;
        return $s;
    }

    private function wa_render_template($template, $values){
        return preg_replace_callback('/@([A-Za-z0-9_]+)/', function($m) use ($values){
            $k = $m[1];
            return isset($values[$k]) ? (string)$values[$k] : $m[0];
        }, (string)$template);
    }

    private function wa_send_via_fonnte($token, $target, $message, $opts=array()){
        $body = array(
            'target'  => (string)$target,
            'message' => (string)$message,
            'domain'  => get_site_url(),
        );
        if(!empty($opts['delay_seconds'])) $body['delay'] = intval($opts['delay_seconds']);
        if(!empty($opts['schedule_at'])){
            $ts = strtotime((string)$opts['schedule_at']);
            if($ts) $body['schedule'] = $ts;
        }
        $args = array(
            'headers' => array('Authorization' => (string)$token),
            'body'    => $body,
            'timeout' => 30,
        );
        return wp_remote_post('https://api.fonnte.com/send', $args);
    }

    public function page_wa_settings(){
        if(!current_user_can('manage_options')) return;
        $db=$this->db(); $t=$this->tables();
        $edit_id = intval($_GET['edit'] ?? 0);
        $preview_run_id = intval($_REQUEST['preview_run_id'] ?? 0);
        $notice = '';

        if(isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'at_wa_del')){
            $db->delete($t->wa_settings, array('id'=>intval($_GET['delete'])));
            echo '<div class="updated"><p>Setting WA-Survey dihapus.</p></div>';
        }

        if(isset($_POST['at_action']) && $_POST['at_action']==='save_wa_setting' && check_admin_referer('at_save_wa_setting')){
            $id = intval($_POST['id'] ?? 0);
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $run_id = intval($_POST['run_id'] ?? 0);
            $wa_field_key = sanitize_key(wp_unslash($_POST['wa_field_key'] ?? ''));
            $message_template = (string)wp_unslash($_POST['message_template'] ?? '');
            $fonnte_token = sanitize_text_field(wp_unslash($_POST['fonnte_token'] ?? ''));
            $country_code = sanitize_text_field(wp_unslash($_POST['country_code'] ?? '62'));
            $delay_seconds = max(0, intval($_POST['delay_seconds'] ?? 0));
            $schedule_at = trim((string)wp_unslash($_POST['schedule_at'] ?? ''));
            $schedule_at = $schedule_at !== '' ? $this->parse_datetime_local_to_mysql($schedule_at) : null;
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            $run_fields = $this->wa_get_run_fields($run_id);
            if($name==='' || !$run_fields['run'] || $wa_field_key==='' || !isset($run_fields['wa'][$wa_field_key]) || trim($message_template)==='' || $fonnte_token===''){
                echo '<div class="error"><p>Data WA-Survey belum lengkap atau pelaksanaan survey tidak valid.</p></div>';
            } else {
                $data = array(
                    'name'=>$name,
                    'run_id'=>$run_id,
                    'wa_field_key'=>$wa_field_key,
                    'message_template'=>$message_template,
                    'fonnte_token'=>$fonnte_token,
                    'country_code'=>$country_code !== '' ? $country_code : '62',
                    'delay_seconds'=>$delay_seconds,
                    'schedule_at'=>$schedule_at,
                    'is_active'=>$is_active,
                    'updated_at'=>current_time('mysql'),
                );
                if($id){
                    $db->update($t->wa_settings, $data, array('id'=>$id));
                    echo '<div class="updated"><p>Setting WA-Survey diperbarui.</p></div>';
                } else {
                    $data['created_at'] = current_time('mysql');
                    $db->insert($t->wa_settings, $data);
                    echo '<div class="updated"><p>Setting WA-Survey disimpan.</p></div>';
                }
                $edit_id = 0;
            }
        }

        if(isset($_POST['at_action']) && $_POST['at_action']==='send_wa_setting' && check_admin_referer('at_send_wa_setting')){
            $sid = intval($_POST['setting_id'] ?? 0);
            $st = $db->get_row($db->prepare("SELECT * FROM {$t->wa_settings} WHERE id=%d", $sid));
            if(!$st){
                echo '<div class="error"><p>Setting tidak ditemukan.</p></div>';
            } else {
                $run = $this->wa_get_run($st->run_id);
                if(!$run || $run->survey_type !== 'group'){
                    echo '<div class="error"><p>Pelaksanaan survey untuk setting ini tidak valid.</p></div>';
                } else {
                    $users = (array)$db->get_results($db->prepare("SELECT id FROM {$t->users} WHERE group_id=%d ORDER BY id ASC", intval($run->group_id)));
                    $ok=0; $skip=0; $fail=0; $notes=array();
                    foreach($users as $u){
                        $vals = $this->wa_get_user_values($run, intval($u->id));
                        $raw_phone = $vals[$st->wa_field_key] ?? '';
                        $phone = $this->wa_normalize_phone($raw_phone, $st->country_code);
                        if($phone===''){
                            $skip++; if(count($notes)<10) $notes[]='User ID '.intval($u->id).': nomor WA kosong.'; continue;
                        }
                        $msg = $this->wa_render_template($st->message_template, $vals);
                        $resp = $this->wa_send_via_fonnte($st->fonnte_token, $phone, $msg, array('delay_seconds'=>$st->delay_seconds,'schedule_at'=>$st->schedule_at));
                        if(is_wp_error($resp)){
                            $fail++; if(count($notes)<10) $notes[]='User ID '.intval($u->id).': '.$resp->get_error_message(); continue;
                        }
                        $code = wp_remote_retrieve_response_code($resp);
                        $body = wp_remote_retrieve_body($resp);
                        $json = json_decode($body, true);
                        if($code>=200 && $code<300 && (!is_array($json) || !isset($json['status']) || $json['status'])){
                            $ok++;
                        } else {
                            $fail++; if(count($notes)<10) $notes[]='User ID '.intval($u->id).': '.substr((string)$body,0,180);
                        }
                    }
                    echo '<div class="updated"><p>Pengiriman selesai. Berhasil: '.intval($ok).' | Dilewati: '.intval($skip).' | Gagal: '.intval($fail).'</p></div>';
                    if($notes){
                        echo '<div class="notice notice-warning"><p><strong>Catatan:</strong></p><ul>';
                        foreach($notes as $n) echo '<li>'.$this->esc($n).'</li>';
                        echo '</ul></div>';
                    }
                }
            }
        }

        $edit = $edit_id ? $db->get_row($db->prepare("SELECT * FROM {$t->wa_settings} WHERE id=%d", $edit_id)) : null;
        if($edit && !$preview_run_id) $preview_run_id = intval($edit->run_id);
        $preview = $preview_run_id ? $this->wa_get_run_fields($preview_run_id) : array('run'=>null,'all'=>array(),'wa'=>array());
        $runs = $this->wa_get_group_runs();
        $rows = (array)$db->get_results("SELECT ws.*, r.year, r.survey_type, s.title AS survey_title, un.name AS unit_name, g.name AS group_name
            FROM {$t->wa_settings} ws
            LEFT JOIN {$t->runs} r ON r.id=ws.run_id
            LEFT JOIN {$t->surveys} s ON s.id=r.survey_id
            LEFT JOIN {$t->units} un ON un.id=r.unit_id
            LEFT JOIN {$t->user_groups} g ON g.id=r.group_id
            ORDER BY ws.id DESC");

        echo '<div class="wrap"><h1>WA-Survey Setting</h1>';
        echo '<p>Kelola template pengiriman WhatsApp untuk pelaksanaan survey dengan jenis <strong>pengguna tertentu</strong>. Gunakan token seperti <code>@nama</code> atau <code>@link_survey</code> di template pesan.</p>';
        echo '<h2>'.($edit?'Edit Setting':'Tambah Setting').'</h2>';
        echo '<form method="post" style="max-width:1000px">';
        wp_nonce_field('at_save_wa_setting');
        echo '<input type="hidden" name="at_action" value="save_wa_setting">';
        if($edit) echo '<input type="hidden" name="id" value="'.intval($edit->id).'">';
        echo '<table class="form-table">';
        echo '<tr><th>Nama Setting</th><td><input type="text" name="name" value="'.esc_attr($edit->name ?? '').'" required style="min-width:360px"></td></tr>';
        echo '<tr><th>Pelaksanaan Survey</th><td><select name="run_id" onchange="this.form.preview_run_id.value=this.value; this.form.submit();" required style="min-width:460px"><option value="">-- pilih pelaksanaan survey --</option>';
        foreach($runs as $r){
            $sel = selected($preview_run_id, intval($r->id), false);
            $label = $r->survey_title.' — '.($r->unit_name ?: '-').' / '.$r->year.' ('.($r->group_name ?: 'Pengguna').')';
            echo '<option value="'.intval($r->id).'" '.$sel.'>'.$this->esc($label).'</option>';
        }
        echo '</select><input type="hidden" name="preview_run_id" value="'.intval($preview_run_id).'"></td></tr>';
        if($preview['run']){
            echo '<tr><th>Placeholder tersedia</th><td>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            foreach($preview['all'] as $k=>$lbl){
                echo '<span style="display:inline-block;background:#f0f4ff;border:1px solid #c9d4ff;border-radius:999px;padding:6px 10px;"><code>@'.$this->esc($k).'</code> = '.$this->esc($lbl).'</span>';
            }
            echo '</div>';
            echo '<p class="description" style="margin-top:8px">Gunakan token diawali <code>@</code>. Contoh: <code>Hai @nama, silakan isi survey di @link_survey</code>.</p>';
            echo '</td></tr>';
        }
        echo '<tr><th>Kolom No WA</th><td><select name="wa_field_key" required style="min-width:320px"><option value="">-- pilih kolom nomor WA --</option>';
        foreach(($preview['wa'] ?? array()) as $k=>$lbl){
            $selected_key = $edit->wa_field_key ?? '';
            echo '<option value="'.esc_attr($k).'" '.selected($selected_key, $k, false).'>'.$this->esc($lbl).' (@'.$this->esc($k).')</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Template Pesan</th><td><textarea name="message_template" rows="8" required style="min-width:680px;max-width:100%;">'.esc_textarea($edit->message_template ?? '').'</textarea></td></tr>';
        echo '<tr><th>Token / API Key Fonnte</th><td><input type="text" name="fonnte_token" value="'.esc_attr($edit->fonnte_token ?? '').'" required style="min-width:420px"></td></tr>';
        echo '<tr><th>Country Code</th><td><input type="text" name="country_code" value="'.esc_attr($edit->country_code ?? '62').'" style="width:90px"> <span class="description">Dipakai untuk mengubah nomor yang diawali 0 menjadi 62.</span></td></tr>';
        echo '<tr><th>Delay (detik)</th><td><input type="number" name="delay_seconds" min="0" value="'.intval($edit->delay_seconds ?? 0).'" style="width:120px"></td></tr>';
        $sched = '';
        if(!empty($edit->schedule_at)) $sched = esc_attr(str_replace(' ', 'T', substr((string)$edit->schedule_at,0,16)));
        echo '<tr><th>Schedule</th><td><input type="datetime-local" name="schedule_at" value="'.$sched.'"> <span class="description">Kosongkan untuk kirim langsung.</span></td></tr>';
        echo '<tr><th>Aktif?</th><td><label><input type="checkbox" name="is_active" value="1" '.checked(intval($edit->is_active ?? 1),1,false).'> Ya</label></td></tr>';
        echo '</table>';
        echo '<p><button type="submit" class="button button-primary">Simpan Setting</button>';
        if($edit) echo ' <a class="button" href="'.admin_url('admin.php?page='.self::SLUG.'_wa_settings').'">Batal</a>';
        echo '</p></form>';

        echo '<hr><h2>Daftar Setting WA-Survey</h2>';
        if($rows){
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nama</th><th>Pelaksanaan Survey</th><th>Kolom WA</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
            foreach($rows as $r){
                $label = ($r->survey_title ?: 'Run #'.$r->run_id).' — '.($r->unit_name ?: '-').' / '.($r->year ?: '-').' ('.($r->group_name ?: 'Pengguna').')';
                $edit_url = admin_url('admin.php?page='.self::SLUG.'_wa_settings&edit='.intval($r->id));
                $del_url  = wp_nonce_url(admin_url('admin.php?page='.self::SLUG.'_wa_settings&delete='.intval($r->id)), 'at_wa_del');
                echo '<tr><td>'.intval($r->id).'</td><td>'.$this->esc($r->name).'</td><td>'.$this->esc($label).'</td><td><code>@'.$this->esc($r->wa_field_key).'</code></td><td>'.(intval($r->is_active)?'Aktif':'Nonaktif').'</td><td>';
                echo '<a class="button" href="'.$edit_url.'">Edit</a> ';
                echo '<a class="button at-del" href="'.$del_url.'">Hapus</a> ';
                echo '<form method="post" style="display:inline-block;margin-left:6px" onsubmit="return confirm(\'Kirim WA sekarang berdasarkan setting ini?\');">';
                wp_nonce_field('at_send_wa_setting');
                echo '<input type="hidden" name="at_action" value="send_wa_setting"><input type="hidden" name="setting_id" value="'.intval($r->id).'">';
                echo '<button type="submit" class="button button-primary">Kirim Sekarang</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Belum ada setting WA-Survey.</p>';
        }
        echo '</div>';
    }


}

new AkurasiTara();
/* =========================================================
 * Extension: Laporan DSL Role Access
 * File laporan dipisah agar plugin utama tetap aman.
 * ========================================================= */
if ( file_exists( plugin_dir_path(__FILE__) . 'laporan/akurasitara-laporan-dsl.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'laporan/akurasitara-laporan-dsl.php';
}
