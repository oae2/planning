<?php
/**
 * Plugin Name: OAE AI Agent Unified – Document Intelligence
 * Description: Verified PDF ingestion, Thai normalization, legal-aware chunking and Seed Set integration for OAE AI Assistance.
 * Version: 2.3.0
 * Author: Office of Agricultural Economics
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) { exit; }

final class OAEODI {
    public const VERSION='2.3.0';
    private const CAP='manage_options';

    public static function boot(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        foreach (['upload','seed','verify','sync','save','reprocess'] as $a) {
            add_action('admin_post_oaeodi_'.$a,[__CLASS__,'handle_'.$a]);
        }
    }
    public static function activate(): void { self::schema(); self::seed(true); }
    private static function table(string $n): string { global $wpdb; return $wpdb->prefix.'oaeodi_'.$n; }
    private static function base(string $n): string { global $wpdb; return $wpdb->prefix.'oaeaiu_'.$n; }
    private static function now(): string { return current_time('mysql'); }

    private static function schema(): void {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
        $d=self::table('docs'); $p=self::table('pages'); $a=self::table('audit');
        dbDelta("CREATE TABLE $d (
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
          document_date varchar(80) NULL,
          effective_date varchar(120) NULL,
          page_count int unsigned NOT NULL DEFAULT 0,
          extraction_method varchar(80) NULL,
          status varchar(40) NOT NULL DEFAULT 'needs_review',
          coverage varchar(60) NOT NULL DEFAULT 'unknown',
          quality_score decimal(5,2) NOT NULL DEFAULT 0,
          quality_flags text NULL,
          raw_text longtext NULL,
          normalized_text longtext NULL,
          metadata_json longtext NULL,
          relationships_json longtext NULL,
          synced_kb_doc_id bigint unsigned NULL,
          created_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY(id), UNIQUE KEY doc_key(doc_key), KEY status(status), KEY synced_kb_doc_id(synced_kb_doc_id)
        ) $c;");
        dbDelta("CREATE TABLE $p (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          doc_id bigint unsigned NOT NULL,
          page_no int unsigned NOT NULL,
          normalized_text longtext NULL,
          quality_score decimal(5,2) NOT NULL DEFAULT 0,
          quality_flags text NULL,
          created_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY(id), UNIQUE KEY doc_page(doc_id,page_no), KEY doc_id(doc_id)
        ) $c;");
        dbDelta("CREATE TABLE $a (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          doc_id bigint unsigned NULL,
          user_id bigint unsigned NULL,
          action varchar(80) NOT NULL,
          detail_json longtext NULL,
          created_at datetime NOT NULL,
          PRIMARY KEY(id), KEY doc_id(doc_id), KEY action(action)
        ) $c;");
        update_option('oaeodi_schema_version',self::VERSION,false);
    }

    private static function audit(?int $id,string $action,array $detail=[]): void {
        global $wpdb; $wpdb->insert(self::table('audit'),[
            'doc_id'=>$id,'user_id'=>get_current_user_id()?:null,'action'=>$action,
            'detail_json'=>wp_json_encode($detail,JSON_UNESCAPED_UNICODE),'created_at'=>self::now()
        ]);
    }
    private static function guard(string $nonce): void {
        if (!current_user_can(self::CAP)) wp_die('Permission denied');
        check_admin_referer($nonce);
    }
    private static function redirect(string $msg,int $id=0): void {
        $u=admin_url('admin.php?page=oae-document-intelligence'); if($id)$u=add_query_arg('doc_id',$id,$u);
        wp_safe_redirect(add_query_arg('oaeodi_msg',$msg,$u)); exit;
    }

    public static function menu(): void {
        add_menu_page('OAE Document Intelligence','OAE Document Intelligence',self::CAP,'oae-document-intelligence',[__CLASS__,'screen'],'dashicons-media-document',58);
    }
    public static function screen(): void {
        if(!current_user_can(self::CAP))return; self::schema(); global $wpdb;
        if(!empty($_GET['oaeodi_msg'])) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['oaeodi_msg']))).'</p></div>';
        $id=absint($_GET['doc_id']??0); if($id){self::detail($id);return;}
        $docs=$wpdb->get_results('SELECT * FROM '.self::table('docs').' ORDER BY id DESC');
        $env=self::env();
        echo '<div class="wrap"><h1>OAE Document Intelligence v'.esc_html(self::VERSION).'</h1>';
        echo '<p><strong>PDF → extract/OCR → Thai normalize → metadata/quality → review → verify → legal-aware chunks → OAE AI KB</strong></p>';
        echo '<div class="card" style="max-width:1200px"><h2>เพิ่ม PDF</h2><p>PDF ใหม่จะไม่เข้า Production KB จนกว่าเจ้าหน้าที่กด Verify & Publish.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="oaeodi_upload">'; wp_nonce_field('oaeodi_upload');
        echo '<input type="file" name="pdfs[]" accept="application/pdf,.pdf" multiple required> '; submit_button('Upload & Process','primary','submit',false); echo '</form><hr>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="oaeodi_seed">'; wp_nonce_field('oaeodi_seed'); submit_button('Import / Refresh Seed Set 10 เอกสาร','secondary','submit',false); echo '</form></div>';
        echo '<div class="card" style="max-width:1200px"><h2>Environment</h2><table class="widefat striped"><tbody>'; foreach($env as $k=>$v)echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html($v).'</td></tr>'; echo '</tbody></table></div>';
        echo '<h2>Documents</h2><table class="widefat striped"><thead><tr><th>ID</th><th>เอกสาร</th><th>สถานะ</th><th>Coverage</th><th>Quality</th><th>Method</th><th>KB</th><th></th></tr></thead><tbody>';
        foreach($docs as $d){$url=admin_url('admin.php?page=oae-document-intelligence&doc_id='.(int)$d->id);echo '<tr><td>'.(int)$d->id.'</td><td><strong>'.esc_html($d->title).'</strong><br><small>'.esc_html($d->source_filename).'</small></td><td>'.esc_html($d->status).'</td><td>'.esc_html($d->coverage).'</td><td>'.esc_html($d->quality_score).'</td><td>'.esc_html($d->extraction_method).'</td><td>'.($d->synced_kb_doc_id?'#'.(int)$d->synced_kb_doc_id:'—').'</td><td><a class="button" href="'.esc_url($url).'">ตรวจสอบ</a></td></tr>';}
        if(!$docs)echo '<tr><td colspan="8">ยังไม่มีเอกสาร</td></tr>'; echo '</tbody></table></div>';
    }
    private static function detail(int $id): void {
        global $wpdb; $d=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('docs').' WHERE id=%d',$id)); if(!$d){echo '<div class="wrap"><h1>Not found</h1></div>';return;}
        echo '<div class="wrap"><p><a href="'.esc_url(admin_url('admin.php?page=oae-document-intelligence')).'">← กลับ</a></p><h1>'.esc_html($d->title).'</h1>';
        echo '<p><b>Status:</b> '.esc_html($d->status).' | <b>Coverage:</b> '.esc_html($d->coverage).' | <b>Quality:</b> '.esc_html($d->quality_score).' | <b>Flags:</b> <code>'.esc_html($d->quality_flags).'</code></p><div style="display:flex;gap:8px">';
        foreach(['reprocess'=>'Re-extract / OCR','verify'=>'Verify & Publish to KB','sync'=>'Sync KB Now'] as $a=>$label){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="oaeodi_'.$a.'"><input type="hidden" name="doc_id" value="'.$id.'">';wp_nonce_field('oaeodi_'.$a.'_'.$id);submit_button($label,$a==='verify'?'primary':'secondary','submit',false);echo '</form>';}
        echo '</div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="oaeodi_save"><input type="hidden" name="doc_id" value="'.$id.'">';wp_nonce_field('oaeodi_save_'.$id);
        echo '<p>Title <input class="large-text" name="title" value="'.esc_attr($d->title).'"></p><textarea name="normalized_text" style="width:100%;min-height:550px;font-family:monospace">'.esc_textarea($d->normalized_text).'</textarea>';submit_button('Save reviewed text');echo '</form></div>';
    }

    public static function handle_seed(): void { self::guard('oaeodi_seed'); $r=self::seed(true); self::redirect('Seed imported '.$r['imported'].'; synced '.$r['synced']); }
    public static function handle_upload(): void {
        self::guard('oaeodi_upload'); require_once ABSPATH.'wp-admin/includes/file.php'; $ok=0;
        if(empty($_FILES['pdfs']['name'])||!is_array($_FILES['pdfs']['name']))self::redirect('No PDF selected');
        foreach($_FILES['pdfs']['name'] as $i=>$name){
            if((int)$_FILES['pdfs']['error'][$i]!==UPLOAD_ERR_OK)continue; if((int)$_FILES['pdfs']['size'][$i]>50*1024*1024)continue;
            $tmp=$_FILES['pdfs']['tmp_name'][$i]; if(@file_get_contents($tmp,false,null,0,5)!=='%PDF-')continue;
            $f=['name'=>sanitize_file_name(wp_unslash($name)),'type'=>'application/pdf','tmp_name'=>$tmp,'error'=>0,'size'=>$_FILES['pdfs']['size'][$i]];
            $m=wp_handle_upload($f,['test_form'=>false,'mimes'=>['pdf'=>'application/pdf']]); if(!empty($m['error'])||empty($m['file']))continue;
            $id=self::register_pdf($m['file'],basename($m['file'])); if($id){self::process($id);$ok++;}
        } self::redirect('Uploaded/processed '.$ok.' PDF(s)');
    }
    public static function handle_reprocess(): void { $id=absint($_POST['doc_id']??0);self::guard('oaeodi_reprocess_'.$id);self::process($id);self::redirect('Re-extraction finished',$id); }
    public static function handle_save(): void {
        $id=absint($_POST['doc_id']??0);self::guard('oaeodi_save_'.$id);global $wpdb;$text=self::normalize((string)wp_unslash($_POST['normalized_text']??''));$q=self::quality($text,0);
        $wpdb->update(self::table('docs'),['title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),'normalized_text'=>$text,'quality_score'=>$q['score'],'quality_flags'=>implode(',',$q['flags']),'status'=>'needs_review','updated_at'=>self::now()],['id'=>$id]);self::pages($id,$text);self::audit($id,'manual_review',['chars'=>mb_strlen($text)]);self::redirect('Saved; verify before publishing',$id);
    }
    public static function handle_verify(): void {
        $id=absint($_POST['doc_id']??0);self::guard('oaeodi_verify_'.$id);global $wpdb;$d=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('docs').' WHERE id=%d',$id));if(!$d||mb_strlen(trim((string)$d->normalized_text))<30)self::redirect('No usable text',$id);
        $wpdb->update(self::table('docs'),['status'=>'verified','updated_at'=>self::now()],['id'=>$id]);self::audit($id,'verify');$ok=self::sync($id);self::redirect($ok?'Verified and synced':'Verified; base KB not detected',$id);
    }
    public static function handle_sync(): void { $id=absint($_POST['doc_id']??0);self::guard('oaeodi_sync_'.$id);self::redirect(self::sync($id)?'Synced':'Not synced: verify first / base KB unavailable',$id); }

    private static function register_pdf(string $path,string $name): int {
        global $wpdb;$hash=hash_file('sha256',$path);$key=self::seed_key($name);if(!$key)$key='pdf-'.substr($hash,0,24);$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('docs').' WHERE doc_key=%s',$key));
        $data=['title'=>preg_replace('/\.pdf$/iu','',$name),'source_filename'=>$name,'source_path'=>$path,'source_hash'=>$hash,'source_kind'=>'pdf','status'=>'processing','coverage'=>'full_source_attached','updated_at'=>self::now()];
        if($old){$wpdb->update(self::table('docs'),$data,['id'=>(int)$old->id]);$id=(int)$old->id;}else{$data['doc_key']=$key;$data['created_at']=self::now();$wpdb->insert(self::table('docs'),$data);$id=(int)$wpdb->insert_id;}self::audit($id,'attach_pdf',['filename'=>$name,'sha256'=>$hash]);return $id;
    }
    private static function process(int $id): void {
        global $wpdb;$d=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('docs').' WHERE id=%d',$id));if(!$d||!$d->source_path||!is_readable($d->source_path))return;
        $pages=self::page_count($d->source_path);$native=self::pdftotext($d->source_path);$raw=$native;$method='native_text';
        if(!self::usable($native,$pages)){$ocr=self::ocr($d->source_path,min(60,max(1,$pages)));if(mb_strlen(trim($ocr))>mb_strlen(trim($native))){$raw=$ocr;$method='local_ocr';}else{$method='native_low_quality';}}
        $text=self::normalize($raw);$q=self::quality($text,$pages);$flags=$q['flags'];if($method==='local_ocr')$flags[]='ocr_requires_human_verification';if(mb_strlen($text)<200)$flags[]='extraction_incomplete';$meta=self::metadata($text,$d->title);$rels=self::relationships($text);
        $wpdb->update(self::table('docs'),['title'=>$meta['title']?:$d->title,'doc_type'=>$meta['doc_type'],'agency'=>$meta['agency'],'document_no'=>$meta['document_no'],'document_date'=>$meta['document_date'],'page_count'=>$pages,'extraction_method'=>$method,'status'=>'needs_review','quality_score'=>$q['score'],'quality_flags'=>implode(',',array_unique($flags)),'raw_text'=>$raw,'normalized_text'=>$text,'metadata_json'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE),'relationships_json'=>wp_json_encode($rels,JSON_UNESCAPED_UNICODE),'updated_at'=>self::now()],['id'=>$id]);self::pages($id,$text);self::audit($id,'process',['method'=>$method,'pages'=>$pages,'quality'=>$q['score']]);
    }

    private static function has_cmd(string $cmd): bool { if(!function_exists('exec')||in_array('exec',array_map('trim',explode(',',(string)ini_get('disable_functions'))),true))return false;$o=[];$r=1;@exec('command -v '.escapeshellarg($cmd).' 2>/dev/null',$o,$r);return $r===0&&!empty($o); }
    private static function page_count(string $f): int { if(!self::has_cmd('pdfinfo'))return 0;$o=[];$r=1;@exec('pdfinfo '.escapeshellarg($f).' 2>/dev/null',$o,$r);return $r===0&&preg_match('/^Pages:\s+(\d+)/mi',implode("\n",$o),$m)?(int)$m[1]:0; }
    private static function pdftotext(string $f): string { if(!self::has_cmd('pdftotext'))return '';$t=wp_tempnam('oaeodi.txt');if(!$t)return '';$o=[];$r=1;@exec('pdftotext -layout -enc UTF-8 '.escapeshellarg($f).' '.escapeshellarg($t).' 2>/dev/null',$o,$r);$s=$r===0&&is_readable($t)?(string)file_get_contents($t):'';@unlink($t);return $s; }
    private static function ocr(string $f,int $max): string {
        if(!self::has_cmd('pdftoppm')||!self::has_cmd('tesseract'))return '';$dir=trailingslashit(get_temp_dir()).'oaeodi_'.wp_generate_password(10,false,false);if(!wp_mkdir_p($dir))return '';$prefix=$dir.'/page';$o=[];$r=1;
        @exec('pdftoppm -f 1 -l '.intval($max).' -r 190 -png '.escapeshellarg($f).' '.escapeshellarg($prefix).' >/dev/null 2>&1',$o,$r);$imgs=glob($dir.'/page-*.png')?:[];natsort($imgs);$text='';$p=0;
        foreach($imgs as $img){$p++;$base=$dir.'/ocr-'.$p;$x=[];$rc=1;@exec('tesseract '.escapeshellarg($img).' '.escapeshellarg($base).' -l tha+eng --psm 6 >/dev/null 2>&1',$x,$rc);$txt=$base.'.txt';if($rc===0&&is_readable($txt))$text.="\n[[PAGE:$p]]\n".file_get_contents($txt)."\n";}
        foreach(glob($dir.'/*')?:[] as $x)@unlink($x);@rmdir($dir);return $text;
    }
    private static function usable(string $s,int $pages): bool { $n=mb_strlen(trim($s));if($n<max(220,$pages?120*$pages:220))return false;return substr_count($s,'�')<max(5,(int)($n*.003)); }
    private static function normalize(string $s): string { $s=wp_check_invalid_utf8($s,true);$s=str_replace(["\u{200B}","\u{FEFF}"],'',$s);if(class_exists('Normalizer')){$n=Normalizer::normalize($s,Normalizer::FORM_C);if($n!==false)$s=$n;}$s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$s)??$s;$s=preg_replace('/[ \t]+/u',' ',$s)??$s;$s=preg_replace('/\r\n?|\x{2028}|\x{2029}/u',"\n",$s)??$s;$s=preg_replace('/\n{4,}/u',"\n\n\n",$s)??$s;return trim($s); }
    private static function quality(string $s,int $pages): array { $n=max(1,mb_strlen($s));$score=100;$f=[];$bad=substr_count($s,'�');if($bad){$score-=min(35,($bad/$n)*1000);$f[]='replacement_chars';}$lines=preg_split('/\R/u',$s)?:[];$tiny=0;foreach($lines as $l)if(trim($l)!==''&&mb_strlen(trim($l))<=2)$tiny++;if(count($lines)>20&&$tiny/count($lines)>.18){$score-=18;$f[]='fragmented_glyphs';}if($pages&&$n/$pages<180){$score-=25;$f[]='low_text_per_page';}if($n<300){$score-=30;$f[]='low_text_volume';}return ['score'=>max(0,min(100,round($score,2))),'flags'=>$f]; }
    private static function metadata(string $s,string $fallback): array { $title=$fallback;$no='';$date='';$agency='';$type='general';if(preg_match('/(?:เรื่อง|เรือง)\s*[:\-]?\s*([^\n]{8,220})/u',$s,$m))$title=trim($m[1]);if(preg_match('/(?:ที่|ที)\s*([ก-๙A-Za-z0-9\.\-\/ ]{3,80})/u',$s,$m))$no=trim($m[1]);if(preg_match('/(\d{1,2}|[๐-๙]{1,2})\s+(มกราคม|กุมภาพันธ์|มีนาคม|เมษายน|พฤษภาคม|มิถุนายน|กรกฎาคม|สิงหาคม|กันยายน|ตุลาคม|พฤศจิกายน|ธันวาคม)\s+(\d{4}|[๐-๙]{4})/u',$s,$m))$date=$m[0];foreach(['กระทรวงการคลัง','กรมบัญชีกลาง','สำนักงาน ก.พ.','สำนักงานเศรษฐกิจการเกษตร','กระทรวงเกษตรและสหกรณ์','สำนักนายกรัฐมนตรี'] as $a)if(mb_stripos($s,$a)!==false){$agency=$a;break;}if(mb_stripos($title,'ระเบียบ')!==false)$type='regulation';elseif(mb_stripos($title,'คำสั่ง')!==false)$type='order';elseif(mb_stripos($title,'แบบฟอร์ม')!==false)$type='form_circular';elseif(mb_stripos($title,'หลักเกณฑ์')!==false||mb_stripos($title,'แนวทาง')!==false)$type='guideline';elseif($no)$type='circular';return compact('title','agency')+['document_no'=>$no,'document_date'=>$date,'doc_type'=>$type]; }
    private static function relationships(string $s): array { $r=[];foreach(['amends'=>'แก้ไขเพิ่มเติม','replaces'=>'ให้ใช้ความต่อไปนี้แทน','repeals'=>'ให้ยกเลิก','references'=>'อ้างถึง'] as $t=>$q)if(mb_stripos($s,$q)!==false)$r[]=['type'=>$t,'evidence'=>$q];return $r; }
    private static function pages(int $id,string $s): void { global $wpdb;$wpdb->delete(self::table('pages'),['doc_id'=>$id]);$parts=preg_split('/\[\[PAGE:(\d+)\]\]/u',$s,-1,PREG_SPLIT_DELIM_CAPTURE);$pages=[];if($parts&&count($parts)>2){for($i=1;$i<count($parts)-1;$i+=2)$pages[(int)$parts[$i]]=trim($parts[$i+1]);}else$pages[1]=$s;foreach($pages as $n=>$x){$q=self::quality($x,1);$wpdb->insert(self::table('pages'),['doc_id'=>$id,'page_no'=>$n,'normalized_text'=>$x,'quality_score'=>$q['score'],'quality_flags'=>implode(',',$q['flags']),'created_at'=>self::now(),'updated_at'=>self::now()]);} }

    private static function manifest(): array { $f=plugin_dir_path(__FILE__).'data/seed-manifest.json';$x=is_readable($f)?json_decode((string)file_get_contents($f),true):[];return is_array($x)?$x:[]; }
    private static function canon(string $s): string { $s=mb_strtolower(trim($s));$s=preg_replace('/\s+/u','',$s)??$s;return preg_replace('/[^ก-๙a-z0-9\.]+/u','',$s)??$s; }
    private static function seed_key(string $name): string { $c=self::canon($name);foreach(self::manifest() as $s)if($c===self::canon((string)($s['source_filename']??'')))return (string)$s['doc_key'];return ''; }
    private static function seed(bool $auto): array {
        self::schema();global $wpdb;$n=0;$synced=0;foreach(self::manifest() as $s){$key=sanitize_key((string)($s['doc_key']??''));$cf=plugin_dir_path(__FILE__).'data/'.basename((string)($s['content_file']??''));if(!$key||!is_readable($cf))continue;$text=self::normalize((string)file_get_contents($cf));$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('docs').' WHERE doc_key=%s',$key));$meta=$s;unset($meta['content_file']);$data=['title'=>(string)$s['title'],'source_filename'=>(string)$s['source_filename'],'source_kind'=>'seed','doc_type'=>(string)($s['doc_type']??'general'),'agency'=>(string)($s['agency']??''),'document_no'=>(string)($s['document_no']??''),'document_date'=>(string)($s['document_date']??''),'effective_date'=>(string)($s['effective_date']??''),'page_count'=>(int)($s['page_count']??0),'extraction_method'=>(string)($s['extraction_method']??'verified_seed'),'status'=>(string)($s['status']??'needs_review'),'coverage'=>(string)($s['coverage']??'verified_extract'),'quality_score'=>(float)($s['quality_score']??0),'quality_flags'=>implode(',',(array)($s['quality_flags']??[])),'raw_text'=>$text,'normalized_text'=>$text,'metadata_json'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE),'relationships_json'=>wp_json_encode((array)($s['relationships']??[]),JSON_UNESCAPED_UNICODE),'updated_at'=>self::now()];
            if($old){if($old->source_path||$old->source_kind==='pdf')$data=['metadata_json'=>$data['metadata_json'],'relationships_json'=>$data['relationships_json'],'updated_at'=>self::now()];$wpdb->update(self::table('docs'),$data,['id'=>(int)$old->id]);$id=(int)$old->id;}else{$data['doc_key']=$key;$data['created_at']=self::now();$wpdb->insert(self::table('docs'),$data);$id=(int)$wpdb->insert_id;}if(!$old||(!$old->source_path&&$old->source_kind!=='pdf'))self::pages($id,$text);self::audit($id,'seed_import',['coverage'=>$s['coverage']??'']);$n++;if($auto&&($s['status']??'')==='verified'&&self::sync($id))$synced++;}
        update_option('oaeodi_seed_version',self::VERSION,false);return ['imported'=>$n,'synced'=>$synced];
    }

    private static function base_ready(): bool { global $wpdb;$d=self::base('kb_docs');$c=self::base('kb_chunks');return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$d))===$d&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$c))===$c; }
    private static function cols(string $t): array { global $wpdb;$o=[];foreach($wpdb->get_results('SHOW COLUMNS FROM `'.esc_sql($t).'`')?:[] as $r)$o[$r->Field]=1;return $o; }
    private static function supported(array $v,array $c): array { return array_intersect_key($v,$c); }
    private static function sync(int $id): bool {
        if(!self::base_ready())return false;global $wpdb;$d=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('docs').' WHERE id=%d',$id));if(!$d||$d->status!=='verified'||mb_strlen(trim((string)$d->normalized_text))<30)return false;$dt=self::base('kb_docs');$ct=self::base('kb_chunks');$dc=self::cols($dt);$cc=self::cols($ct);$ref='oaeodi://'.$d->doc_key;$old=isset($dc['source_ref'])?$wpdb->get_var($wpdb->prepare("SELECT id FROM $dt WHERE source_ref=%s LIMIT 1",$ref)):0;
        $body="[OAE DOCUMENT INTELLIGENCE]\nCoverage: {$d->coverage}\nQuality flags: {$d->quality_flags}\nRule: If coverage is a key extract, do not infer omitted provisions; request verification against the original PDF for details outside this extract.\n\n".$d->normalized_text;$hash=hash('sha256',$body);
        $base=self::supported(['title'=>$d->title,'source_type'=>'oaeodi','source_ref'=>$ref,'content'=>$body,'full_content'=>$body,'normalized_content'=>$body,'content_hash'=>$hash,'quality_score'=>$d->quality_score,'quality_flags'=>$d->quality_flags,'updated_at'=>self::now()],$dc);
        if($old){$wpdb->update($dt,$base,['id'=>(int)$old]);$kb=(int)$old;}else{$ins=array_merge($base,self::supported(['document_type'=>$d->doc_type,'doc_type'=>$d->doc_type,'version_label'=>'OAEODI '.self::VERSION,'authority_score'=>self::authority($d->doc_type),'lifecycle'=>'current','visibility'=>'internal','data_classification'=>'internal','active'=>1,'created_at'=>self::now()],$dc));if(isset($dc['created_at'])&&!isset($ins['created_at']))$ins['created_at']=self::now();$wpdb->insert($dt,$ins);$kb=(int)$wpdb->insert_id;}if(!$kb)return false;$wpdb->delete($ct,['doc_id'=>$kb]);$i=0;foreach(self::chunks($body) as $x){$row=self::supported(['doc_id'=>$kb,'chunk_index'=>$i++,'content'=>$x,'keywords'=>self::keywords($x),'content_hash'=>hash('sha256',$x),'char_count'=>mb_strlen($x),'token_estimate'=>(int)ceil(mb_strlen($x)/3.2),'quality_score'=>$d->quality_score,'created_at'=>self::now()],$cc);$wpdb->insert($ct,$row);}$wpdb->update(self::table('docs'),['synced_kb_doc_id'=>$kb,'updated_at'=>self::now()],['id'=>$id]);self::audit($id,'sync',['kb_doc_id'=>$kb,'chunks'=>$i]);do_action('oaeodi_synced_to_kb',$kb,$id);return true;
    }
    private static function authority(string $t): int { return match($t){'regulation'=>95,'order'=>92,'circular'=>88,'guideline'=>86,'form_circular'=>82,default=>70}; }
    private static function chunks(string $s): array { $lines=preg_split('/\R/u',self::normalize($s))?:[];$out=[];$b='';$boundary='/^(?:\[\[PAGE:\d+\]\]|หมวด\s*\d+|ส่วนที่\s*\d+|ข้อ\s*[๐-๙0-9]+|บัญชี(?:หมายเลข)?\s*[๐-๙0-9]+|เรื่อง\s+)/u';foreach($lines as $l){$l=trim($l);if($l==='')continue;if(preg_match($boundary,$l)&&mb_strlen($b)>=700){$out[]=trim($b);$b='';}if($b!==''&&mb_strlen($b)+mb_strlen($l)>3200){$out[]=trim($b);$b='';}$b.=($b===''?'':"\n").$l;}if(trim($b)!=='')$out[]=trim($b);return array_values(array_filter($out,fn($x)=>mb_strlen($x)>20)); }
    private static function keywords(string $s): string { $x=[];foreach(['ฝึกอบรม','สัมมนา','ศึกษา','ลา','สัญญา','ชดใช้เงิน','ค้ำประกัน','เทียบตำแหน่ง','อนุมัติ','ค่าใช้จ่าย','วิทยากร','ดูงาน','ปฏิบัติการวิจัย','ต่างประเทศ','ในประเทศ','ข้าราชการ'] as $t)if(mb_stripos($s,$t)!==false)$x[]=$t;return implode(' ',array_unique($x)); }
    private static function env(): array { return ['PHP'=>PHP_VERSION,'WordPress'=>get_bloginfo('version'),'pdftotext'=>self::has_cmd('pdftotext')?'ready':'not found','pdfinfo'=>self::has_cmd('pdfinfo')?'ready':'not found','pdftoppm'=>self::has_cmd('pdftoppm')?'ready':'not found','tesseract tha+eng'=>self::has_cmd('tesseract')?'binary detected; verify tha data':'not found','Unicode Normalizer'=>class_exists('Normalizer')?'ready':'not available','Base OAE AI KB'=>self::base_ready()?'detected':'not detected','Policy'=>'local-first; human verification required before new PDF publication']; }
}
register_activation_hook(__FILE__,['OAEODI','activate']);
OAEODI::boot();
