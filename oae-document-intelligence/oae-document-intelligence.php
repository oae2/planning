<?php
/**
 * Plugin Name: OAE AI Agent Unified – Document Intelligence
 * Description: Verified PDF ingestion, Thai government-document normalization, legal-aware chunking and Seed Set integration for OAE AI Assistance.
 * Version: 2.3.0
 * Author: Office of Agricultural Economics
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) { exit; }

final class OAEODI {
    public const VERSION = '2.3.0';
    public const CAP = 'manage_options';
    public const CRON_HOOK = 'oaeodi_process_document';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_oaeodi_upload', [__CLASS__, 'handle_upload']);
        add_action('admin_post_oaeodi_seed', [__CLASS__, 'handle_seed']);
        add_action('admin_post_oaeodi_verify', [__CLASS__, 'handle_verify']);
        add_action('admin_post_oaeodi_sync', [__CLASS__, 'handle_sync']);
        add_action('admin_post_oaeodi_reprocess', [__CLASS__, 'handle_reprocess']);
        add_action('admin_post_oaeodi_save_text', [__CLASS__, 'handle_save_text']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_document']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
    }

    public static function activate(): void {
        self::install_schema();
        self::import_seed_set(true);
    }

    private static function t(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'oaeodi_' . $suffix;
    }

    private static function base_table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'oaeaiu_' . $suffix;
    }

    private static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $docs = self::t('docs');
        $pages = self::t('pages');
        $audit = self::t('audit');

        dbDelta("CREATE TABLE {$docs} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            doc_key varchar(120) NOT NULL,
            title varchar(500) NOT NULL,
            source_filename varchar(500) NULL,
            source_path text NULL,
            source_hash char(64) NULL,
            source_kind varchar(40) NOT NULL DEFAULT 'seed',
            doc_type varchar(80) NULL,
            agency varchar(255) NULL,
            document_no varchar(255) NULL,
            document_date varchar(40) NULL,
            effective_date varchar(40) NULL,
            page_count int unsigned NOT NULL DEFAULT 0,
            extraction_method varchar(80) NULL,
            status varchar(40) NOT NULL DEFAULT 'needs_review',
            coverage varchar(40) NOT NULL DEFAULT 'unknown',
            quality_score decimal(5,2) NOT NULL DEFAULT 0,
            quality_flags text NULL,
            raw_text longtext NULL,
            normalized_text longtext NULL,
            metadata_json longtext NULL,
            relationships_json longtext NULL,
            synced_kb_doc_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY doc_key (doc_key),
            KEY status (status),
            KEY synced_kb_doc_id (synced_kb_doc_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$pages} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            doc_id bigint unsigned NOT NULL,
            page_no int unsigned NOT NULL,
            raw_text longtext NULL,
            normalized_text longtext NULL,
            quality_score decimal(5,2) NOT NULL DEFAULT 0,
            quality_flags text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY doc_page (doc_id,page_no),
            KEY doc_id (doc_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            doc_id bigint unsigned NULL,
            user_id bigint unsigned NULL,
            action varchar(80) NOT NULL,
            detail_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY doc_id (doc_id),
            KEY action (action)
        ) {$charset};");

        update_option('oaeodi_schema_version', self::VERSION, false);
    }

    private static function now(): string { return current_time('mysql'); }

    private static function audit(?int $doc_id, string $action, array $detail = []): void {
        global $wpdb;
        $wpdb->insert(self::t('audit'), [
            'doc_id' => $doc_id,
            'user_id' => get_current_user_id() ?: null,
            'action' => $action,
            'detail_json' => wp_json_encode($detail, JSON_UNESCAPED_UNICODE),
            'created_at' => self::now(),
        ], ['%d','%d','%s','%s','%s']);
    }

    private static function require_admin(string $nonce_action): void {
        if (!current_user_can(self::CAP)) { wp_die('Permission denied'); }
        check_admin_referer($nonce_action);
    }

    public static function admin_menu(): void {
        add_menu_page(
            'OAE Document Intelligence',
            'OAE Document Intelligence',
            self::CAP,
            'oae-document-intelligence',
            [__CLASS__, 'render_admin'],
            'dashicons-media-document',
            58
        );
    }

    public static function admin_notices(): void {
        if (!current_user_can(self::CAP)) { return; }
        if (!empty($_GET['oaeodi_msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['oaeodi_msg']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    public static function render_admin(): void {
        if (!current_user_can(self::CAP)) { return; }
        self::install_schema();
        global $wpdb;
        $doc_id = isset($_GET['doc_id']) ? absint($_GET['doc_id']) : 0;
        if ($doc_id) { self::render_detail($doc_id); return; }

        $docs = $wpdb->get_results('SELECT * FROM ' . self::t('docs') . ' ORDER BY id DESC');
        $counts = $wpdb->get_results('SELECT status,COUNT(*) c FROM ' . self::t('docs') . ' GROUP BY status', OBJECT_K);
        $base_ready = self::base_ready();
        $env = self::environment_status();
        ?>
        <div class="wrap">
            <h1>OAE Document Intelligence <small>v<?php echo esc_html(self::VERSION); ?></small></h1>
            <p><strong>Pipeline:</strong> PDF → classify → native text/OCR → Thai normalization → metadata/relationship parsing → human verification → legal-aware chunks → OAE AI KB.</p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0">
                <?php foreach (['verified','needs_review','processing','error'] as $s): ?>
                    <div class="card" style="min-width:150px"><strong><?php echo esc_html($s); ?></strong><br><span style="font-size:26px"><?php echo isset($counts[$s]) ? intval($counts[$s]->c) : 0; ?></span></div>
                <?php endforeach; ?>
                <div class="card" style="min-width:210px"><strong>OAE AI KB</strong><br><?php echo $base_ready ? '✅ detected' : '⚠️ base KB tables not detected'; ?></div>
            </div>

            <div class="card" style="max-width:1200px">
                <h2>เพิ่ม PDF</h2>
                <p>รองรับหลายไฟล์พร้อมกัน ไฟล์ใหม่ทุกฉบับจะอยู่สถานะ <code>needs_review</code> จนกด Verify เพื่อป้องกัน OCR ผิดไหลเข้า Production KB.</p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="oaeodi_upload">
                    <?php wp_nonce_field('oaeodi_upload'); ?>
                    <input type="file" name="pdfs[]" accept="application/pdf,.pdf" multiple required>
                    <?php submit_button('Upload & Process', 'primary', 'submit', false); ?>
                </form>
                <hr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="oaeodi_seed">
                    <?php wp_nonce_field('oaeodi_seed'); ?>
                    <?php submit_button('Import / Refresh Seed Set 10 เอกสาร', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div class="card" style="max-width:1200px">
                <h2>Environment diagnostics</h2>
                <table class="widefat striped"><tbody>
                <?php foreach ($env as $k=>$v): ?><tr><th style="width:250px"><?php echo esc_html($k); ?></th><td><?php echo esc_html($v); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>

            <h2>Documents</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>เอกสาร</th><th>ประเภท</th><th>สถานะ</th><th>Coverage</th><th>Quality</th><th>Extraction</th><th>KB</th><th></th></tr></thead>
                <tbody>
                <?php if (!$docs): ?><tr><td colspan="9">ยังไม่มีเอกสาร</td></tr><?php endif; ?>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td><?php echo intval($d->id); ?></td>
                        <td><strong><?php echo esc_html($d->title); ?></strong><br><small><?php echo esc_html($d->source_filename ?: $d->doc_key); ?></small></td>
                        <td><?php echo esc_html($d->doc_type); ?></td>
                        <td><?php echo esc_html($d->status); ?></td>
                        <td><?php echo esc_html($d->coverage); ?></td>
                        <td><?php echo esc_html(number_format((float)$d->quality_score,1)); ?></td>
                        <td><?php echo esc_html($d->extraction_method); ?></td>
                        <td><?php echo $d->synced_kb_doc_id ? 'KB#' . intval($d->synced_kb_doc_id) : '—'; ?></td>
                        <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=oae-document-intelligence&doc_id=' . intval($d->id))); ?>">ตรวจสอบ</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_detail(int $doc_id): void {
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('docs') . ' WHERE id=%d', $doc_id));
        if (!$d) { echo '<div class="wrap"><h1>Document not found</h1></div>'; return; }
        $meta = json_decode((string)$d->metadata_json, true) ?: [];
        $rels = json_decode((string)$d->relationships_json, true) ?: [];
        ?>
        <div class="wrap">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=oae-document-intelligence')); ?>">← กลับ</a></p>
            <h1><?php echo esc_html($d->title); ?></h1>
            <p><strong>Status:</strong> <?php echo esc_html($d->status); ?> | <strong>Quality:</strong> <?php echo esc_html($d->quality_score); ?> | <strong>Coverage:</strong> <?php echo esc_html($d->coverage); ?> | <strong>Method:</strong> <?php echo esc_html($d->extraction_method); ?></p>
            <p><strong>Flags:</strong> <code><?php echo esc_html($d->quality_flags); ?></code></p>
            <p><strong>Metadata:</strong> <code><?php echo esc_html(wp_json_encode($meta, JSON_UNESCAPED_UNICODE)); ?></code></p>
            <p><strong>Relationships:</strong> <code><?php echo esc_html(wp_json_encode($rels, JSON_UNESCAPED_UNICODE)); ?></code></p>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:15px 0">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="oaeodi_reprocess"><input type="hidden" name="doc_id" value="<?php echo intval($d->id); ?>"><?php wp_nonce_field('oaeodi_reprocess_' . $d->id); ?><?php submit_button('Re-extract / OCR', 'secondary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="oaeodi_verify"><input type="hidden" name="doc_id" value="<?php echo intval($d->id); ?>"><?php wp_nonce_field('oaeodi_verify_' . $d->id); ?><?php submit_button('Verify & Publish to KB', 'primary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="oaeodi_sync"><input type="hidden" name="doc_id" value="<?php echo intval($d->id); ?>"><?php wp_nonce_field('oaeodi_sync_' . $d->id); ?><?php submit_button('Sync KB Now', 'secondary', 'submit', false); ?></form>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="oaeodi_save_text"><input type="hidden" name="doc_id" value="<?php echo intval($d->id); ?>"><?php wp_nonce_field('oaeodi_save_text_' . $d->id); ?>
                <table class="form-table"><tr><th>Title</th><td><input class="large-text" name="title" value="<?php echo esc_attr($d->title); ?>"></td></tr></table>
                <h2>Normalized / verified text</h2>
                <textarea name="normalized_text" style="width:100%;min-height:520px;font-family:monospace;white-space:pre-wrap"><?php echo esc_textarea($d->normalized_text); ?></textarea>
                <?php submit_button('Save reviewed text'); ?>
            </form>

            <?php if ($d->source_path && file_exists($d->source_path)): ?>
                <h2>Original file</h2><p>Stored on server: <code><?php echo esc_html($d->source_path); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_seed(): void {
        self::require_admin('oaeodi_seed');
        self::install_schema();
        $result = self::import_seed_set(true);
        self::redirect('Seed Set: imported ' . intval($result['imported']) . ', synced ' . intval($result['synced']));
    }

    public static function handle_upload(): void {
        self::require_admin('oaeodi_upload');
        self::install_schema();
        if (empty($_FILES['pdfs']['name']) || !is_array($_FILES['pdfs']['name'])) { self::redirect('No PDF selected'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $count = count($_FILES['pdfs']['name']);
        $ok = 0;
        for ($i=0; $i<$count; $i++) {
            if ((int)$_FILES['pdfs']['error'][$i] !== UPLOAD_ERR_OK) { continue; }
            $file = [
                'name' => sanitize_file_name(wp_unslash($_FILES['pdfs']['name'][$i])),
                'type' => $_FILES['pdfs']['type'][$i],
                'tmp_name' => $_FILES['pdfs']['tmp_name'][$i],
                'error' => $_FILES['pdfs']['error'][$i],
                'size' => $_FILES['pdfs']['size'][$i],
            ];
            if ($file['size'] > 50 * 1024 * 1024) { continue; }
            $head = @file_get_contents($file['tmp_name'], false, null, 0, 5);
            if ($head !== '%PDF-') { continue; }
            $moved = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>['pdf'=>'application/pdf']]);
            if (!empty($moved['error']) || empty($moved['file'])) { continue; }
            $doc_id = self::register_uploaded_pdf($moved['file'], basename($moved['file']));
            if ($doc_id) {
                self::process_document($doc_id);
                $ok++;
            }
        }
        self::redirect('Uploaded/processed ' . $ok . ' PDF(s)');
    }

    private static function register_uploaded_pdf(string $path, string $filename): int {
        global $wpdb;
        $hash = is_readable($path) ? hash_file('sha256', $path) : '';
        $seed_key = self::seed_key_for_filename($filename);
        $key = $seed_key ?: ('pdf-' . substr($hash ?: hash('sha256', $filename . microtime(true)), 0, 24));
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('docs') . ' WHERE doc_key=%s', $key));
        $data = [
            'title' => preg_replace('/\.pdf$/iu', '', $filename),
            'source_filename' => $filename,
            'source_path' => $path,
            'source_hash' => $hash,
            'source_kind' => 'pdf',
            'status' => 'processing',
            'coverage' => 'full_source_attached',
            'updated_at' => self::now(),
        ];
        if ($existing) {
            $wpdb->update(self::t('docs'), $data, ['id'=>(int)$existing->id]);
            self::audit((int)$existing->id, 'attach_pdf', ['filename'=>$filename,'hash'=>$hash]);
            return (int)$existing->id;
        }
        $data['doc_key'] = $key;
        $data['created_at'] = self::now();
        $wpdb->insert(self::t('docs'), $data);
        $id = (int)$wpdb->insert_id;
        self::audit($id, 'upload_pdf', ['filename'=>$filename,'hash'=>$hash]);
        return $id;
    }

    public static function handle_reprocess(): void {
        $id = absint($_POST['doc_id'] ?? 0);
        self::require_admin('oaeodi_reprocess_' . $id);
        self::process_document($id);
        self::redirect('Re-extraction finished', $id);
    }

    public static function handle_save_text(): void {
        $id = absint($_POST['doc_id'] ?? 0);
        self::require_admin('oaeodi_save_text_' . $id);
        global $wpdb;
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $text = self::normalize_text((string)wp_unslash($_POST['normalized_text'] ?? ''));
        $q = self::quality($text, 0);
        $wpdb->update(self::t('docs'), [
            'title'=>$title,
            'normalized_text'=>$text,
            'quality_score'=>$q['score'],
            'quality_flags'=>implode(',', $q['flags']),
            'status'=>'needs_review',
            'updated_at'=>self::now(),
        ], ['id'=>$id]);
        self::write_pages($id, $text);
        self::audit($id, 'save_reviewed_text', ['chars'=>mb_strlen($text)]);
        self::redirect('Saved; verification is required before KB publish', $id);
    }

    public static function handle_verify(): void {
        $id = absint($_POST['doc_id'] ?? 0);
        self::require_admin('oaeodi_verify_' . $id);
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('docs') . ' WHERE id=%d', $id));
        if (!$d || mb_strlen(trim((string)$d->normalized_text)) < 30) { self::redirect('Cannot verify: no usable text', $id); }
        $wpdb->update(self::t('docs'), ['status'=>'verified','updated_at'=>self::now()], ['id'=>$id]);
        self::audit($id, 'verify', ['quality'=>(float)$d->quality_score]);
        $kb = self::sync_to_base_kb($id);
        self::redirect($kb ? 'Verified and synced to OAE AI KB' : 'Verified; base OAE AI KB not detected yet', $id);
    }

    public static function handle_sync(): void {
        $id = absint($_POST['doc_id'] ?? 0);
        self::require_admin('oaeodi_sync_' . $id);
        $ok = self::sync_to_base_kb($id);
        self::redirect($ok ? 'Synced to OAE AI KB' : 'Not synced: document must be verified and base KB must exist', $id);
    }

    private static function redirect(string $msg, int $doc_id = 0): void {
        $url = admin_url('admin.php?page=oae-document-intelligence');
        if ($doc_id) { $url = add_query_arg('doc_id', $doc_id, $url); }
        wp_safe_redirect(add_query_arg('oaeodi_msg', rawurlencode($msg), $url)); exit;
    }

    public static function process_document(int $doc_id): void {
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('docs') . ' WHERE id=%d', $doc_id));
        if (!$d || !$d->source_path || !is_readable($d->source_path)) { return; }
        $wpdb->update(self::t('docs'), ['status'=>'processing','updated_at'=>self::now()], ['id'=>$doc_id]);
        $path = (string)$d->source_path;
        $page_count = self::pdf_page_count($path);
        $native = self::native_pdf_text($path);
        $method = 'native_text';
        $raw = $native;
        if (!self::text_is_usable($native, $page_count)) {
            $ocr = self::local_ocr_pdf($path, min(max($page_count,1),60));
            if (mb_strlen(trim($ocr)) > mb_strlen(trim($native))) { $raw = $ocr; $method = 'local_ocr'; }
            else { $method = 'native_low_quality'; }
        }
        $normalized = self::normalize_text($raw);
        $q = self::quality($normalized, $page_count);
        $meta = self::extract_metadata($normalized, (string)$d->title);
        $rels = self::extract_relationships($normalized);
        $flags = $q['flags'];
        if ($method === 'local_ocr') { $flags[] = 'ocr_requires_human_verification'; }
        if (mb_strlen(trim($normalized)) < 200) { $flags[] = 'extraction_incomplete'; }
        $wpdb->update(self::t('docs'), [
            'title'=>$meta['title'] ?: $d->title,
            'doc_type'=>$meta['doc_type'],
            'agency'=>$meta['agency'],
            'document_no'=>$meta['document_no'],
            'document_date'=>$meta['document_date'],
            'page_count'=>$page_count,
            'extraction_method'=>$method,
            'status'=>'needs_review',
            'quality_score'=>$q['score'],
            'quality_flags'=>implode(',', array_values(array_unique($flags))),
            'raw_text'=>$raw,
            'normalized_text'=>$normalized,
            'metadata_json'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'relationships_json'=>wp_json_encode($rels, JSON_UNESCAPED_UNICODE),
            'updated_at'=>self::now(),
        ], ['id'=>$doc_id]);
        self::write_pages($doc_id, $normalized);
        self::audit($doc_id, 'process_pdf', ['method'=>$method,'pages'=>$page_count,'quality'=>$q['score'],'flags'=>$flags]);
    }

    private static function command_available(string $cmd): bool {
        if (!function_exists('exec')) { return false; }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) { return false; }
        $out=[]; $code=1;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    private static function pdf_page_count(string $path): int {
        if (self::command_available('pdfinfo')) {
            $out=[]; $code=1; @exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && preg_match('/^Pages:\s+(\d+)/mi', implode("\n",$out), $m)) { return (int)$m[1]; }
        }
        return 0;
    }

    private static function native_pdf_text(string $path): string {
        if (!self::command_available('pdftotext')) { return ''; }
        $tmp = wp_tempnam('oaeodi-native.txt');
        if (!$tmp) { return ''; }
        $cmd = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
        $out=[]; $code=1; @exec($cmd, $out, $code);
        $text = ($code===0 && is_readable($tmp)) ? (string)file_get_contents($tmp) : '';
        @unlink($tmp);
        return $text;
    }

    private static function local_ocr_pdf(string $path, int $max_pages): string {
        if (!self::command_available('pdftoppm') || !self::command_available('tesseract')) { return ''; }
        $dir = trailingslashit(get_temp_dir()) . 'oaeodi_' . wp_generate_password(10,false,false);
        if (!wp_mkdir_p($dir)) { return ''; }
        $prefix = $dir . '/page';
        $cmd = 'pdftoppm -f 1 -l ' . intval($max_pages) . ' -r 190 -png -singlefile=false ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' >/dev/null 2>&1';
        $x=[]; $code=1; @exec($cmd,$x,$code);
        $files = glob($dir . '/page-*.png') ?: [];
        natsort($files);
        $text=''; $p=0;
        foreach ($files as $img) {
            $p++;
            $base = $dir . '/ocr-' . $p;
            $c = 'tesseract ' . escapeshellarg($img) . ' ' . escapeshellarg($base) . ' -l tha+eng --psm 6 >/dev/null 2>&1';
            $y=[]; $rc=1; @exec($c,$y,$rc);
            $txt = $base . '.txt';
            if ($rc===0 && is_readable($txt)) { $text .= "\n[[PAGE:".$p."]]\n" . file_get_contents($txt) . "\n"; }
        }
        foreach (glob($dir.'/*') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
        return $text;
    }

    private static function text_is_usable(string $text, int $pages): bool {
        $n = mb_strlen(trim($text));
        $floor = max(220, $pages > 0 ? $pages * 120 : 220);
        if ($n < $floor) { return false; }
        $bad = substr_count($text, "�");
        return $bad < max(5, (int)($n * 0.003));
    }

    private static function normalize_text(string $text): string {
        $text = wp_check_invalid_utf8($text, true);
        $text = str_replace(["\xC2\xA0", "\u{200B}", "\u{FEFF}"], [' ', '', ''], $text);
        if (class_exists('Normalizer')) { $n = Normalizer::normalize($text, Normalizer::FORM_C); if ($n !== false) { $text = $n; } }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\r\n?|\x{2028}|\x{2029}/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{4,}/u', "\n\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function quality(string $text, int $pages): array {
        $len = max(1, mb_strlen($text));
        $score = 100.0;
        $flags=[];
        $replacement = substr_count($text, '�');
        if ($replacement) { $score -= min(35, ($replacement/$len)*1000); $flags[]='replacement_chars'; }
        $lines = preg_split('/\R/u', $text) ?: [];
        $tiny=0; foreach ($lines as $l) { $t=trim($l); if ($t!=='' && mb_strlen($t)<=2) { $tiny++; } }
        if (count($lines)>20 && $tiny/count($lines)>0.18) { $score-=18; $flags[]='fragmented_glyphs'; }
        if ($pages>0 && $len/$pages<180) { $score-=25; $flags[]='low_text_per_page'; }
        if ($len<300) { $score-=30; $flags[]='low_text_volume'; }
        if (preg_match_all('/[A-Za-z0-9]{30,}/u',$text,$m)>3) { $score-=8; $flags[]='possible_binary_noise'; }
        return ['score'=>max(0,min(100,round($score,2))),'flags'=>array_values(array_unique($flags))];
    }

    private static function extract_metadata(string $text, string $fallback_title): array {
        $title = $fallback_title; $docno=''; $date=''; $agency=''; $type='general';
        if (preg_match('/(?:เรื่อง|เรือง)\s*[:\-]?\s*([^\n]{8,220})/u',$text,$m)) { $title=trim($m[1]); }
        if (preg_match('/(?:ที่|ที)\s*([ก-๙A-Za-z0-9\.\-\/ ]{3,80})/u',$text,$m)) { $docno=trim($m[1]); }
        if (preg_match('/(\d{1,2}|[๐-๙]{1,2})\s+(มกราคม|กุมภาพันธ์|มีนาคม|เมษายน|พฤษภาคม|มิถุนายน|กรกฎาคม|สิงหาคม|กันยายน|ตุลาคม|พฤศจิกายน|ธันวาคม)\s+(\d{4}|[๐-๙]{4})/u',$text,$m)) { $date=trim($m[0]); }
        foreach (['กระทรวงการคลัง','กรมบัญชีกลาง','สำนักงาน ก.พ.','สำนักงานเศรษฐกิจการเกษตร','กระทรวงเกษตรและสหกรณ์','สำนักนายกรัฐมนตรี'] as $a) { if (mb_stripos($text,$a)!==false) { $agency=$a; break; } }
        if (mb_stripos($title,'ระเบียบ')!==false) $type='regulation';
        elseif (mb_stripos($title,'คำสั่ง')!==false) $type='order';
        elseif (mb_stripos($title,'แบบฟอร์ม')!==false) $type='form_circular';
        elseif (mb_stripos($title,'หลักเกณฑ์')!==false || mb_stripos($title,'แนวทาง')!==false) $type='guideline';
        elseif ($docno!=='') $type='circular';
        return ['title'=>$title,'document_no'=>$docno,'document_date'=>$date,'agency'=>$agency,'doc_type'=>$type];
    }

    private static function extract_relationships(string $text): array {
        $rels=[];
        $patterns = [
            'amends'=>'แก้ไขเพิ่มเติม',
            'replaces'=>'ให้ใช้ความต่อไปนี้แทน',
            'repeals'=>'ให้ยกเลิก',
            'references'=>'อ้างถึง',
        ];
        foreach ($patterns as $k=>$needle) {
            if (mb_stripos($text,$needle)!==false) { $rels[]=['type'=>$k,'evidence'=>$needle]; }
        }
        return $rels;
    }

    private static function write_pages(int $doc_id, string $text): void {
        global $wpdb;
        $wpdb->delete(self::t('pages'), ['doc_id'=>$doc_id], ['%d']);
        $parts = preg_split('/\[\[PAGE:(\d+)\]\]/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pages=[];
        if ($parts && count($parts)>2) {
            for ($i=1; $i<count($parts)-1; $i+=2) { $pages[(int)$parts[$i]] = trim($parts[$i+1]); }
        } else { $pages[1]=$text; }
        foreach ($pages as $no=>$content) {
            $q=self::quality($content,1);
            $wpdb->insert(self::t('pages'), ['doc_id'=>$doc_id,'page_no'=>$no,'raw_text'=>$content,'normalized_text'=>$content,'quality_score'=>$q['score'],'quality_flags'=>implode(',',$q['flags']),'created_at'=>self::now(),'updated_at'=>self::now()]);
        }
    }

    private static function seed_manifest(): array {
        $file = plugin_dir_path(__FILE__) . 'data/seed-manifest.json';
        if (!is_readable($file)) { return []; }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private static function seed_key_for_filename(string $filename): string {
        $n = self::canonical_filename($filename);
        foreach (self::seed_manifest() as $s) {
            if ($n === self::canonical_filename((string)($s['source_filename'] ?? ''))) { return (string)$s['doc_key']; }
        }
        return '';
    }

    private static function canonical_filename(string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/u','',$s) ?? $s;
        $s = preg_replace('/[^ก-๙a-z0-9\.]+/u','',$s) ?? $s;
        return $s;
    }

    private static function import_seed_set(bool $auto_sync): array {
        self::install_schema();
        global $wpdb;
        $imported=0; $synced=0;
        foreach (self::seed_manifest() as $seed) {
            $key = sanitize_key((string)($seed['doc_key'] ?? ''));
            $content_file = plugin_dir_path(__FILE__) . 'data/' . basename((string)($seed['content_file'] ?? ''));
            if ($key==='' || !is_readable($content_file)) { continue; }
            $text = self::normalize_text((string)file_get_contents($content_file));
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('docs') . ' WHERE doc_key=%s', $key));
            $meta = $seed;
            unset($meta['content_file']);
            $data = [
                'title'=>(string)$seed['title'],
                'source_filename'=>(string)$seed['source_filename'],
                'source_kind'=>'seed',
                'doc_type'=>(string)($seed['doc_type'] ?? 'general'),
                'agency'=>(string)($seed['agency'] ?? ''),
                'document_no'=>(string)($seed['document_no'] ?? ''),
                'document_date'=>(string)($seed['document_date'] ?? ''),
                'effective_date'=>(string)($seed['effective_date'] ?? ''),
                'page_count'=>(int)($seed['page_count'] ?? 0),
                'extraction_method'=>(string)($seed['extraction_method'] ?? 'verified_seed_extract'),
                'status'=>(string)($seed['status'] ?? 'needs_review'),
                'coverage'=>(string)($seed['coverage'] ?? 'verified_extract'),
                'quality_score'=>(float)($seed['quality_score'] ?? 0),
                'quality_flags'=>implode(',', (array)($seed['quality_flags'] ?? [])),
                'raw_text'=>$text,
                'normalized_text'=>$text,
                'metadata_json'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
                'relationships_json'=>wp_json_encode((array)($seed['relationships'] ?? []), JSON_UNESCAPED_UNICODE),
                'updated_at'=>self::now(),
            ];
            if ($existing) {
                // Preserve a full PDF attachment and human-reviewed text when already present.
                if ($existing->source_path || $existing->source_kind === 'pdf') {
                    $data = ['metadata_json'=>$data['metadata_json'],'relationships_json'=>$data['relationships_json'],'updated_at'=>self::now()];
                }
                $wpdb->update(self::t('docs'), $data, ['id'=>(int)$existing->id]);
                $id=(int)$existing->id;
            } else {
                $data['doc_key']=$key; $data['created_at']=self::now();
                $wpdb->insert(self::t('docs'), $data); $id=(int)$wpdb->insert_id;
            }
            if (!$existing || (!$existing->source_path && $existing->source_kind !== 'pdf')) { self::write_pages($id, $text); }
            self::audit($id, 'seed_import', ['version'=>self::VERSION,'coverage'=>$seed['coverage'] ?? '']);
            $imported++;
            if ($auto_sync && (($seed['status'] ?? '') === 'verified') && self::sync_to_base_kb($id)) { $synced++; }
        }
        update_option('oaeodi_seed_version', self::VERSION, false);
        return ['imported'=>$imported,'synced'=>$synced];
    }

    private static function base_ready(): bool {
        global $wpdb;
        $docs = self::base_table('kb_docs');
        $chunks = self::base_table('kb_chunks');
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$docs)) === $docs && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$chunks)) === $chunks;
    }

    private static function table_columns(string $table): array {
        global $wpdb; $out=[];
        foreach ($wpdb->get_results('SHOW COLUMNS FROM `' . esc_sql($table) . '`') ?: [] as $r) { $out[(string)$r->Field]=true; }
        return $out;
    }

    private static function select_supported(array $values, array $columns): array {
        $out=[]; foreach ($values as $k=>$v) { if (isset($columns[$k])) $out[$k]=$v; } return $out;
    }

    private static function sync_to_base_kb(int $doc_id): bool {
        if (!self::base_ready()) { return false; }
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('docs') . ' WHERE id=%d',$doc_id));
        if (!$d || $d->status !== 'verified' || mb_strlen(trim((string)$d->normalized_text))<30) { return false; }
        $docs=self::base_table('kb_docs'); $chunks=self::base_table('kb_chunks');
        $dc=self::table_columns($docs); $cc=self::table_columns($chunks);
        $source_ref='oaeodi://' . $d->doc_key;
        $existing = isset($dc['source_ref']) ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$docs} WHERE source_ref=%s ORDER BY id ASC LIMIT 1",$source_ref)) : null;
        $hash=hash('sha256',(string)$d->normalized_text);
        $flags=trim((string)$d->quality_flags,',');
        $safe_update = self::select_supported([
            'title'=>$d->title,
            'source_type'=>'oaeodi',
            'source_ref'=>$source_ref,
            'content'=>$d->normalized_text,
            'full_content'=>$d->normalized_text,
            'normalized_content'=>$d->normalized_text,
            'content_hash'=>$hash,
            'quality_score'=>$d->quality_score,
            'quality_flags'=>$flags,
            'updated_at'=>self::now(),
        ],$dc);
        if ($existing) {
            $wpdb->update($docs,$safe_update,['id'=>(int)$existing]);
            $kb_id=(int)$existing;
        } else {
            $insert = array_merge($safe_update, self::select_supported([
                'document_type'=>$d->doc_type,
                'doc_type'=>$d->doc_type,
                'version_label'=>'OAEODI Seed/Verified ' . self::VERSION,
                'authority_score'=>self::authority_score((string)$d->doc_type),
                'lifecycle'=>'current',
                'visibility'=>'internal',
                'data_classification'=>'internal',
                'active'=>1,
                'created_at'=>self::now(),
            ],$dc));
            if (!isset($insert['created_at']) && isset($dc['created_at'])) $insert['created_at']=self::now();
            $wpdb->insert($docs,$insert); $kb_id=(int)$wpdb->insert_id;
        }
        if (!$kb_id) { return false; }
        $wpdb->delete($chunks,['doc_id'=>$kb_id],['%d']);
        $parts=self::legal_chunks((string)$d->normalized_text);
        $idx=0;
        foreach ($parts as $part) {
            $row=self::select_supported([
                'doc_id'=>$kb_id,
                'chunk_index'=>$idx++,
                'content'=>$part,
                'keywords'=>self::keywords($part),
                'content_hash'=>hash('sha256',$part),
                'char_count'=>mb_strlen($part),
                'token_estimate'=>(int)ceil(mb_strlen($part)/3.2),
                'quality_score'=>$d->quality_score,
                'created_at'=>self::now(),
            ],$cc);
            $wpdb->insert($chunks,$row);
        }
        $wpdb->update(self::t('docs'),['synced_kb_doc_id'=>$kb_id,'updated_at'=>self::now()],['id'=>$doc_id]);
        self::audit($doc_id,'sync_base_kb',['kb_doc_id'=>$kb_id,'chunks'=>count($parts)]);
        do_action('oaeodi_synced_to_kb',$kb_id,$doc_id);
        do_action('oaeaiu_document_reindexed',$kb_id);
        return true;
    }

    private static function authority_score(string $type): int {
        return match($type) {
            'regulation'=>95,
            'order'=>92,
            'circular'=>88,
            'guideline'=>86,
            'form_circular'=>82,
            default=>70,
        };
    }

    private static function legal_chunks(string $text): array {
        $text=self::normalize_text($text);
        $lines=preg_split('/\R/u',$text) ?: [];
        $chunks=[]; $buf='';
        $boundary='/^(?:\[\[PAGE:\d+\]\]|หมวด\s*\d+|ส่วนที่\s*\d+|ข้อ\s*[๐-๙0-9]+|บัญชี(?:หมายเลข)?\s*[๐-๙0-9]+|เรื่อง\s+)/u';
        foreach ($lines as $line) {
            $line=trim($line); if ($line==='') continue;
            $isBoundary=(bool)preg_match($boundary,$line);
            if ($isBoundary && mb_strlen($buf)>=700) { $chunks[]=trim($buf); $buf=''; }
            if ($buf!=='' && mb_strlen($buf)+mb_strlen($line)>3200) { $chunks[]=trim($buf); $buf=''; }
            $buf .= ($buf===''?'':"\n") . $line;
        }
        if (trim($buf)!=='') $chunks[]=trim($buf);
        if (!$chunks) $chunks=[$text];
        return array_values(array_filter($chunks,fn($x)=>mb_strlen(trim($x))>20));
    }

    private static function keywords(string $text): string {
        $terms=[];
        foreach (['ฝึกอบรม','สัมมนา','ศึกษา','ลา','สัญญา','ชดใช้เงิน','ค้ำประกัน','เทียบตำแหน่ง','อนุมัติ','ค่าใช้จ่าย','วิทยากร','ดูงาน','ปฏิบัติการวิจัย','ต่างประเทศ','ในประเทศ','ข้าราชการ'] as $t) {
            if (mb_stripos($text,$t)!==false) $terms[]=$t;
        }
        return implode(' ',array_unique($terms));
    }

    private static function environment_status(): array {
        return [
            'PHP'=>PHP_VERSION,
            'WordPress'=>get_bloginfo('version'),
            'pdftotext'=>self::command_available('pdftotext')?'ready':'not found',
            'pdfinfo'=>self::command_available('pdfinfo')?'ready':'not found',
            'pdftoppm'=>self::command_available('pdftoppm')?'ready':'not found',
            'tesseract'=>self::command_available('tesseract')?'ready':'not found',
            'Thai Normalizer'=>class_exists('Normalizer')?'ready':'not available',
            'Base OAE AI KB'=>self::base_ready()?'detected':'not detected',
            'Base scanned-PDF class'=>class_exists('OAEAIU_Document_OCR')?'detected':'not detected / not loaded',
            'External OCR'=>'OFF by design; local-first + human verification',
        ];
    }
}

register_activation_hook(__FILE__, ['OAEODI','activate']);
OAEODI::boot();
