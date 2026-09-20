<?php
/** AkurasiTara Ext Reports - Universal Excel DSL Engine, Smart Auto Text Parser & Role Management */
if (!defined('ABSPATH')) exit;

class AkurasiTara_Ext_Reports_DSL {
    const SLUG_REPORT = 'akurasitara_ext_reports';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_akurasitara_save_ump_table', array($this, 'ajax_save_ump_table'));
        add_action('wp_ajax_akurasitara_switch_ref_table', array($this, 'ajax_switch_ref_table'));
        add_action('wp_ajax_akurasitara_delete_ref_table', array($this, 'ajax_delete_ref_table'));
        add_action('admin_post_at_ext_report_export', array($this, 'handle_export'));
        add_action('admin_init', array($this, 'handle_form_actions'));
    }

    private function db() { global $wpdb; return $wpdb; }
    private function tables() {
        $p = $this->db()->prefix . 'akurasitara_';
        return (object) array('runs'=>$p.'runs','surveys'=>$p.'surveys','questions'=>$p.'questions','answers'=>$p.'answers','responses'=>$p.'responses','users'=>$p.'users','unit_heads'=>$p.'unit_heads','data_processors'=>$p.'data_processors','reports'=>$p.'reports','report_sections'=>$p.'report_sections','units'=>$p.'units');
    }

    private function ensure_reports_tables() {
        $db = $this->db(); $t = $this->tables();
        $db->query("CREATE TABLE IF NOT EXISTS {$t->reports} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            question_ids TEXT NULL,
            formula TEXT NULL,
            calc_method VARCHAR(50) DEFAULT 'dsl',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) DEFAULT CHARSET=utf8mb4;");

        $db->query("CREATE TABLE IF NOT EXISTS {$t->report_sections} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            formula TEXT NOT NULL,
            sort_order INT(11) DEFAULT 1,
            show_in_table INT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY report_id (report_id)
        ) DEFAULT CHARSET=utf8mb4;");

        $cols = $db->get_results("SHOW COLUMNS FROM {$t->report_sections} LIKE 'show_in_table'");
        if (empty($cols)) {
            $db->query("ALTER TABLE {$t->report_sections} ADD COLUMN show_in_table INT(1) DEFAULT 1;");
        }
    }

    public function add_admin_menu() {
        add_submenu_page('akurasitara', 'Laporan DSL & Acuan', 'Laporan DSL & Acuan', 'read', self::SLUG_REPORT, array($this, 'page_reports'));
    }

    public function can_manage_reports() {
        if (!is_user_logged_in()) return false;
        if (current_user_can('administrator') || current_user_can('manage_options')) return true;
        $uid = get_current_user_id(); $t = $this->tables(); $db = $this->db();
        return ($db->get_var($db->prepare("SELECT unit_id FROM {$t->unit_heads} WHERE wp_user_id=%d LIMIT 1", $uid)) || $db->get_var($db->prepare("SELECT unit_id FROM {$t->data_processors} WHERE wp_user_id=%d LIMIT 1", $uid))) ? true : false;
    }

    public function all_runs() {
        $t = $this->tables();
        return (array) $this->db()->get_results("SELECT r.*, COALESCE(s.title, r.run_name, CONCAT('Run #', r.id)) AS survey_title, u.name AS unit_name FROM {$t->runs} r LEFT JOIN {$t->surveys} s ON r.survey_id=s.id LEFT JOIN {$t->units} u ON r.unit_id=u.id ORDER BY r.id DESC");
    }

    public function run_questions($run_id) {
        $t = $this->tables(); $db = $this->db();
        $run = $db->get_row($db->prepare("SELECT survey_id, id FROM {$t->runs} WHERE id=%d", $run_id));
        if (!$run) return array();
        $sid = intval($run->survey_id ?? 0) ?: intval($run->id);
        return (array) $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC", $sid));
    }

    public function question_code($run_id, $qid) {
        foreach ($this->run_questions($run_id) as $idx => $q) { if (intval($q->id) === intval($qid)) return 'P' . ($idx + 1); }
        return 'Q' . $qid;
    }

    private function get_qtype_label($qtype) {
        switch ($qtype) {
            case 'radio':
                return '<span style="background:#f0fdf4;color:#166534;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Pilihan Tunggal (Radio)</span>';
            case 'select':
                return '<span style="background:#f0fdf4;color:#166534;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Dropdown (Select)</span>';
            case 'checkbox':
                return '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Pilihan Ganda (Checkbox)</span>';
            case 'number':
                return '<span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Angka / Nominal</span>';
            case 'date':
                return '<span style="background:#fce7f3;color:#9d174d;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Tanggal</span>';
            case 'textarea':
                return '<span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Teks Panjang (Textarea)</span>';
            case 'label':
                return '<span style="background:#e2e8f0;color:#334155;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Label / Heading</span>';
            case 'text':
            default:
                return '<span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Teks Singkat (Text)</span>';
        }
    }

    private function format_options_display($options_csv) {
        $raw = trim((string)$options_csv);
        if ($raw === '') return '<span style="color:#94a3b8;font-size:11px;font-style:italic">Isian bebas / teks</span>';
        $parts = preg_split('/[\r\n,]+/', $raw);
        $badges = array();
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (strpos($p, '|') !== false) {
                $sub = explode('|', $p);
                $p = trim($sub[1] ?? $sub[0]);
            }
            $badges[] = '<span class="at-pill" style="cursor:default;font-size:11px;margin:2px 2px">' . esc_html($p) . '</span>';
        }
        return !empty($badges) ? implode(' ', $badges) : '<span style="color:#94a3b8;font-size:11px;font-style:italic">Isian bebas / teks</span>';
    }

    public function render_questions_table($questions, $run_id) {
        echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:18px">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">';
        echo '<h4 style="margin:0;font-size:13px;color:#0f172a;display:flex;align-items:center;gap:6px">📋 <strong>Daftar & Keterangan Pertanyaan Survey (Kode Kolom P1, P2, dst.)</strong></h4>';
        echo '<span style="font-size:11px;color:#0284c7;background:#e0f2fe;padding:3px 10px;border-radius:12px;font-weight:600">' . count($questions) . ' Pertanyaan</span>';
        echo '</div>';
        
        echo '<div style="overflow-x:auto"><table class="at-table" style="background:#fff;border:1px solid #cbd5e1;border-radius:6px;width:100%">';
        echo '<thead><tr>';
        echo '<th style="width:70px;text-align:center;background:#f1f5f9;font-weight:700">Kode</th>';
        echo '<th style="width:40%;background:#f1f5f9;font-weight:700">Isi / Teks Pertanyaan</th>';
        echo '<th style="width:20%;background:#f1f5f9;font-weight:700">Tipe Input</th>';
        echo '<th style="width:35%;background:#f1f5f9;font-weight:700">Pilihan Jawaban / Opsi</th>';
        echo '</tr></thead><tbody>';

        if (!empty($questions)) {
            foreach ($questions as $q) {
                $code = $this->question_code($run_id, $q->id);
                $qtype_label = $this->get_qtype_label($q->qtype ?? 'text');
                $options_html = $this->format_options_display($q->options_csv ?? '');

                echo '<tr>';
                echo '<td style="text-align:center"><span style="background:#0284c7;color:#fff;font-weight:700;font-size:12px;padding:3px 8px;border-radius:6px;display:inline-block">' . esc_html($code) . '</span></td>';
                echo '<td><strong style="color:#1e293b">' . esc_html($q->question_text) . '</strong>' . (!empty($q->is_required) ? ' <span style="color:#ef4444;font-weight:bold" title="Wajib Diisi">*</span>' : '') . '</td>';
                echo '<td>' . $qtype_label . '</td>';
                echo '<td>' . $options_html . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4" style="text-align:center;color:#64748b;padding:16px">Tidak ada pertanyaan pada survey ini.</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // CRUD TABEL ACUAN
    public function get_ref_tables_data() {
        $saved = get_option('akurasitara_saved_ref_tables', null);
        if (is_array($saved) && isset($saved['tables']) && is_array($saved['tables'])) {
            return $saved;
        }
        $def = array('active_id'=>'none', 'tables'=>array('ref_default'=>array('id'=>'ref_default','title'=>'Tabel Acuan UMP Provinsi','question_code'=>'P4','data'=>array('banyuwangi'=>1000000,'jember'=>2000000,'bali'=>3000000,'malang'=>4000000))));
        update_option('akurasitara_saved_ref_tables', $def); return $def;
    }

    public function get_ump_settings() {
        $d = $this->get_ref_tables_data(); $aid = $d['active_id'] ?? 'none';
        if ($aid === 'none' || empty($d['tables'][$aid])) return array('id'=>'none','title'=>'Tanpa Tabel Acuan','question_code'=>'','data'=>array());
        return $d['tables'][$aid];
    }

    public function ajax_save_ump_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $d = $this->get_ref_tables_data(); $tid = sanitize_key($_POST['table_id'] ?? '');
        if (!$tid && empty($_POST['is_new']) && !empty($d['active_id']) && $d['active_id'] !== 'none') $tid = $d['active_id'];
        if (!$tid) $tid = 'ref_' . uniqid();
        $dec = json_decode(wp_unslash($_POST['ump_data'] ?? ''), true); $san = array();
        if (is_array($dec)) { foreach ($dec as $k => $v) { $k = sanitize_text_field($k); if ($k !== '') $san[$k] = is_numeric($v) ? floatval($v) : sanitize_text_field($v); } }
        $d['tables'][$tid] = array('id'=>$tid, 'title'=>sanitize_text_field(wp_unslash($_POST['ref_column_title'] ?? 'Tabel Acuan')), 'question_code'=>sanitize_text_field(wp_unslash($_POST['ref_question_code'] ?? 'P4')), 'data'=>$san);
        $d['active_id'] = $tid; update_option('akurasitara_saved_ref_tables', $d); wp_send_json_success(array('message'=>'Disimpan.', 'all_data'=>$d));
    }

    public function ajax_switch_ref_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $tid = sanitize_key($_POST['table_id'] ?? ''); $d = $this->get_ref_tables_data();
        if ($tid === 'none' || isset($d['tables'][$tid])) { $d['active_id'] = $tid; update_option('akurasitara_saved_ref_tables', $d); wp_send_json_success(); }
        wp_send_json_error();
    }

    public function ajax_delete_ref_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $tid = sanitize_key($_POST['table_id'] ?? ''); $d = $this->get_ref_tables_data();
        if (isset($d['tables'][$tid])) unset($d['tables'][$tid]);
        $d['active_id'] = empty($d['tables']) ? 'none' : (($d['active_id'] === $tid) ? array_key_first($d['tables']) : $d['active_id']);
        update_option('akurasitara_saved_ref_tables', $d); wp_send_json_success();
    }

    // AUTO EVALUATOR MATRIKS IKU 1 (POIN B, C, D, E, F)
    public function evaluate_iku1_respondent_category($answers_str, $ump_settings = array()) {
        $str = strtolower(trim((string)$answers_str));
        $is_layak = (strpos($str, 'layak') !== false || strpos($str, '> 1') !== false || strpos($str, '3 juta') !== false || strpos($str, '4 juta') !== false || strpos($str, '5 juta') !== false);
        
        // Poin E / F: Sebelum Lulus
        if (strpos($str, 'sebelum') !== false) {
            return $is_layak ? array('cat'=>'Poin E1/F1 (Sebelum Lulus Layak)', 'k'=>1.0) : array('cat'=>'Poin E2/F2 (Sebelum Lulus Std)', 'k'=>0.6);
        }
        
        // Poin C1: Founder / Co-Founder
        if (strpos($str, 'founder') !== false || strpos($str, 'wirausaha') !== false) {
            if (strpos($str, '< 6') !== false || strpos($str, '1 bulan') !== false || strpos($str, '2 bulan') !== false) {
                return $is_layak ? array('cat'=>'Poin C1.a (Founder Fast Layak)', 'k'=>1.2) : array('cat'=>'Poin C1.c (Founder Fast UMP)', 'k'=>0.8);
            }
            return $is_layak ? array('cat'=>'Poin C1.b (Founder Std Layak)', 'k'=>1.0) : array('cat'=>'Poin C1.d (Founder Std UMP)', 'k'=>0.6);
        }
        
        // Poin C2: Freelancer
        if (strpos($str, 'freelance') !== false || strpos($str, 'lepas') !== false) {
            if (strpos($str, '< 6') !== false || strpos($str, '1 bulan') !== false || strpos($str, '2 bulan') !== false) {
                return $is_layak ? array('cat'=>'Poin C2.a (Freelance Fast Layak)', 'k'=>0.5) : array('cat'=>'Poin C2.c (Freelance Fast UMP)', 'k'=>0.3);
            }
            return $is_layak ? array('cat'=>'Poin C2.b (Freelance Std Layak)', 'k'=>0.4) : array('cat'=>'Poin C2.d (Freelance Std UMP)', 'k'=>0.2);
        }
        
        // Poin D: Studi Lanjut
        if (strpos($str, 'studi') !== false || strpos($str, 's2') !== false || strpos($str, 'kuliah') !== false) {
            return array('cat'=>'Poin D1 (Studi Lanjut <12 Bln)', 'k'=>0.6);
        }
        
        // Poin B: Bekerja (Default)
        if (strpos($str, '< 6') !== false || strpos($str, '1 bulan') !== false || strpos($str, '2 bulan') !== false) {
            return $is_layak ? array('cat'=>'Poin B1 (Bekerja Fast Layak)', 'k'=>1.0) : array('cat'=>'Poin B3 (Bekerja Std UMP)', 'k'=>0.6);
        }
        if (strpos($str, '6-12') !== false || strpos($str, '8 bulan') !== false || strpos($str, '9 bulan') !== false) {
            return $is_layak ? array('cat'=>'Poin B2 (Bekerja Std Layak)', 'k'=>0.8) : array('cat'=>'Poin B3 (Bekerja Std UMP)', 'k'=>0.6);
        }

        return $is_layak ? array('cat'=>'Poin B1 (Bekerja Fast Layak)', 'k'=>1.0) : array('cat'=>'Poin B3 (Bekerja Std UMP)', 'k'=>0.6);
    }

    // EVALUATOR SMART DENGAN CONVERT OTOMATIS TEKS (BULAN, JUTA, UMP KOTA)
    public function evaluate_single_respondent_column($formula, $res_data, $questions = array(), $ump_settings = array()) {
        $f = trim((string)$formula); $f = ltrim($f, '=');
        if ($f === '') return '-';

        $qid_map = array();
        if (!empty($questions)) {
            foreach ($questions as $idx => $q) {
                $code = 'P' . ($idx + 1);
                $qid_map[$code] = intval($q->id);
            }
        }

        $ans_combine = array();
        foreach ($res_data['answers'] as $q_ans) {
            $ans_combine[] = implode(' | ', $q_ans);
        }
        $ans_str = implode(' | ', $ans_combine);

        // Extract P2 (Masa Tunggu)
        $p2_val = 0;
        $p2_text = isset($qid_map['P2']) && isset($res_data['answers'][$qid_map['P2']]) ? implode(' ', $res_data['answers'][$qid_map['P2']]) : '';
        if (strpos(strtolower($p2_text), 'sebelum') !== false) {
            $p2_val = 0;
        } else {
            if (preg_match('/(\d+)\s*bulan/i', $p2_text, $m)) $p2_val = floatval($m[1]);
            else $p2_val = floatval(preg_replace('/[^0-9\.]/', '', $p2_text));
        }

        // Extract P3 (Gaji Nominal Rupiah)
        $p3_val = 0;
        $p3_text = isset($qid_map['P3']) && isset($res_data['answers'][$qid_map['P3']]) ? implode(' ', $res_data['answers'][$qid_map['P3']]) : '';
        if (preg_match('/([\d\.]+)\s*juta/i', $p3_text, $m)) $p3_val = floatval($m[1]) * 1000000;
        elseif (is_numeric(preg_replace('/[^0-9\.]/', '', $p3_text))) {
            $raw_p3 = floatval(preg_replace('/[^0-9\.]/', '', $p3_text));
            $p3_val = ($raw_p3 < 100) ? ($raw_p3 * 1000000) : $raw_p3;
        }

        // Extract P4 (Kota -> Nominal UMP)
        $p4_val = 1000000;
        $p4_text = isset($qid_map['P4']) && isset($res_data['answers'][$qid_map['P4']]) ? implode(' ', $res_data['answers'][$qid_map['P4']]) : '';
        $p4_key = strtolower(trim($p4_text));
        if (isset($ump_settings['data'][$p4_key]) && is_numeric($ump_settings['data'][$p4_key])) {
            $p4_val = floatval($ump_settings['data'][$p4_key]);
        }

        $expr = $f;
        $expr = preg_replace('/\bP2\b/i', $p2_val, $expr);
        $expr = preg_replace('/\bP3\b/i', $p3_val, $expr);
        $expr = preg_replace('/\bP4\b/i', $p4_val, $expr);

        // Clean & Fix IF syntax
        $expr = preg_replace_callback('/IF\(([^,]+),([^,]+),([^)]+)\)/i', function($m) {
            $cond = trim($m[1]);
            $cond = str_replace('=', '==', $cond);
            $cond = str_replace('====', '==', $cond);
            $cond = str_replace('>==', '>=', $cond);
            $cond = str_replace('<==', '<=', $cond);
            return '((' . $cond . ') ? (' . trim($m[2]) . ') : (' . trim($m[3]) . '))';
        }, $expr);

        $clean_expr = preg_replace('/[^0-9\+\-\*\/\.\(\)\?\:\>\<\=\!\s]/', '', $expr);
        
        // Auto balance parentheses
        $open_c = substr_count($clean_expr, '(');
        $close_c = substr_count($clean_expr, ')');
        if ($close_c > $open_c) {
            $diff = $close_c - $open_c;
            for ($i = 0; $i < $diff; $i++) {
                $pos = strrpos($clean_expr, ')');
                if ($pos !== false) $clean_expr = substr_replace($clean_expr, '', $pos, 1);
            }
        }

        $res = 0;
        if (trim($clean_expr) !== '') {
            try { @eval('$res = ' . $clean_expr . ';'); } catch (Throwable $e) { $res = 0; }
        }

        if ($res <= 0) {
            $eval = $this->evaluate_iku1_respondent_category($ans_str, $ump_settings);
            return number_format($eval['k'], 2, ',', '.');
        }

        return number_format(floatval($res), 2, ',', '.');
    }

    // MESIN FORMULA DSL EXCEL UNIVERSAL
    public function evaluate_dsl_formula($formula, $survey_values, $ump_data = array(), $questions = array(), $run_id = 0) {
        $f = trim((string)$formula); $f = ltrim($f, '='); $tot = count($survey_values);
        if ($tot <= 0) return '0';
        if ($f === '' || strtoupper($f) === 'COUNT()') return $tot . ' Responden';

        $qid_map = array();
        if (!empty($questions)) {
            foreach ($questions as $idx => $q) {
                $code = 'P' . ($idx + 1);
                $qid_map[$code] = intval($q->id);
                $qid_map['Q' . $q->id] = intval($q->id);
            }
        }

        $get_col_vals = function($col_code) use ($survey_values, $qid_map, $ump_data) {
            $vals = array();
            $col_code = strtoupper(trim($col_code));
            
            if (strpos($col_code, '+') !== false) {
                $parts = explode('+', $col_code);
                $q1 = $qid_map[trim($parts[0])] ?? 0;
                $q2 = $qid_map[trim($parts[1])] ?? 0;
                foreach ($survey_values as $r) {
                    $a1 = ($q1 > 0 && isset($r['answers'][$q1])) ? implode(' | ', $r['answers'][$q1]) : '';
                    $a2 = ($q2 > 0 && isset($r['answers'][$q2])) ? implode(' | ', $r['answers'][$q2]) : '';
                    $combo = trim($a1 . ' | ' . $a2);
                    $eval = $this->evaluate_iku1_respondent_category($combo, array('id'=>'active','data'=>$ump_data));
                    $vals[] = $eval['k'];
                }
                return $vals;
            }

            $qid = $qid_map[$col_code] ?? 0;
            foreach ($survey_values as $r) {
                $ans = ($qid > 0 && isset($r['answers'][$qid])) ? $r['answers'][$qid] : array();
                $ans_str = implode(' | ', $ans);
                $eval = $this->evaluate_iku1_respondent_category($ans_str, array('id'=>'active','data'=>$ump_data));
                $vals[] = $eval['k'];
            }
            return $vals;
        };

        $expr = $f;
        $expr = preg_replace_callback('/COUNT\(\s*\)/i', function() use ($tot) { return $tot; }, $expr);

        $expr = preg_replace_callback('/IF\(([^,]+),([^,]+),([^)]+)\)/i', function($m) {
            $cond = trim($m[1]); $t_v = trim($m[2]); $f_v = trim($m[3]);
            return '((' . $cond . ') ? (' . $t_v . ') : (' . $f_v . '))';
        }, $expr);

        $expr = preg_replace_callback('/COUNTIF\(\s*([A-Z0-9_\+]+)\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i', function($m) use ($survey_values, $qid_map) {
            $col = strtoupper($m[1]); $target = $m[2]; $qid = $qid_map[$col] ?? 0; $cnt = 0;
            foreach ($survey_values as $r) {
                $ans = ($qid > 0 && isset($r['answers'][$qid])) ? $r['answers'][$qid] : array();
                foreach ($ans as $a) { if (strcasecmp(trim($a), $target) === 0) { $cnt++; break; } }
            }
            return $cnt;
        }, $expr);

        $expr = preg_replace_callback('/PERCENT\(\s*([A-Z0-9_\+]+)\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i', function($m) use ($survey_values, $qid_map, $tot) {
            $col = strtoupper($m[1]); $target = $m[2]; $qid = $qid_map[$col] ?? 0; $cnt = 0;
            foreach ($survey_values as $r) {
                $ans = ($qid > 0 && isset($r['answers'][$qid])) ? $r['answers'][$qid] : array();
                foreach ($ans as $a) { if (strcasecmp(trim($a), $target) === 0) { $cnt++; break; } }
            }
            return $tot > 0 ? (($cnt / $tot) * 100) : 0;
        }, $expr);

        $expr = preg_replace_callback('/SUM\(([^)]+)\)/i', function($m) use ($get_col_vals) {
            $cols = explode(',', $m[1]); $sum = 0;
            foreach ($cols as $c) { $v = $get_col_vals($c); $sum += array_sum($v); }
            return $sum;
        }, $expr);

        $expr = preg_replace_callback('/(AVERAGE|AVG)\(([^)]+)\)/i', function($m) use ($get_col_vals) {
            $cols = explode(',', $m[2]); $all = array();
            foreach ($cols as $c) { $all = array_merge($all, $get_col_vals($c)); }
            return !empty($all) ? (array_sum($all) / count($all)) : 0;
        }, $expr);

        $expr = preg_replace_callback('/MIN\(([^)]+)\)/i', function($m) use ($get_col_vals) {
            $cols = explode(',', $m[1]); $all = array();
            foreach ($cols as $c) { $all = array_merge($all, $get_col_vals($c)); }
            return !empty($all) ? min($all) : 0;
        }, $expr);

        $expr = preg_replace_callback('/MAX\(([^)]+)\)/i', function($m) use ($get_col_vals) {
            $cols = explode(',', $m[1]); $all = array();
            foreach ($cols as $c) { $all = array_merge($all, $get_col_vals($c)); }
            return !empty($all) ? max($all) : 0;
        }, $expr);

        $expr = preg_replace_callback('/\b(P\d+|Q\d+)\b/i', function($m) use ($get_col_vals) {
            $v = $get_col_vals($m[1]);
            return !empty($v) ? (array_sum($v) / count($v)) : 0;
        }, $expr);

        $expr = str_replace('%', '/100', $expr);
        $clean_expr = preg_replace('/[^0-9\+\-\*\/\.\(\)\?\:\>\<\=\!\s]/', '', $expr);
        $result = 0;
        if (trim($clean_expr) !== '') {
            try { @eval('$result = ' . $clean_expr . ';'); } catch (Throwable $e) { $result = 0; }
        }

        if (!is_numeric($result) || is_nan($result) || is_infinite($result)) return '0';
        $has_pct = (strpos(strtoupper($formula), '%') !== false || strpos(strtoupper($formula), 'PERCENT') !== false);
        return number_format(floatval($result), 2, ',', '.') . ($has_pct ? '%' : '');
    }

    // DATA RESPONDEN & MATRIX
    public function collect_values($run_id, $question_ids = array()) {
        if (intval($run_id) <= 0) return array();
        $t = $this->tables(); $db = $this->db();
        $sql = $db->prepare("SELECT a.response_id, a.question_id, a.answer_text, COALESCE(u.username, CONCAT('Responden #', r.id)) AS full_name FROM {$t->answers} a JOIN {$t->responses} r ON a.response_id=r.id LEFT JOIN {$t->users} u ON r.user_id=u.id WHERE r.run_id=%d", $run_id);
        if (!empty($question_ids)) $sql .= " AND a.question_id IN (" . implode(',', array_map('intval', $question_ids)) . ")";
        $rows = (array) $db->get_results($sql . " ORDER BY a.response_id ASC");
        $res = array();
        foreach ($rows as $r) {
            $rid = intval($r->response_id); $qid = intval($r->question_id);
            if (!isset($res[$rid])) $res[$rid] = array('name' => $r->full_name, 'answers' => array());
            if (!isset($res[$rid]['answers'][$qid])) $res[$rid]['answers'][$qid] = array();
            $res[$rid]['answers'][$qid][] = $r->answer_text;
        }
        return $res;
    }

    public function build_detail_table_data($run_id, $survey_values, $question_ids_survey) {
        $headers = array('No', 'Nama'); foreach ($question_ids_survey as $qid) $headers[] = $this->question_code($run_id, $qid) . ' (Pertanyaan ' . substr($this->question_code($run_id, $qid), 1) . ')';
        $headers[] = 'Hasil'; $rows = array(); $idx = 1;
        foreach ($survey_values as $res) {
            $row = array($idx++, $res['name']); foreach ($question_ids_survey as $qid) $row[] = isset($res['answers'][$qid]) ? implode(' | ', $res['answers'][$qid]) : '-';
            $row[] = '-'; $rows[] = $row;
        }
        return array('headers' => $headers, 'rows' => $rows);
    }

    public function handle_form_actions() {
        if (!isset($_POST['at_action']) || $_POST['at_action'] !== 'save_report' || !$this->can_manage_reports() || !check_admin_referer('at_ext_save_report')) return;
        $this->ensure_reports_tables();
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $run_id = intval($_POST['run_id'] ?? 0); $report_id = intval($_POST['id'] ?? 0);
        if (!$name || $run_id <= 0) wp_die('Nama laporan dan survey wajib diisi.');
        $t = $this->tables(); $db = $this->db();
        $q_ids = array_column($this->run_questions($run_id), 'id');
        $secs = $_POST['sections'] ?? array();
        $first_f = !empty($secs[0]['formula']) ? sanitize_text_field(wp_unslash($_POST['formula'] ?? ($secs[0]['formula'] ?? 'COUNT()'))) : 'COUNT()';
        $data = array('name'=>$name, 'run_id'=>$run_id, 'question_ids'=>json_encode($q_ids), 'formula'=>$first_f, 'calc_method'=>'dsl', 'updated_at'=>current_time('mysql'));
        
        if ($report_id > 0) {
            $db->update($t->reports, $data, array('id'=>$report_id));
        } else {
            $data['created_at'] = current_time('mysql');
            $res = $db->insert($t->reports, $data);
            if ($res === false) {
                $db->query($db->prepare("INSERT INTO {$t->reports} (name, run_id, formula, calc_method, created_at, updated_at) VALUES (%s, %d, %s, %s, %s, %s)", $name, $run_id, $first_f, 'dsl', current_time('mysql'), current_time('mysql')));
                $report_id = $db->insert_id;
            } else { $report_id = $db->insert_id; }
        }

        if ($report_id > 0) {
            $db->delete($t->report_sections, array('report_id'=>$report_id));
            if (is_array($secs)) {
                $s_idx = 1;
                foreach ($secs as $s) {
                    if (empty($s['title']) && empty($s['formula'])) continue;
                    $db->insert($t->report_sections, array(
                        'report_id'     => $report_id,
                        'title'         => sanitize_text_field(wp_unslash($s['title'] ?? ('DSL #'.$s_idx))),
                        'formula'       => sanitize_text_field(wp_unslash($s['formula'] ?? 'COUNT()')),
                        'show_in_table' => !empty($s['show_in_table']) ? 1 : 0,
                        'sort_order'    => $s_idx++
                    ));
                }
            }
        }
        wp_redirect(admin_url('admin.php?page=' . self::SLUG_REPORT . '&message=saved')); exit;
    }

    public function handle_export() {
        if (!$this->can_manage_reports()) wp_die('Akses ditolak.');
        check_admin_referer('at_ext_report_export');
        $run_id = intval($_GET['run_id'] ?? 1); $q_ids = array_column($this->run_questions($run_id), 'id');
        $detail = $this->build_detail_table_data($run_id, $this->collect_values($run_id, $q_ids), $q_ids);
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=Laporan_Export_'.date('Ymd_His').'.csv');
        $out = fopen('php://output', 'w'); fputcsv($out, $detail['headers']);
        foreach ($detail['rows'] as $r) fputcsv($out, $r);
        fclose($out); exit;
    }

    // ADMIN VIEWS
    public function page_reports() {
        if (!$this->can_manage_reports()) { echo '<div class="notice notice-error"><p>Akses ditolak.</p></div>'; return; }
        $this->ensure_reports_tables();
        $mode = sanitize_key($_GET['mode'] ?? '');
        $action = sanitize_key($_GET['action'] ?? '');
        $view_id = intval($_GET['id'] ?? 0);
        $edit_id = intval($_GET['edit'] ?? 0);
        $del_id = intval($_GET['delete'] ?? 0);

        if ($del_id > 0 && check_admin_referer('at_ext_delete_report_' . $del_id)) {
            $t = $this->tables(); $this->db()->delete($t->reports, array('id'=>$del_id)); $this->db()->delete($t->report_sections, array('report_id'=>$del_id));
            echo '<div class="notice notice-success"><p>Laporan dihapus.</p></div>';
        }

        if (isset($_GET['message']) && $_GET['message'] === 'saved') {
            echo '<div class="notice notice-success is-dismissible" style="padding:12px 16px;margin:15px 0"><p style="margin:0;font-size:14px;font-weight:bold;color:#15803d">✅ Laporan Berhasil Disimpan!</p></div>';
        }

        echo '<div class="wrap" style="max-width:1200px;margin:20px auto">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;background:#fff;padding:16px 20px;border-radius:10px;border:1px solid #e2e8f0"><h1 style="margin:0;font-size:20px">Laporan DSL & Tabel Acuan Value</h1><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_REPORT . '&action=create_report')) . '">+ Buat Laporan Baru</a></div>';

        // MODE VIEW LAPORAN
        if ($mode === 'view' && $view_id > 0) {
            $this->render_report_view($view_id);
        }
        // MODE EDIT / FORM LAPORAN
        elseif ($action === 'create_report' || $mode === 'edit' || $edit_id > 0) {
            $edit_report = ($edit_id > 0) ? $this->db()->get_row($this->db()->prepare("SELECT * FROM {$this->tables()->reports} WHERE id=%d", $edit_id)) : null;
            $selected_run = intval($_GET['run_id'] ?? ($edit_report->run_id ?? 0));
            echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px;margin-bottom:24px">';
            $this->render_report_form($edit_report, $selected_run);
            echo '</div>';
        }

        $this->render_reports_list();
        echo '</div>';
    }

    // LIST LAPORAN TERSIMPAN
    private function render_reports_list() {
        $this->ensure_reports_tables();
        $t = $this->tables();
        $reports = (array) $this->db()->get_results("SELECT * FROM {$t->reports} ORDER BY id DESC");
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px"><h3 style="margin:0 0 14px 0">Daftar Laporan Tersimpan</h3>';
        if (empty($reports)) { echo '<p style="color:#64748b;margin:0">Belum ada laporan tersimpan.</p></div>'; return; }
        echo '<table class="widefat striped" style="border:none"><thead><tr><th>ID</th><th>Judul Laporan</th><th>Survey Terkait</th><th>Tanggal Dibuat</th><th style="text-align:right">Aksi</th></tr></thead><tbody>';
        foreach ($reports as $r) {
            $run_id = intval($r->run_id);
            $run_info = $this->db()->get_row($this->db()->prepare("SELECT r.*, s.title AS survey_title, u.name AS unit_name FROM {$t->runs} r LEFT JOIN {$t->surveys} s ON r.survey_id=s.id LEFT JOIN {$t->units} u ON r.unit_id=u.id WHERE r.id=%d", $run_id));
            $s_name = $run_info ? (($run_info->survey_title ?: 'Survey') . ' — ' . ($run_info->unit_name ?: 'Unit') . ' (Run #' . $run_id . ')') : ('Run #' . $run_id);

            $del_url = wp_nonce_url(admin_url('admin.php?page=' . self::SLUG_REPORT . '&delete=' . intval($r->id)), 'at_ext_delete_report_' . intval($r->id));
            $edit_url = admin_url('admin.php?page=' . self::SLUG_REPORT . '&mode=edit&edit=' . intval($r->id) . '&run_id=' . $run_id);
            $view_url = admin_url('admin.php?page=' . self::SLUG_REPORT . '&mode=view&id=' . intval($r->id));

            echo '<tr><td>#' . intval($r->id) . '</td><td><strong style="color:#0284c7;font-size:14px">' . esc_html($r->name) . '</strong></td><td><span style="background:#f1f5f9;color:#334155;padding:3px 8px;border-radius:6px;font-size:12px">' . esc_html($s_name) . '</span></td><td>' . esc_html(date('d M Y H:i', strtotime($r->created_at ?? current_time('mysql')))) . '</td><td style="text-align:right"><a class="button button-primary button-small" href="' . esc_url($view_url) . '">👁️ Lihat Hasil Laporan</a> <a class="button button-small" href="' . esc_url($edit_url) . '">✏️ Edit</a> <a class="button button-small" href="' . esc_url($del_url) . '" onclick="return confirm(\'Hapus laporan ini?\')" style="color:#d63638">🗑️ Hapus</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // MODE VIEW HASIL LAPORAN (SMART PARSER RESPONDEN)
    private function render_report_view($report_id) {
        $t = $this->tables(); $db = $this->db();
        $report = $db->get_row($db->prepare("SELECT * FROM {$t->reports} WHERE id=%d", $report_id));
        if (!$report) { echo '<div class="notice notice-error"><p>Laporan tidak ditemukan.</p></div>'; return; }

        $run_id = intval($report->run_id);
        $run_info = $db->get_row($db->prepare("SELECT r.*, s.title AS survey_title, u.name AS unit_name FROM {$t->runs} r LEFT JOIN {$t->surveys} s ON r.survey_id=s.id LEFT JOIN {$t->units} u ON r.unit_id=u.id WHERE r.id=%d", $run_id));
        $s_name = $run_info ? (($run_info->survey_title ?: 'Survey') . ' — ' . ($run_info->unit_name ?: '-')) : ('Run #' . $run_id);

        $questions = $this->run_questions($run_id);
        $q_ids = array_column($questions, 'id');
        $survey_values = $this->collect_values($run_id, $q_ids);
        $detail_data = $this->build_detail_table_data($run_id, $survey_values, $q_ids);
        $ump_settings = $this->get_ump_settings();
        $sections = (array) $db->get_results($db->prepare("SELECT * FROM {$t->report_sections} WHERE report_id=%d ORDER BY sort_order ASC, id ASC", $report_id));

        $calc_cols = array_filter($sections, function($sec){ return !empty($sec->show_in_table); });

        echo '<div style="background:#fff;border:1px solid #0284c7;border-radius:10px;padding:24px;margin-bottom:24px">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;border-bottom:2px solid #e0f2fe;padding-bottom:12px">';
        echo '<div><h2 style="margin:0;color:#0369a1">📄 ' . esc_html($report->name) . '</h2><p style="margin:4px 0 0 0;color:#64748b;font-size:13px">Survey: <strong>' . esc_html($s_name) . ' (Run #' . $run_id . ')</strong> | Total Responden: <strong>' . count($detail_data['rows']) . '</strong></p></div>';
        echo '<div style="display:flex;gap:8px"><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_REPORT)) . '">← Kembali ke Daftar</a> <a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=at_ext_report_export&run_id=' . $run_id), 'at_ext_report_export')) . '">📥 Export CSV</a></div>';
        echo '</div>';

        // TABEL 1: RINGKASAN HASIL PERHITUNGAN DSL
        echo '<div style="margin-bottom:24px"><h3 style="margin:0 0 12px 0;font-size:15px;color:#0f172a">📊 Ringkasan Hasil Perhitungan & Formulasi DSL</h3>';
        echo '<table class="widefat striped" style="border:1px solid #cbd5e1"><thead><tr><th style="width:30%">Judul Seksi Perhitungan</th><th style="width:45%">Formulasi Rumus DSL / Excel</th><th style="width:25%;text-align:right">Hasil Akhir Kalkulasi</th></tr></thead><tbody>';
        if (!empty($sections)) {
            foreach ($sections as $sec) {
                $res_val = $this->evaluate_dsl_formula($sec->formula, $survey_values, $ump_settings['data'] ?? array(), $questions, $run_id);
                echo '<tr><td><strong>' . esc_html($sec->title) . '</strong></td><td><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px">' . esc_html($sec->formula) . '</code></td><td style="text-align:right"><span style="background:#e0f2fe;color:#0369a1;font-weight:bold;font-size:13px;padding:4px 10px;border-radius:8px">' . esc_html($res_val) . '</span></td></tr>';
            }
        } else {
            $res_val = $this->evaluate_dsl_formula('COUNT()', $survey_values, $ump_settings['data'] ?? array(), $questions, $run_id);
            echo '<tr><td><strong>Total Responden</strong></td><td><code>COUNT()</code></td><td style="text-align:right"><span style="background:#e0f2fe;color:#0369a1;font-weight:bold;font-size:13px;padding:4px 10px;border-radius:8px">' . esc_html($res_val) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';

        // TABEL 2: DAFTAR PERTANYAAN SURVEY
        $this->render_questions_table($questions, $run_id);

        // TABEL 3: SHOW TABEL SEMUA DATA RESPONDEN (DENGAN SMART PARSER DISAMPING P4)
        echo '<div><h3 style="margin:0 0 12px 0;font-size:15px;color:#0f172a">📋 Tabel Data Survey Responden & Kolom Perhitungan / Bobot Dinamis</h3>';
        echo '<div style="overflow-x:auto"><table class="widefat striped" style="border:1px solid #cbd5e1"><thead><tr>';
        echo '<th>No</th><th>Nama</th>';
        foreach ($questions as $q) {
            $code = $this->question_code($run_id, $q->id);
            $q_len = function_exists('mb_strlen') ? mb_strlen($q->question_text) : strlen($q->question_text);
            $short_txt = $q_len > 28 ? (function_exists('mb_substr') ? mb_substr($q->question_text, 0, 26) : substr($q->question_text, 0, 26)) . '...' : $q->question_text;
            echo '<th title="' . esc_attr($code . ': ' . $q->question_text) . '"><span style="color:#0284c7;font-weight:700">' . esc_html($code) . '</span><br><span style="font-size:10px;color:#64748b;font-weight:normal">' . esc_html($short_txt) . '</span></th>';
        }
        
        if (!empty($calc_cols)) {
            foreach ($calc_cols as $cc) {
                echo '<th style="background:#dcfce7;color:#166534;font-weight:bold">📊 ' . esc_html($cc->title) . '</th>';
            }
        }
        echo '</tr></thead><tbody>';
        
        if (!empty($detail_data['rows'])) {
            $row_idx = 1;
            foreach ($survey_values as $r_id => $r_data) {
                echo '<tr><td>' . ($row_idx++) . '</td><td>' . esc_html($r_data['name']) . '</td>';
                foreach ($q_ids as $qid) {
                    echo '<td>' . esc_html(isset($r_data['answers'][$qid]) ? implode(' | ', $r_data['answers'][$qid]) : '-') . '</td>';
                }
                if (!empty($calc_cols)) {
                    foreach ($calc_cols as $cc) {
                        $val = $this->evaluate_single_respondent_column($cc->formula, $r_data, $questions, $ump_settings);
                        echo '<td style="font-weight:bold;color:#166534;background:#f0fdf4"><span style="background:#dcfce7;padding:3px 8px;border-radius:6px">' . esc_html($val) . '</span></td>';
                    }
                }
                echo '</tr>';
            }
        } else { echo '<tr><td colspan="' . (count($questions) + 2 + count($calc_cols)) . '" style="text-align:center;color:#64748b">Belum ada data responden.</td></tr>'; }
        
        echo '</tbody></table></div></div></div>';
    }

    private function render_report_form($edit = null, $selected_run = 0) {
        $runs = $this->all_runs(); if ($edit && !$selected_run) $selected_run = intval($edit->run_id);
        $is_edit = !empty($edit) && !empty($edit->id); $questions = $selected_run ? $this->run_questions($selected_run) : array();
        $report_name = isset($_GET['name']) ? sanitize_text_field(wp_unslash($_GET['name'])) : ($edit->name ?? '');

        echo '<style>.at-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px}.at-title{font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;margin:0 0 14px 0}.at-num{background:#0284c7;color:#fff;min-width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}.at-table{width:100%;border-collapse:collapse;font-size:13px;margin:0}.at-table th{background:#f8fafc;color:#334155;font-weight:600;padding:10px 14px;text-align:left;border-bottom:2px solid #e2e8f0}.at-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9}.at-pill{display:inline-block;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;margin-right:4px;margin-bottom:4px;user-select:none}.at-pill:hover{background:#bae6fd}</style>';
        echo '<form method="post" id="at_main_report_form" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG_REPORT . ($is_edit ? '&mode=edit&edit=' . intval($edit->id) : ''))) . '">';
        wp_nonce_field('at_ext_save_report'); echo '<input type="hidden" name="at_action" value="save_report">'; if ($is_edit) echo '<input type="hidden" name="id" value="' . intval($edit->id) . '">';

        // STEP 1
        echo '<div class="at-card"><h3 class="at-title"><span class="at-num">1</span> Pengaturan Utama Laporan</h3>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">';
        echo '<div><label style="font-weight:600;font-size:13px">Nama Laporan:</label><input type="text" name="name" value="' . esc_attr($report_name) . '" required placeholder="Contoh: Laporan IKU 1 2026" style="width:100%"></div>';
        echo '<div><label style="font-weight:600;font-size:13px">Pilih Survey:</label><select name="run_id" id="at_run_id" required style="width:100%"><option value="">-- Pilih Survey --</option>';
        foreach ($runs as $r) echo '<option value="' . intval($r->id) . '" ' . selected($selected_run, intval($r->id), false) . '>' . esc_html(($r->survey_title ?: 'Survey') . ' — ' . ($r->unit_name ?: '-') . ' (Run #' . $r->id . ')') . '</option>';
        echo '</select></div></div></div>';

        if (!$selected_run) {
            echo '<div class="at-card" style="text-align:center;padding:24px;background:#f8fafc;border:1px dashed #cbd5e1"><p style="margin:0;color:#64748b">Silakan Pilih Survey Terlebih Dahulu di Step 1.</p></div>';
        } else {
            $q_ids = array_column($questions, 'id'); $survey_values = $this->collect_values($selected_run, $q_ids);
            $detail_data = $this->build_detail_table_data($selected_run, $survey_values, $q_ids);
            $ump_settings = $this->get_ump_settings();
            $existing_sections = $is_edit ? (array)$this->db()->get_results($this->db()->prepare("SELECT * FROM {$this->tables()->report_sections} WHERE report_id=%d ORDER BY sort_order ASC, id ASC", $edit->id)) : array();

            $q_choices_map = array();
            foreach ($questions as $q) {
                $code = $this->question_code($selected_run, $q->id); $choices = array();
                if (!empty($q->options_csv)) { foreach (explode(',', $q->options_csv) as $p) { $p = trim($p); if ($p !== '') { $parts = explode('|', $p); $choices[] = trim($parts[0]); } } }
                foreach ($survey_values as $res_data) { if (isset($res_data['answers'][$q->id])) { foreach ($res_data['answers'][$q->id] as $a) { $a = trim($a); if ($a !== '' && $a !== '-') $choices[] = $a; } } }
                $q_choices_map[$code] = array_values(array_unique($choices));
            }

            // STEP 2: TABEL DATA SURVEY RESPONDEN (HANYA MURNI DATA SURVEY + PREVIEW KOLOM PERHITUNGAN HASIL CRUD)
            $calc_cols = array_filter($existing_sections, function($sec){ return !empty($sec->show_in_table); });

            echo '<div class="at-card"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><h3 class="at-title" style="margin:0"><span class="at-num">2</span> Tabel Data Survey Responden & Kolom Perhitungan / Bobot (CRUD)</h3><span style="font-size:12px;color:#0284c7;background:#e0f2fe;padding:4px 10px;border-radius:12px;font-weight:600">' . count($detail_data['rows']) . ' Responden</span></div>';
            
            // 1. TAMPILKAN TABEL DAFTAR PERTANYAAN SURVEY
            $this->render_questions_table($questions, $selected_run);

            // 2. TAMPILKAN TABEL DATA RESPONDEN
            echo '<h4 style="margin:16px 0 10px 0;font-size:13px;color:#0f172a;display:flex;align-items:center;gap:6px">👥 <strong>Data Responden & Preview Kolom Bobot Dinamis</strong></h4>';
            echo '<div style="overflow-x:auto"><table class="at-table"><thead><tr>';
            echo '<th>No</th><th>Nama</th>';
            foreach ($questions as $q) {
                $code = $this->question_code($selected_run, $q->id);
                $q_len = function_exists('mb_strlen') ? mb_strlen($q->question_text) : strlen($q->question_text);
                $short_txt = $q_len > 28 ? (function_exists('mb_substr') ? mb_substr($q->question_text, 0, 26) : substr($q->question_text, 0, 26)) . '...' : $q->question_text;
                echo '<th title="' . esc_attr($code . ': ' . $q->question_text) . '"><span style="color:#0284c7;font-weight:700">' . esc_html($code) . '</span><br><span style="font-size:10px;color:#64748b;font-weight:normal">' . esc_html($short_txt) . '</span></th>';
            }
            if (!empty($calc_cols)) {
                foreach ($calc_cols as $cc) {
                    echo '<th style="background:#dcfce7;color:#166534">📊 ' . esc_html($cc->title) . '</th>';
                }
            }
            echo '</tr></thead><tbody>';
            if (!empty($detail_data['rows'])) {
                $row_idx = 1;
                foreach ($survey_values as $r_id => $r_data) {
                    echo '<tr><td>' . ($row_idx++) . '</td><td>' . esc_html($r_data['name']) . '</td>';
                    foreach ($q_ids as $qid) {
                        echo '<td>' . esc_html(isset($r_data['answers'][$qid]) ? implode(' | ', $r_data['answers'][$qid]) : '-') . '</td>';
                    }
                    if (!empty($calc_cols)) {
                        foreach ($calc_cols as $cc) {
                            $val = $this->evaluate_single_respondent_column($cc->formula, $r_data, $questions, $ump_settings);
                            echo '<td style="font-weight:bold;color:#166534;background:#f0fdf4"><span style="background:#dcfce7;padding:3px 8px;border-radius:6px">' . esc_html($val) . '</span></td>';
                        }
                    }
                    echo '</tr>';
                }
            } else { echo '<tr><td colspan="' . (count($questions) + 2 + count($calc_cols)) . '" style="text-align:center;color:#64748b">Belum ada data responden.</td></tr>'; }
            echo '</tbody></table></div></div>';

            // STEP 3 (ACUAN VALUE / MULTI-KOLOM MATRIX BOBOT)
            $all_ref = $this->get_ref_tables_data(); $active_id = $all_ref['active_id'] ?? 'none';
            $saved_qcode = $ump_settings['question_code'] ?? 'P4'; $active_choices = $q_choices_map[$saved_qcode] ?? array();

            echo '<div class="at-card"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><h3 class="at-title" style="margin:0"><span class="at-num">3</span> Tabel Acuan Value & Matriks Pembobotan Multi-Kolom</h3><div style="display:flex;gap:8px"><button type="button" class="button at-disable-ref-btn">🚫 Nonaktifkan Tabel</button> <button type="button" class="button button-primary" id="at_add_ref_btn">+ Buat Tabel Baru</button></div></div>';
            echo '<table class="at-table" style="margin-bottom:16px"><thead><tr><th>Nama Tabel Acuan</th><th>Pertanyaan Acuan</th><th>Status</th><th style="text-align:right">Aksi Tabel</th></tr></thead><tbody>';
            foreach ($all_ref['tables'] as $tid => $tconf) {
                $is_act = ($tid === $active_id);
                echo '<tr><td><strong>' . esc_html($tconf['title']) . '</strong></td><td><code>' . esc_html($tconf['question_code'] ?? 'P4') . '</code></td><td>' . ($is_act ? '<span style="background:#dcfce7;color:#166534;font-size:11px;padding:3px 8px;border-radius:10px;font-weight:700">🟢 Aktif</span>' : '<button type="button" class="button button-small at-switch-btn" data-id="' . esc_attr($tid) . '">Gunakan</button>') . '</td><td style="text-align:right"><button type="button" class="button button-small at-del-ref-btn" data-id="' . esc_attr($tid) . '" style="color:#d63638">🗑️ Hapus Tabel</button></td></tr>';
            }
            echo '<tr style="' . ($active_id === 'none' ? 'background:#f8fafc' : '') . '"><td><em>Tanpa Tabel Acuan (Nonaktif)</em></td><td>-</td><td>' . ($active_id === 'none' ? '<span style="background:#f1f5f9;color:#475569;font-size:11px;padding:3px 8px;border-radius:10px;font-weight:700">⚪ Nonaktif</span>' : '<button type="button" class="button button-small at-disable-ref-btn">Pilih Nonaktif</button>') . '</td><td style="text-align:right">-</td></tr>';
            echo '</tbody></table>';

            if ($active_id !== 'none') {
                echo '<div style="border:1px solid #cbd5e1;background:#f8fafc;border-radius:8px;padding:16px">';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px"><h4 style="margin:0">📝 Edit Isian Matriks & Pembobotan (' . esc_html($ump_settings['title']) . ')</h4><button type="button" class="button button-primary" id="at_save_ref_btn">💾 Simpan Tabel</button></div>';
                echo '<div style="display:flex;gap:16px;margin-bottom:12px"><div><label style="font-weight:600;font-size:12px">Nama Tabel:</label><br><input type="text" id="at_ref_title" value="' . esc_attr($ump_settings['title']) . '" style="width:200px"></div><div><label style="font-weight:600;font-size:12px">Pertanyaan Acuan (Single / Kombinasi Multi-Kolom):</label><br><select id="at_ref_qselect">';
                foreach ($questions as $q) { $code = $this->question_code($selected_run, $q->id); echo '<option value="' . esc_attr($code) . '" ' . selected($saved_qcode, $code, false) . '>' . esc_html($code . ' — Pertanyaan ' . substr($code, 1)) . '</option>'; }
                if (count($questions) >= 2) {
                    echo '<option value="P2+P3" ' . selected($saved_qcode, 'P2+P3', false) . '>P2 + P3 (Kombinasi Masa Tunggu & Gaji UMP)</option>';
                }
                echo '</select></div></div>';
                echo '<p style="margin:0 0 10px 0;font-size:12px;color:#0284c7">Masukkan nilai <strong>UMP Provinsi (Rupiah)</strong> atau <strong>Bobot Point ($k_i$)</strong> pada kolom kanan.</p>';
                echo '<table class="at-table"><thead><tr><th>Kriteria / Jawaban Pertanyaan (Kolom Kiri)</th><th>Input Value UMP / Bobot Point k_i (Kolom Kanan)</th><th style="text-align:right">Aksi Baris</th></tr></thead><tbody id="at_ref_items_tbody">';
                $rendered = array();
                if (!empty($active_choices)) { foreach ($active_choices as $cText) { $rendered[] = $cText; echo '<tr><td><strong class="at-ump-key-label" data-key="' . esc_attr($cText) . '">' . esc_html($cText) . '</strong></td><td><input type="text" class="at-ump-inp" data-key="' . esc_attr($cText) . '" value="' . esc_attr($ump_settings['data'][$cText] ?? '') . '" style="width:100%" placeholder="Contoh UMP: 1.000.000"></td><td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️ Hapus Baris</button></td></tr>'; } }
                if (!empty($ump_settings['data'])) { foreach ($ump_settings['data'] as $k => $v) { if (!in_array($k, $rendered, true)) echo '<tr><td><strong class="at-ump-key-label" data-key="' . esc_attr($k) . '">' . esc_html($k) . '</strong></td><td><input type="text" class="at-ump-inp" data-key="' . esc_attr($k) . '" value="' . esc_attr($v) . '" style="width:100%" placeholder="Contoh UMP: 1.000.000"></td><td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️ Hapus Baris</button></td></tr>'; } }
                echo '</tbody></table></div>';
            }
            echo '</div>';

            // STEP 4: PERHITUNGAN & CRUD KOLOM PERHITUNGAN DINAMIS (SEBELAH KANAN P4)
            $tot_resp = count($detail_data['rows']);

            echo '<div class="at-card">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><h3 class="at-title" style="margin:0"><span class="at-num">4</span> Perhitungan & CRUD Kolom Perhitungan / Bobot (Sebelah Kanan P4)</h3>';
            echo '<div style="display:flex;gap:8px"><button type="button" class="button" id="at_apply_iku1_template" style="background:#fef3c7;color:#92400e;border-color:#fde68a;font-weight:700">🏆 Sisip Otomatis Template IKU 1</button> <button type="button" class="button button-primary" id="at_add_dsl_sec_btn">+ Tambah Kolom Perhitungan Baru (CRUD)</button></div>';
            echo '</div>';

            // KALKULATOR RESPONDEN MINIMUM SLOVIN
            echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin-bottom:16px">';
            echo '<h4 style="margin:0 0 8px 0;color:#0369a1;font-size:14px">🎯 Ambang Batas Minimum Responden Lulusan (Rumus Slovin Galat 2,3%)</h4>';
            echo '<p style="margin:0 0 10px 0;font-size:12px;color:#0284c7">Masukkan jumlah populasi total alumni/lulusan untuk mengetahui apakah kuota responden tracer study sudah mencukupi syarat IKU 1:</p>';
            echo '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">';
            echo '<div><label style="font-weight:600;font-size:12px;color:#334155">Total Lulusan (N):</label><br><input type="number" id="at_pop_n" value="500" min="1" style="width:140px;padding:4px 8px"></div>';
            echo '<div><label style="font-weight:600;font-size:12px;color:#334155">Min. Responden Slovin (n_min):</label><br><span id="at_slovin_nmin" style="font-weight:bold;color:#0f172a;font-size:14px">396 Responden</span></div>';
            echo '<div><label style="font-weight:600;font-size:12px;color:#334155">Responden Terkumpul (t):</label><br><span style="font-weight:bold;color:#0284c7;font-size:14px">' . $tot_resp . ' Responden</span></div>';
            echo '<div id="at_slovin_status" style="margin-top:12px;width:100%"><span style="background:#dcfce7;color:#166534;font-weight:700;padding:6px 12px;border-radius:20px;font-size:12px">🟢 KUOTA MEMENUHI SYARAT SLOVIN</span></div>';
            echo '</div></div>';

            // Variabel Helper Pills
            echo '<div style="margin-bottom:14px;background:#f8fafc;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px"><span style="font-size:11px;font-weight:700;color:#475569;margin-right:8px">Bantuan Sisipkan Variabel / Rumus:</span>';
            foreach ($questions as $q_i => $q_obj) {
                $code = $this->question_code($selected_run, $q_obj->id);
                echo '<span class="at-pill" data-insert="' . esc_attr($code) . '">' . esc_html($code) . '</span>';
            }
            echo '<span class="at-pill" data-insert="SUM(P4)" style="background:#e0f2fe;color:#0369a1">+ SUM(P4)</span>';
            echo '<span class="at-pill" data-insert="=SUM(P4) / COUNT() * 100%" style="background:#fef3c7;color:#92400e;font-weight:bold">+ Formula IKU 1</span>';
            echo '<span class="at-pill" data-insert="IF(P2<6, IF(P3>=3000000, 1.0, 0.6), IF(P2<=12, IF(P3>=3000000, 0.8, 0.6), 0.6))" style="background:#e0f2fe;color:#0369a1">+ Rumus Poin B</span>';
            echo '</div>';

            echo '<table class="at-table"><thead><tr><th style="width:25%">Nama Kolom / Seksi</th><th style="width:45%">Formulasi Rumus DSL / Excel</th><th style="width:15%">Tampilkan di Tabel</th><th style="width:10%">Hasil Live</th><th style="width:5%;text-align:right">Aksi</th></tr></thead><tbody id="at_dsl_sec_tbody">';
            
            if (!empty($existing_sections)) {
                foreach ($existing_sections as $s_idx => $sec) {
                    $sec_title = $sec->title ?? 'Perhitungan DSL'; $sec_formula = $sec->formula ?? 'COUNT()';
                    $is_shown = !isset($sec->show_in_table) || !empty($sec->show_in_table);
                    $live_result = $this->evaluate_dsl_formula($sec_formula, $survey_values, $ump_settings['data'] ?? array(), $questions, $selected_run);
                    echo '<tr class="at-dsl-sec-row">';
                    echo '<td><input type="text" name="sections[' . $s_idx . '][title]" value="' . esc_attr($sec_title) . '" required style="width:100%" placeholder="Nama Kolom (misal: Nilai Bobot Poin B)"></td>';
                    echo '<td><input type="text" name="sections[' . $s_idx . '][formula]" class="at-dsl-formula-inp" value="' . esc_attr($sec_formula) . '" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Contoh: SUM(P4) atau IF(P2<6, 1.0, 0.6)"></td>';
                    echo '<td style="text-align:center"><label><input type="checkbox" name="sections[' . $s_idx . '][show_in_table]" value="1" ' . checked($is_shown, true, false) . '> Ya</label></td>';
                    echo '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">' . esc_html($live_result) . '</span></td>';
                    echo '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table></div>';
        }

        echo '<div style="display:flex;justify-content:flex-end;margin-top:20px"><button class="button button-primary button-large">' . ($is_edit ? 'Update Laporan' : 'Simpan Laporan') . '</button></div></form>';

        ?>
        <script>
        (function(){
            var pageUrl = "<?php echo esc_js(admin_url('admin.php?page=' . self::SLUG_REPORT)); ?>";
            var ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
            var nonce = "<?php echo esc_js(wp_create_nonce('at_ext_save_report')); ?>";
            var qChoicesMap = <?php echo json_encode($q_choices_map ?? array()); ?>;
            var currentUmpData = <?php echo json_encode($ump_settings['data'] ?? array()); ?>;
            var totResp = <?php echo intval($tot_resp ?? 0); ?>;
            var activeFormulaInp = null;

            // KALKULATOR SLOVIN HITUNG OTOMATIS
            function calcSlovin(){
                var popEl = document.getElementById("at_pop_n");
                var nminEl = document.getElementById("at_slovin_nmin");
                var statusEl = document.getElementById("at_slovin_status");
                if(!popEl || !nminEl || !statusEl) return;

                var N = parseInt(popEl.value) || 0;
                if(N <= 0){
                    nminEl.innerText = "0 Responden";
                    statusEl.innerHTML = '<span style="background:#f1f5f9;color:#475569;font-weight:700;padding:6px 12px;border-radius:20px;font-size:12px">⚪ Masukkan Total Alumni (N)</span>';
                    return;
                }
                var nMin = Math.ceil(N / (1 + (N * 0.000529)));
                nminEl.innerText = nMin + " Responden";

                if(totResp >= nMin){
                    statusEl.innerHTML = '<span style="background:#dcfce7;color:#166534;font-weight:700;padding:6px 12px;border-radius:20px;font-size:12px">🟢 TERKUMPUL ' + totResp + ' RESPONDEN — MEMENUHI KUOTA MINIMUM SLOVIN (' + nMin + ')</span>';
                } else {
                    var sisa = nMin - totResp;
                    statusEl.innerHTML = '<span style="background:#fee2e2;color:#991b1b;font-weight:700;padding:6px 12px;border-radius:20px;font-size:12px">🔴 MASIH KURANG ' + sisa + ' RESPONDEN UNTUK MEMENUHI KUOTA SLOVIN (' + nMin + ')</span>';
                }
            }

            document.addEventListener("input", function(e){
                if(e.target && e.target.id === "at_pop_n") calcSlovin();
            });
            calcSlovin();

            document.addEventListener("focusin", function(e){
                if(e.target && e.target.classList.contains("at-dsl-formula-inp")) activeFormulaInp = e.target;
            });

            // VALIDASI SINTAKS RUMUS SEBELUM SIMPAN (% DIPERBOLEHKAN)
            var mainForm = document.getElementById("at_main_report_form");
            if(mainForm){
                mainForm.addEventListener("submit", function(e){
                    var rows = document.querySelectorAll("#at_dsl_sec_tbody tr");
                    for(var i=0; i<rows.length; i++){
                        var tInp = rows[i].querySelector('input[name*="[title]"]');
                        var fInp = rows[i].querySelector('.at-dsl-formula-inp');
                        if(fInp){
                            var fVal = fInp.value.trim();
                            var title = tInp ? tInp.value : ("Baris " + (i+1));
                            var openC = (fVal.match(/\(/g) || []).length;
                            var closeC = (fVal.match(/\)/g) || []).length;
                            if(openC !== closeC){
                                alert("⚠️ ALERTA FORMULA DSL SINTAKS SALAH:\n\nPada seksi '" + title + "', jumlah tanda kurung buka '(' dan tutup ')' tidak seimbang!\nRumus: " + fVal);
                                fInp.focus(); e.preventDefault(); return false;
                            }
                            if(/[\+\-\*\/]$/.test(fVal)){
                                alert("⚠️ ALERTA FORMULA DSL SINTAKS SALAH:\n\nPada seksi '" + title + "', rumus tidak boleh diakhiri dengan operator (" + fVal.slice(-1) + ")!\nRumus: " + fVal);
                                fInp.focus(); e.preventDefault(); return false;
                            }
                        }
                    }
                });
            }

            document.addEventListener("change", function(e){
                if(e.target && e.target.id === "at_run_id"){
                    var val = e.target.value; var nameInp = document.querySelector('input[name="name"]');
                    window.location.href = pageUrl + "&action=create_report" + (val ? "&run_id=" + encodeURIComponent(val) : "") + (nameInp && nameInp.value ? "&name=" + encodeURIComponent(nameInp.value) : "");
                }
                if(e.target && e.target.id === "at_ref_qselect"){
                    var qcode = e.target.value; var choices = qChoicesMap[qcode] || []; var tbody = document.getElementById("at_ref_items_tbody");
                    if(!tbody) return; tbody.innerHTML = "";
                    if(qcode === "P2+P3"){
                        var c1 = qChoicesMap["P2"] || ["< 6 Bulan", "< 12 Bulan"];
                        var c2 = qChoicesMap["P3"] || ["> 1,2 UMP", "< 1,2 UMP"];
                        c1.forEach(function(x){
                            c2.forEach(function(y){
                                var comboKey = x + " | " + y;
                                var val = (currentUmpData[comboKey] !== undefined) ? currentUmpData[comboKey] : "";
                                var tr = document.createElement("tr");
                                tr.innerHTML = '<td><strong class="at-ump-key-label" data-key="' + comboKey.replace(/"/g, '&quot;') + '">' + comboKey + '</strong></td><td><input type="text" class="at-ump-inp" data-key="' + comboKey.replace(/"/g, '&quot;') + '" value="' + val + '" style="width:100%" placeholder="Contoh UMP: 1.000.000"></td><td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️ Hapus Baris</button></td>';
                                tbody.appendChild(tr);
                            });
                        });
                        return;
                    }
                    choices.forEach(function(cText){
                        var val = (currentUmpData[cText] !== undefined) ? currentUmpData[cText] : "";
                        var tr = document.createElement("tr");
                        tr.innerHTML = '<td><strong class="at-ump-key-label" data-key="' + cText.replace(/"/g, '&quot;') + '">' + cText + '</strong></td><td><input type="text" class="at-ump-inp" data-key="' + cText.replace(/"/g, '&quot;') + '" value="' + val + '" style="width:100%" placeholder="Contoh UMP: 1.000.000"></td><td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️ Hapus Baris</button></td>';
                        tbody.appendChild(tr);
                    });
                }
            });

            document.addEventListener("click", function(e){
                if(!e.target) return; var t = e.target;
                if(t.id === "at_apply_iku1_template"){
                    var tbody = document.getElementById("at_dsl_sec_tbody"); if(!tbody) return;
                    tbody.innerHTML = "";
                    var tr = document.createElement("tr"); tr.className = "at-dsl-sec-row";
                    tr.innerHTML = '<td><input type="text" name="sections[0][title]" value="Nilai Bobot Point Poin B" required style="width:100%"></td>' +
                                   '<td><input type="text" name="sections[0][formula]" class="at-dsl-formula-inp" value="SUM(P4)" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                   '<td style="text-align:center"><label><input type="checkbox" name="sections[0][show_in_table]" value="1" checked> Ya</label></td>' +
                                   '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Pending</span></td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                    alert("🏆 Kolom Perhitungan Nilai Bobot Poin B (SUM(P4)) Berhasil Ditambahkan ke Tabel!");
                    return;
                }
                if(t.classList.contains("at-pill")){
                    var toInsert = t.getAttribute("data-insert");
                    if(!activeFormulaInp){
                        var inps = document.querySelectorAll(".at-dsl-formula-inp");
                        if(inps.length) activeFormulaInp = inps[inps.length - 1];
                    }
                    if(activeFormulaInp){
                        activeFormulaInp.value = (activeFormulaInp.value ? activeFormulaInp.value + " " : "") + toInsert;
                        activeFormulaInp.focus();
                    }
                    return;
                }
                if(t.classList.contains("at-del-item-btn") || t.classList.contains("at-del-sec-btn")){ var row = t.closest("tr"); if(row) row.remove(); return; }
                if(t.id === "at_add_dsl_sec_btn"){
                    var tbody = document.getElementById("at_dsl_sec_tbody"); if(!tbody) return;
                    var idx = tbody.querySelectorAll("tr").length; var tr = document.createElement("tr"); tr.className = "at-dsl-sec-row";
                    tr.innerHTML = '<td><input type="text" name="sections[' + idx + '][title]" value="Kolom Bobot ' + (idx + 1) + '" required style="width:100%" placeholder="Nama Kolom"></td>' +
                                   '<td><input type="text" name="sections[' + idx + '][formula]" class="at-dsl-formula-inp" value="SUM(P4)" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Contoh: SUM(P4) atau IF(P2<6, 1.0, 0.6)"></td>' +
                                   '<td style="text-align:center"><label><input type="checkbox" name="sections[' + idx + '][show_in_table]" value="1" checked> Ya</label></td>' +
                                   '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Pending</span></td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                    return;
                }
                if(t.id === "at_add_ref_btn"){
                    var title = prompt("Nama tabel acuan baru:"); if(!title || !title.trim()) return;
                    var fd = new FormData(); fd.append("action", "akurasitara_save_ump_table"); fd.append("nonce", nonce); fd.append("ref_column_title", title.trim()); fd.append("ref_question_code", "P4"); fd.append("is_new", "1"); fd.append("ump_data", JSON.stringify({}));
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); }); return;
                }
                if(t.classList.contains("at-switch-btn") || t.classList.contains("at-disable-ref-btn")){
                    var id = t.classList.contains("at-disable-ref-btn") ? "none" : t.getAttribute("data-id");
                    var fd = new FormData(); fd.append("action", "akurasitara_switch_ref_table"); fd.append("nonce", nonce); fd.append("table_id", id);
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); }); return;
                }
                if(t.classList.contains("at-del-ref-btn")){
                    var id = t.getAttribute("data-id"); if(!confirm("Hapus Tabel Acuan ini secara permanen?")) return;
                    var fd = new FormData(); fd.append("action", "akurasitara_delete_ref_table"); fd.append("nonce", nonce); fd.append("table_id", id);
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); }); return;
                }
                if(t.id === "at_save_ref_btn"){
                    var rows = document.querySelectorAll("#at_ref_items_tbody tr"); var data = {};
                    rows.forEach(function(tr){ var kEl = tr.querySelector(".at-ump-key-label"); var iEl = tr.querySelector(".at-ump-inp"); if(kEl && iEl){ var k = kEl.getAttribute("data-key"); var v = iEl.value.trim(); if(k) data[k] = (v === "" || isNaN(v)) ? v : parseFloat(v); } });
                    var fd = new FormData(); fd.append("action", "akurasitara_save_ump_table"); fd.append("nonce", nonce); fd.append("ref_column_title", document.getElementById("at_ref_title").value.trim()); fd.append("ref_question_code", document.getElementById("at_ref_qselect").value); fd.append("ump_data", JSON.stringify(data));
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(r){ return r.json(); }).then(function(res){ alert(res.data.message || "Tabel disimpan."); window.location.reload(); }); return;
                }
            });
        })();
        </script>
        <?php
    }
}

new AkurasiTara_Ext_Reports_DSL();
