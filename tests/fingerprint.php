<?php
global $wpdb;
$owned = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_eds_owned'");
$kits = $wpdb->get_col("SELECT clone_id FROM {$wpdb->prefix}eds_objects WHERE kind='kit'");
foreach ($kits as $k) { $ids = get_post_meta((int)$k, '_elementor_global_classes_post_ids', true); if (is_array($ids)) foreach ($ids as $c) if (is_numeric($c)) $owned[] = (int)$c; }
$owned = array_map('intval', $owned); echo "X owned=".count($owned)."\n";
$rows = $wpdb->get_results("SELECT ID,post_type,post_status,post_title,post_content,post_name FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','eds_page') ORDER BY ID");
foreach ($rows as $r) {
  if (in_array((int)$r->ID, $owned, true)) continue;
  $m = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key NOT IN ('_edit_lock','_edit_last','_elementor_css','_elementor_element_cache','_elementor_page_assets') ORDER BY meta_key,meta_id",$r->ID));
  $mm=''; foreach($m as $x) $mm.=$x->meta_key.'='.$x->meta_value."\n";
  echo "P {$r->ID} {$r->post_type} {$r->post_status} ".md5($r->post_title.$r->post_name.$r->post_content)." ".md5($mm)."\n";
}
$o = $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' AND option_name NOT LIKE 'eds%' AND option_name NOT IN ('cron','elementor_log','elementor_remote_info_library','elementor_remote_info_feed_data','elementor_notes_db_version','recently_activated','active_plugins','rewrite_rules','_site_transient_update_plugins','finished_updating_comment_type','_elementor_ab_testing_data','_elementor_design_system_sync_css_meta') ORDER BY option_name");
foreach ($o as $x) echo "O {$x->option_name} ".md5($x->option_value)."\n";
$t = $wpdb->get_results("SELECT t.term_id,t.name,tt.taxonomy,tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING(term_id) ORDER BY t.term_id");
foreach ($t as $x) echo "T {$x->term_id} {$x->taxonomy} {$x->count} ".md5($x->name)."\n";
echo "U ".md5(serialize($wpdb->get_results("SELECT user_login,user_pass,user_email FROM {$wpdb->users}")))."\n";
$d = wp_upload_dir()['basedir'].'/elementor';
if (is_dir($d)) { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)); $f=[]; foreach($it as $x) $f[substr($x->getPathname(),strlen($d))]=md5_file($x->getPathname()); ksort($f); foreach($f as $k=>$v) echo "F $k $v\n"; }
