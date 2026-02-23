<?php
/**
 * Plugin Name: DM IDML Importer
 * Description: Imports repeating InDesign IDML layouts into Digitales Magazin ACF blocks (Infoseiten + Fotostrecken).
 * Version: 0.1.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Avoid fatals if the plugin file is loaded twice for any reason.
if (class_exists('DM_IDML_Importer_Plugin', false)) {
    return;
}

final class DM_IDML_Importer_Plugin
{
    private const IDML_NS = 'http://ns.adobe.com/AdobeInDesign/idml/1.0/packaging';
    private const ADMIN_SLUG = 'dm-idml-import';
    private const GITHUB_REPO_DEFAULT = 'Felixcmr/dm-idml-importer';

    private static function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    private static function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }

    private static function str_contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }

    private static function decode_idml_style_name(string $style): string
    {
        // Styles can contain URL-encoded pieces (e.g. "%3a") in some IDML exports.
        $decoded = rawurldecode($style);
        return $decoded !== '' ? $decoded : $style;
    }

    private static function is_url_character_style(string $character_style): bool
    {
        $s = self::decode_idml_style_name($character_style);
        if (self::str_ends_with($s, '/URL')) {
            return true;
        }
        $s = strtolower($s);
        return strpos($s, 'www') !== false || strpos($s, 'url') !== false;
    }

    public static function register_wp_cli(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('dm idml import', [self::class, 'wp_cli_import']);
    }

    public static function register_admin(): void
    {
        if (!is_admin()) {
            return;
        }

        add_filter('upload_mimes', [self::class, 'filter_upload_mimes']);
        add_filter('wp_check_filetype_and_ext', [self::class, 'filter_check_filetype_and_ext'], 10, 5);

        add_action('admin_menu', static function (): void {
            add_management_page(
                'IDML Import',
                'IDML Import',
                'edit_posts',
                self::ADMIN_SLUG,
                [self::class, 'render_admin_page']
            );
        });
    }

    /**
     * Allow uploading `.idml` (IDML is a zip container).
     *
     * @param array<string,string> $mimes
     * @return array<string,string>
     */
    public static function filter_upload_mimes(array $mimes): array
    {
        // Only grant the mime when the current user can upload files.
        if (current_user_can('upload_files')) {
            $mimes['idml'] = 'application/zip';
        }
        return $mimes;
    }

    /**
     * Some WP setups validate the real mime against the expected mime for the extension.
     *
     * Note: WordPress may pass `null` for the `$mimes` argument.
     *
     * @param array{ext?:string,type?:string,proper_filename?:string|false} $data
     * @param mixed $mimes
     * @param mixed $real_mime
     * @return array{ext?:string,type?:string,proper_filename?:string|false}
     */
    public static function filter_check_filetype_and_ext(array $data, string $file, string $filename, $mimes, $real_mime = null): array
    {
        if (current_user_can('upload_files') && self::str_ends_with(strtolower($filename), '.idml')) {
            $data['ext'] = 'idml';
            $data['type'] = 'application/zip';
            $data['proper_filename'] = false;
        }
        return $data;
    }

    public static function render_admin_page(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions.');
        }

        $page_url = admin_url('tools.php?page=' . self::ADMIN_SLUG);
        $layout = isset($_POST['layout']) ? sanitize_key((string)$_POST['layout']) : (isset($_GET['layout']) ? sanitize_key((string)$_GET['layout']) : 'infoseiten');
        if (!in_array($layout, ['infoseiten', 'fotostrecke'], true)) {
            $layout = 'infoseiten';
        }

        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = self::handle_admin_post();
        }

        echo '<div class="wrap">';
        echo '<h1>IDML Import</h1>';
        echo '<p>Upload an <code>.idml</code> and generate Gutenberg block markup for copy/paste into your editor.</p>';

        if (is_array($result) && isset($result['notice'])) {
            $class = !empty($result['ok']) ? 'notice notice-success' : 'notice notice-error';
            echo '<div class="' . esc_attr($class) . '"><p>' . wp_kses_post((string)$result['notice']) . '</p></div>';
        }

        if (is_array($result) && !empty($result['warnings']) && is_array($result['warnings'])) {
            echo '<div class="notice notice-warning"><p><strong>Warnings</strong></p><ul style="margin-left: 1.25em; list-style: disc;">';
            foreach ($result['warnings'] as $w) {
                echo '<li>' . esc_html((string)$w) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . esc_url($page_url) . '" enctype="multipart/form-data">';
        wp_nonce_field('dm_idml_import', '_dm_idml_nonce');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="layout">Template</label></th><td>';
        echo '<select id="layout" name="layout">';
        echo '<option value="infoseiten"' . selected($layout, 'infoseiten', false) . '>Infoseiten (Content Teaser)</option>';
        echo '<option value="fotostrecke"' . selected($layout, 'fotostrecke', false) . '>Fotostrecke (Parallax Background)</option>';
        echo '</select>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="idml_file">IDML file</label></th><td>';
        echo '<input type="file" id="idml_file" name="idml_file" accept=".idml,application/octet-stream,application/zip" required />';
        echo '<p class="description">The IDML stays in your uploads folder unless you delete it manually.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Mode</th><td>';
        echo '<label><input type="checkbox" name="dry_run" value="1" checked /> Preview only (recommended)</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Options</th><td>';
        echo '<label>Header image: ';
        echo '<select name="header_image">';
        echo '<option value="last" selected>Use last teaser image</option>';
        echo '<option value="first">Use first teaser image</option>';
        echo '<option value="none">No header image</option>';
        echo '</select></label><br />';
        echo '<label><input type="checkbox" name="newtab_all" value="1" checked /> Teaser links open in new tab</label><br />';
        echo '</td></tr>';
        echo '</tbody></table>';

        submit_button('Generate blocks');
        echo '</form>';

        if (is_array($result) && !empty($result['generated_blocks'])) {
            echo '<h2>Generated blocks</h2>';
            echo '<textarea readonly style="width:100%; min-height: 240px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;">' . esc_textarea((string)$result['generated_blocks']) . '</textarea>';
            echo '<p class="description">In the block editor, switch to the code editor and paste this markup.</p>';
        }

        if (is_array($result) && !empty($result['image_report']) && is_array($result['image_report'])) {
            echo '<h2>Image mapping</h2>';
            echo '<table class="widefat striped"><thead><tr><th>Kind</th><th>Label</th><th>Expected file</th><th>Attachment ID</th></tr></thead><tbody>';
            foreach ($result['image_report'] as $row) {
                $kind = (string)($row['kind'] ?? '');
                $label = (string)($row['label'] ?? '');
                $expected = (string)($row['expected_file'] ?? '');
                $aid = (int)($row['attachment_id'] ?? 0);
                echo '<tr>';
                echo '<td>' . esc_html($kind) . '</td>';
                echo '<td>' . esc_html($label) . '</td>';
                echo '<td><code>' . esc_html($expected) . '</code></td>';
                echo '<td>' . esc_html((string)$aid) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">If Attachment ID is 0, the image is not present in this WordPress Media Library.</p>';
        }

        if (is_array($result) && !empty($result['order_report']) && is_array($result['order_report'])) {
            echo '<h2>Order override</h2>';
            echo '<p class="description">Set order numbers (lower first) and click “Regenerate with order”.</p>';
            echo '<form method="post" action="' . esc_url($page_url) . '">';
            wp_nonce_field('dm_idml_import', '_dm_idml_nonce');
            echo '<input type="hidden" name="layout" value="' . esc_attr($layout) . '" />';
            echo '<input type="hidden" name="header_image" value="' . esc_attr(isset($_POST['header_image']) ? (string)$_POST['header_image'] : 'last') . '" />';
            echo '<input type="hidden" name="newtab_all" value="' . esc_attr(!empty($_POST['newtab_all']) ? '1' : '0') . '" />';
            echo '<input type="hidden" name="dry_run" value="1" />';
            echo '<input type="hidden" name="existing_idml_file" value="' . esc_attr((string)($result['existing_idml_file'] ?? '')) . '" />';
            echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Label</th></tr></thead><tbody>';
            foreach ($result['order_report'] as $row) {
                $sid = (string)($row['story_id'] ?? '');
                $label = (string)($row['label'] ?? '');
                $order = (int)($row['order'] ?? 0);
                echo '<tr>';
                echo '<td style="width:120px;"><input type="number" name="order[' . esc_attr($sid) . ']" value="' . esc_attr((string)$order) . '" /></td>';
                echo '<td>' . esc_html($label) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            submit_button('Regenerate with order', 'secondary');
            echo '</form>';
        }

        echo '</div>';
    }

    /**
     * @return array{ok:bool,notice:string,warnings?:list<string>,generated_blocks?:string,image_report?:array}
     */
    private static function handle_admin_post(): array
    {
        if (!current_user_can('edit_posts')) {
            return ['ok' => false, 'notice' => 'Insufficient permissions.'];
        }
        if (!isset($_POST['_dm_idml_nonce']) || !wp_verify_nonce((string)$_POST['_dm_idml_nonce'], 'dm_idml_import')) {
            return ['ok' => false, 'notice' => 'Invalid nonce.'];
        }
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'notice' => 'ZipArchive not available on this server.'];
        }

        $path = '';
        $existing = isset($_POST['existing_idml_file']) ? (string)$_POST['existing_idml_file'] : '';
        if ($existing !== '') {
            $uploads = wp_upload_dir();
            $basedir = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';
            if ($basedir !== '' && self::str_starts_with($existing, $basedir) && self::str_ends_with(strtolower($existing), '.idml') && file_exists($existing)) {
                $path = $existing;
            }
        }

        if ($path === '') {
            if (empty($_FILES['idml_file']) || !is_array($_FILES['idml_file']) || empty($_FILES['idml_file']['name'])) {
                return ['ok' => false, 'notice' => 'No file uploaded.'];
            }

            $name = (string)$_FILES['idml_file']['name'];
            if (!self::str_ends_with(strtolower($name), '.idml')) {
                return ['ok' => false, 'notice' => 'Please upload an .idml file.'];
            }

            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $overrides = ['test_form' => false];
            $uploaded = wp_handle_upload($_FILES['idml_file'], $overrides);
            if (!is_array($uploaded) || !empty($uploaded['error']) || empty($uploaded['file'])) {
                $err = is_array($uploaded) && !empty($uploaded['error']) ? (string)$uploaded['error'] : 'Upload failed.';
                return ['ok' => false, 'notice' => esc_html($err)];
            }

            $path = (string)$uploaded['file'];
        }
        $header_image = isset($_POST['header_image']) ? sanitize_key((string)$_POST['header_image']) : 'last';
        if (!in_array($header_image, ['last', 'first', 'none'], true)) {
            $header_image = 'last';
        }

        $layout = isset($_POST['layout']) ? sanitize_key((string)$_POST['layout']) : 'infoseiten';
        if (!in_array($layout, ['infoseiten', 'fotostrecke'], true)) {
            $layout = 'infoseiten';
        }

        $newtab_all = !empty($_POST['newtab_all']);
        $dry_run = !empty($_POST['dry_run']);
        $order = isset($_POST['order']) && is_array($_POST['order']) ? $_POST['order'] : [];

        try {
            $parsed = self::parse_idml($path, ['layout' => $layout, 'order' => $order]);
            $blocks = self::build_blocks($parsed, [
                'header_image_mode' => $header_image,
                'newtab_mode' => $newtab_all ? 'all' : 'none',
                'layout' => $layout,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'notice' => 'Import failed: ' . esc_html($e->getMessage())];
        }

        $warnings = isset($parsed['warnings']) && is_array($parsed['warnings']) ? $parsed['warnings'] : [];
        if (
            ($parsed['headline'] ?? '') === '' ||
            ($parsed['lead'] ?? '') === '' ||
            (empty($parsed['teasers']) && empty($parsed['parallax_items']) && empty($parsed['info_teasers']))
        ) {
            $warnings[] = sprintf(
                'Extracted: headline=%s, lead=%s, teasers=%d, info_teasers=%d, parallax_items=%d.',
                ($parsed['headline'] ?? '') !== '' ? 'yes' : 'no',
                ($parsed['lead'] ?? '') !== '' ? 'yes' : 'no',
                is_array($parsed['teasers'] ?? null) ? count((array)$parsed['teasers']) : 0,
                is_array($parsed['info_teasers'] ?? null) ? count((array)$parsed['info_teasers']) : 0,
                is_array($parsed['parallax_items'] ?? null) ? count((array)$parsed['parallax_items']) : 0
            );
        }

        return [
            'ok' => true,
            'notice' => $dry_run ? 'Generated successfully (preview only).' : 'Generated successfully.',
            'warnings' => $warnings,
            'generated_blocks' => $blocks,
            'image_report' => isset($parsed['image_report']) && is_array($parsed['image_report']) ? $parsed['image_report'] : [],
            'order_report' => isset($parsed['order_report']) && is_array($parsed['order_report']) ? $parsed['order_report'] : [],
            'existing_idml_file' => $path,
        ];
    }

    /**
     * GitHub update notifications (no one-click updates).
     *
     * Define these constants in `wp-config.php` on each environment:
     * - `DM_IDML_IMPORTER_GITHUB_REPO`  e.g. `your-org/dm-idml-importer` (optional)
     */
    public static function register_update_notifications(): void
    {
        if (!is_admin()) {
            return;
        }

        add_filter('site_transient_update_plugins', [self::class, 'filter_update_plugins_transient']);

        $basename = plugin_basename(__FILE__);
        add_action('in_plugin_update_message-' . $basename, [self::class, 'render_update_message'], 10, 2);
    }

    public static function filter_update_plugins_transient($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $info = self::get_github_release_update_info();
        if (!$info) {
            return $transient;
        }

        $basename = plugin_basename(__FILE__);
        $item = new \stdClass();
        $item->slug = 'dm-idml-importer';
        $item->plugin = $basename;
        $item->new_version = $info['version'];
        $item->url = $info['url'];
        // No package URL (updates only show; installation is manual ZIP upload).
        $item->package = '';

        $transient->response[$basename] = $item;
        return $transient;
    }

    public static function render_update_message(array $plugin_data, $response): void
    {
        $info = self::get_github_release_update_info();
        if (!$info) {
            return;
        }

        $url = (string)$info['url'];
        $ver = (string)$info['version'];
        echo ' New version <strong>' . esc_html($ver) . '</strong> available. ';
        if ($url !== '') {
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">View release</a>.';
        } else {
            echo 'View the latest release in GitHub.';
        }
        echo ' Install by downloading the ZIP and uploading it in <em>Plugins → Add New → Upload Plugin</em>.';
    }

    /**
     * @return array{version:string,url:string}|null
     */
    private static function get_github_release_update_info(): ?array
    {
        $repo = self::get_github_repo();
        if ($repo === '') {
            return null;
        }

        $current = self::get_current_plugin_version();
        if ($current === '') {
            return null;
        }

        $cached = get_transient('dm_idml_importer_update_info');
        if (is_array($cached) && isset($cached['checked']) && (time() - (int)$cached['checked']) < 6 * HOUR_IN_SECONDS) {
            $latest = (string)($cached['latest'] ?? '');
            $url = (string)($cached['url'] ?? '');
            if ($latest !== '' && version_compare($latest, $current, '>')) {
                return ['version' => $latest, 'url' => $url];
            }
            return null;
        }

        $api_url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $args = [
            'timeout' => 12,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'wp-dm-idml-importer',
            ],
        ];

        $res = wp_remote_get($api_url, $args);
        $latest_version = '';
        $latest_url = '';

        if (!is_wp_error($res)) {
            $code = (int)wp_remote_retrieve_response_code($res);
            $body = (string)wp_remote_retrieve_body($res);
            if ($code >= 200 && $code < 300 && $body !== '') {
                $json = json_decode($body, true);
                if (is_array($json)) {
                    $tag = (string)($json['tag_name'] ?? '');
                    $latest_url = (string)($json['html_url'] ?? '');
                    $latest_version = ltrim($tag, "vV \t\n\r\0\x0B");
                }
            }
        }

        set_transient('dm_idml_importer_update_info', [
            'checked' => time(),
            'latest' => $latest_version,
            'url' => $latest_url,
        ], 6 * HOUR_IN_SECONDS);

        if ($latest_version !== '' && version_compare($latest_version, $current, '>')) {
            return ['version' => $latest_version, 'url' => $latest_url];
        }

        return null;
    }

    private static function get_github_repo(): string
    {
        if (defined('DM_IDML_IMPORTER_GITHUB_REPO')) {
            $repo = (string)DM_IDML_IMPORTER_GITHUB_REPO;
            return trim($repo);
        }
        return self::GITHUB_REPO_DEFAULT;
    }

    private static function get_current_plugin_version(): string
    {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
        $ver = is_array($data) ? (string)($data['Version'] ?? '') : '';
        return trim($ver);
    }

    /**
     * Usage:
     *   wp dm idml import /path/file.idml --post_id=123 [--replace] [--dry-run]
     *   wp dm idml import /path/file.idml --post_type=page --post_status=draft --post_title="..." [--replace] [--dry-run]
     *
     * Options:
     *   --post_id=<id>               Update an existing post.
     *   --post_type=<type>           Post type to create if post_id is not provided. Default: page
     *   --post_status=<status>       Post status to create if post_id is not provided. Default: draft
     *   --post_title=<title>         Post title to create if post_id is not provided. Default: headline from IDML
     *   --replace                    Replace post_content (default is replace unless --append is set).
     *   --append                     Append to existing post_content.
     *   --dry-run                    Output generated blocks, don’t write.
     *   --header-image=<mode>        Header image mode: last|first|none. Default: last
     *   --newtab=<mode>              Teaser links open new tab: all|none. Default: all
     *   --layout=<layout>            Template: infoseiten|fotostrecke. Default: infoseiten
     */
    public static function wp_cli_import(array $args, array $assoc_args): void
    {
        $idml_path = (string)($args[0] ?? '');
        if ($idml_path === '' || !file_exists($idml_path)) {
            \WP_CLI::error('IDML path missing or not found.');
        }

        if (!class_exists(\ZipArchive::class)) {
            \WP_CLI::error('ZipArchive not available in this PHP build.');
        }

        $header_image_mode = (string)($assoc_args['header-image'] ?? 'last');
        if (!in_array($header_image_mode, ['last', 'first', 'none'], true)) {
            \WP_CLI::error('Invalid --header-image. Use last|first|none.');
        }

        $newtab_mode = (string)($assoc_args['newtab'] ?? 'all');
        if (!in_array($newtab_mode, ['all', 'none'], true)) {
            \WP_CLI::error('Invalid --newtab. Use all|none.');
        }

        $layout = (string)($assoc_args['layout'] ?? 'infoseiten');
        if (!in_array($layout, ['infoseiten', 'fotostrecke'], true)) {
            \WP_CLI::error('Invalid --layout. Use infoseiten|fotostrecke.');
        }

        $dry_run = array_key_exists('dry-run', $assoc_args);
        $append = array_key_exists('append', $assoc_args);

        $parsed = self::parse_idml($idml_path, ['layout' => $layout]);
        $blocks = self::build_blocks($parsed, [
            'header_image_mode' => $header_image_mode,
            'newtab_mode' => $newtab_mode,
            'layout' => $layout,
        ]);

        if ($dry_run) {
            \WP_CLI::line($blocks);
            return;
        }

        $post_id = isset($assoc_args['post_id']) ? (int)$assoc_args['post_id'] : 0;
        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post) {
                \WP_CLI::error('Post not found for --post_id.');
            }
        } else {
            $post_type = (string)($assoc_args['post_type'] ?? 'page');
            $post_status = (string)($assoc_args['post_status'] ?? 'draft');
            $post_title = (string)($assoc_args['post_title'] ?? '');
            if ($post_title === '') {
                $post_title = $parsed['headline'] !== '' ? $parsed['headline'] : 'Imported IDML';
            }

            $post_id = (int)wp_insert_post([
                'post_type' => $post_type,
                'post_status' => $post_status,
                'post_title' => $post_title,
                'post_content' => '',
            ], true);

            if (is_wp_error($post_id) || $post_id <= 0) {
                \WP_CLI::error('Failed to create post.');
            }
        }

        $existing = (string)get_post_field('post_content', $post_id);
        $new_content = $append ? (rtrim($existing) . "\n\n" . ltrim($blocks)) : $blocks;

        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content,
        ], true);

        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }

        \WP_CLI::success('Imported IDML into post_id=' . $post_id);
    }

    /**
     * @return array{
     *   headline:string,
     *   lead:string,
     *   teasers: list<array{story_id:string, location:string, headline:string, intro:string, url:string, image_basename:string, attachment_id:int}>,
     *   info_teasers: list<array{story_id:string, location:string, headline:string, intro:string, url:string, image_basename:string, attachment_id:int}>,
     *   parallax_items: list<array{story_id:string, location:string, title:string, body:string, image_basename:string, attachment_id:int}>,
     *   image_report?: list<array{kind:string,label:string,expected_file:string,attachment_id:int}>,
     *   warnings:list<string>
     * }
     */
    private static function parse_idml(string $idml_path, array $options = []): array
    {
        $layout = (string)($options['layout'] ?? 'infoseiten');
        if (!in_array($layout, ['infoseiten', 'fotostrecke'], true)) {
            $layout = 'infoseiten';
        }
        $order_override = isset($options['order']) && is_array($options['order']) ? $options['order'] : [];

        $zip = new \ZipArchive();
        $opened = $zip->open($idml_path);
        if ($opened !== true) {
            throw new \RuntimeException('Failed to open IDML.');
        }

        $warnings = [];

        $images = [];
        $text_frames = [];
        $spread_files_seen = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!self::str_starts_with($name, 'Spreads/') || !self::str_ends_with($name, '.xml')) {
                continue;
            }
            $spread_files_seen++;
            $spread_xml = (string)$zip->getFromIndex($i);
            if ($spread_xml === '') {
                continue;
            }
            $spread = self::parse_spread_positions($spread_xml);
            foreach (($spread['text_frames'] ?? []) as $sid => $pos) {
                if (!isset($text_frames[$sid])) {
                    $text_frames[$sid] = $pos;
                }
            }
            foreach (($spread['images'] ?? []) as $img) {
                $images[] = $img;
            }
        }

        if ($spread_files_seen === 0) {
            $zip->close();
            throw new \RuntimeException('No spread XML found in IDML.');
        }

        $stories = [];
        $story_files_seen = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!self::str_starts_with($name, 'Stories/') || !self::str_ends_with($name, '.xml')) {
                continue;
            }
            $story_files_seen++;
            $xml = (string)$zip->getFromIndex($i);
            $story = self::parse_story($xml);
            if ($story['id'] !== '' || ($story['paragraphs'] ?? []) !== []) {
                $stories[] = $story;
            }
        }

        $zip->close();

        $headline = '';
        $lead = '';
        $teaser_stories = [];
        $parallax_stories = [];
        $info_stories = [];
        $all_para_styles = [];

        foreach ($stories as $story) {
            $style_names = $story['paragraph_styles'];
            foreach ($style_names as $sn) {
                $all_para_styles[$sn] = true;
            }
            $is_info_like = self::story_has_paragraph_style($style_names, 'Info_DZ') && self::story_has_paragraph_style($style_names, 'Info_LT');

            if ($headline === '' && !$is_info_like && self::story_has_any_paragraph_style_prefix($style_names, 'ParagraphStyle/01_Headline')) {
                $headline = self::strip_urls(self::normalize_text($story['text_all']));
                continue;
            }
            if ($lead === '' && self::story_has_any_paragraph_style_prefix($style_names, 'ParagraphStyle/02_Vorspann')) {
                $lead = self::strip_urls(self::normalize_text($story['text_all']));
                continue;
            }

            if (
                self::story_has_paragraph_style($style_names, '06_Ort_KT_Meldungen') &&
                self::story_has_paragraph_style($style_names, '03_LT_links') &&
                $story['has_url_style'] === true
            ) {
                $teaser_stories[] = $story;
            }

            if (
                self::story_has_paragraph_style($style_names, 'Info_DZ') &&
                self::story_has_paragraph_style($style_names, 'Head_Info') &&
                self::story_has_paragraph_style($style_names, 'Info_LT')
            ) {
                $parallax_stories[] = $story;
                $info_stories[] = $story;
            }
        }

        // Heuristic fallbacks for magazines using different style names (e.g. TTG "H1 60/60", etc.).
        if ($headline === '') {
            $candidates = [];
            foreach ($stories as $story) {
                $styles = $story['paragraph_styles'];
                $is_info_like = self::story_has_paragraph_style($styles, 'Info_DZ') && self::story_has_paragraph_style($styles, 'Info_LT');
                if ($is_info_like) {
                    continue;
                }
                if (
                    self::story_has_paragraph_style($styles, 'Head versal') ||
                    self::story_has_any_paragraph_style_prefix($styles, 'ParagraphStyle/H1')
                ) {
                    $text = self::strip_urls(self::normalize_text($story['text_all']));
                    if ($text !== '') {
                        $candidates[] = $text;
                    }
                }
            }
            usort($candidates, static function ($a, $b) {
                return strlen($a) <=> strlen($b);
            });
            $headline = (string)($candidates[0] ?? '');
        }

        if ($lead === '') {
            $candidates = [];
            foreach ($stories as $story) {
                $styles = $story['paragraph_styles'];
                if (self::story_has_paragraph_style($styles, 'VS_Infoseite')) {
                    $text = self::strip_urls(self::normalize_text($story['text_all']));
                    if ($text !== '') {
                        $candidates[] = $text;
                    }
                }
            }
            usort($candidates, static function ($a, $b) {
                return strlen($a) <=> strlen($b);
            });
            $lead = (string)($candidates[0] ?? '');
        }

        if ($headline === '') {
            $warnings[] = 'Headline story not found (ParagraphStyle starts with ParagraphStyle/01_Headline).';
        }
        if ($lead === '') {
            $warnings[] = 'Lead story not found (ParagraphStyle starts with ParagraphStyle/02_Vorspann).';
        }
        if (count($teaser_stories) === 0 && count($info_stories) === 0) {
            $warnings[] = 'No teaser/info stories found.';
        }
        if ($layout === 'fotostrecke' && count($parallax_stories) === 0) {
            $warnings[] = 'No parallax stories found (Info_DZ + Head_Info + Info_LT).';
        }
        if ($layout === 'infoseiten' && count($info_stories) === 0) {
            $warnings[] = 'No info-teaser stories found (Info_DZ + Head_Info + Info_LT).';
        }
        $missing_main_items = false;
        if ($layout === 'infoseiten') {
            $missing_main_items = count($info_stories) === 0;
        } elseif ($layout === 'fotostrecke') {
            $missing_main_items = count($parallax_stories) === 0;
        } else {
            $missing_main_items = count($teaser_stories) === 0;
        }

        if ($headline === '' || $lead === '' || $missing_main_items) {
            $unique_styles = array_keys($all_para_styles);
            sort($unique_styles);
            $warnings[] = 'Diagnostics: spread_files=' . $spread_files_seen . ', story_files=' . $story_files_seen . ', parsed_stories=' . count($stories) . ', unique_paragraph_styles=' . count($unique_styles) . '.';
            $warnings[] = 'Diagnostics: paragraph_styles_sample=' . implode(' | ', array_slice($unique_styles, 0, 12)) . (count($unique_styles) > 12 ? ' | …' : '') . '.';
        }

        $teasers = [];
        foreach ($teaser_stories as $story) {
            $teasers[] = self::extract_teaser_from_story($story);
        }

        // Match images to teaser stories by nearest frame position.
        $teaser_positions = [];
        foreach ($teasers as $idx => $teaser) {
            $story_id = $teaser['story_id'];
            if (isset($text_frames[$story_id])) {
                $teaser_positions[$idx] = $text_frames[$story_id];
            } else {
                $teaser_positions[$idx] = ['x' => 0.0, 'y' => 0.0];
                $warnings[] = 'No TextFrame position for story_id=' . $story_id;
            }
        }

        $assignment = self::assign_images_to_teasers($teaser_positions, $images);
        foreach ($assignment as $teaser_idx => $image_idx) {
            $orig_basename = (string)($images[$image_idx]['basename'] ?? '');
            $small_jpg = self::to_small_jpg_basename($orig_basename);
            $attachment_id = $small_jpg !== '' ? self::find_attachment_id_by_filename($small_jpg) : 0;
            if ($attachment_id <= 0 && $orig_basename !== '') {
                $attachment_id = self::find_attachment_id_by_link_basename($orig_basename);
            }
            if ($attachment_id <= 0) {
                $warnings[] = 'Attachment not found for ' . $small_jpg;
            }
            $teasers[$teaser_idx]['image_basename'] = $small_jpg;
            $teasers[$teaser_idx]['attachment_id'] = (int)$attachment_id;
        }

        $parallax_items = [];
        $info_teasers = [];
        foreach ($info_stories as $story) {
            $info_teasers[] = self::extract_info_teaser_from_story($story);
        }

        // Default order for info teasers: by text frame position (reading order).
        if ($layout === 'infoseiten' && count($info_teasers) > 1) {
            usort($info_teasers, static function ($a, $b) use ($text_frames) {
                $sa = (string)($a['story_id'] ?? '');
                $sb = (string)($b['story_id'] ?? '');
                $pa = $text_frames[$sa] ?? ['x' => 0.0, 'y' => 0.0];
                $pb = $text_frames[$sb] ?? ['x' => 0.0, 'y' => 0.0];
                if ((float)$pa['y'] === (float)$pb['y']) {
                    return (float)$pa['x'] <=> (float)$pb['x'];
                }
                return (float)$pa['y'] <=> (float)$pb['y'];
            });
        }

        // Order override (admin input): associative array story_id => order number.
        if ($layout === 'infoseiten' && count($order_override) > 0) {
            $norm = [];
            foreach ($order_override as $k => $v) {
                $sid = sanitize_key((string)$k);
                $norm[$sid] = (int)$v;
            }
            usort($info_teasers, static function ($a, $b) use ($norm) {
                $sa = sanitize_key((string)($a['story_id'] ?? ''));
                $sb = sanitize_key((string)($b['story_id'] ?? ''));
                $oa = $norm[$sa] ?? 9999;
                $ob = $norm[$sb] ?? 9999;
                if ($oa === $ob) {
                    return 0;
                }
                return $oa <=> $ob;
            });
        }

        $usable_images = self::filter_images_for_import($images);
        $image_report = [];

        if ($layout === 'fotostrecke') {
            foreach ($parallax_stories as $story) {
                $parallax_items[] = self::extract_parallax_item_from_story($story);
            }

            foreach ($parallax_items as $i => $item) {
                $orig_basename = (string)($usable_images[$i]['basename'] ?? '');
                $small_jpg = self::to_small_jpg_basename($orig_basename);
                $attachment_id = $small_jpg !== '' ? self::find_attachment_id_by_filename($small_jpg) : 0;
                if ($attachment_id <= 0 && $orig_basename !== '') {
                    $attachment_id = self::find_attachment_id_by_link_basename($orig_basename);
                }
                if ($attachment_id <= 0 && $orig_basename !== '') {
                    $warnings[] = 'Attachment not found for ' . $small_jpg;
                }
                $parallax_items[$i]['image_basename'] = $small_jpg;
                $parallax_items[$i]['attachment_id'] = (int)$attachment_id;
                $image_report[] = [
                    'kind' => 'fotostrecke',
                    'label' => (string)($item['title'] ?? ''),
                    'expected_file' => $small_jpg,
                    'attachment_id' => (int)$attachment_id,
                ];
            }
        }

        // Assign images to info teasers by nearest text frame.
        if ($layout === 'infoseiten' && count($info_teasers) > 0) {
            $info_positions = [];
            foreach ($info_teasers as $idx => $it) {
                $story_id = (string)($it['story_id'] ?? '');
                if ($story_id !== '' && isset($text_frames[$story_id])) {
                    $info_positions[$idx] = $text_frames[$story_id];
                } else {
                    $info_positions[$idx] = ['x' => 0.0, 'y' => 0.0];
                }
            }
            $info_assignment = self::assign_images_to_teasers($info_positions, $usable_images);
            foreach ($info_assignment as $info_idx => $image_idx) {
                $orig_basename = (string)($usable_images[$image_idx]['basename'] ?? '');
                $small_jpg = self::to_small_jpg_basename($orig_basename);
                $attachment_id = $small_jpg !== '' ? self::find_attachment_id_by_filename($small_jpg) : 0;
                if ($attachment_id <= 0 && $orig_basename !== '') {
                    $attachment_id = self::find_attachment_id_by_link_basename($orig_basename);
                }
                if ($attachment_id <= 0 && $orig_basename !== '') {
                    $warnings[] = 'Attachment not found for ' . $small_jpg;
                }
                $info_teasers[$info_idx]['image_basename'] = $small_jpg;
                $info_teasers[$info_idx]['attachment_id'] = (int)$attachment_id;
                $image_report[] = [
                    'kind' => 'infoseiten',
                    'label' => (string)($info_teasers[$info_idx]['headline'] ?? ''),
                    'expected_file' => $small_jpg,
                    'attachment_id' => (int)$attachment_id,
                ];
            }
        }

        return [
            'headline' => $headline,
            'lead' => $lead,
            'teasers' => $teasers,
            'info_teasers' => $info_teasers,
            'parallax_items' => $parallax_items,
            'image_report' => $image_report,
            'order_report' => array_map(static function ($t, $i) {
                return [
                    'story_id' => (string)($t['story_id'] ?? ''),
                    'label' => (string)($t['headline'] ?? ''),
                    'order' => (int)$i,
                ];
            }, $info_teasers, array_keys($info_teasers)),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{text_frames: array<string, array{x:float,y:float}>, images: list<array{x:float,y:float,basename:string}>}
     */
    private static function parse_spread_positions(string $spread_xml): array
    {
        $text_frames = [];
        $images = [];

        $dom_ok = false;
        if (class_exists(\DOMDocument::class)) {
            $prev = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom_ok = (bool)$dom->loadXML($spread_xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            if ($dom_ok) {
                $xpath = new \DOMXPath($dom);
                $xpath->registerNamespace('idPkg', self::IDML_NS);

                /** @var \DOMElement $tf */
                foreach ($xpath->query('//*[local-name()="TextFrame"]') as $tf) {
                    $story_id = (string)$tf->getAttribute('ParentStory');
                    $it = (string)$tf->getAttribute('ItemTransform');
                    $pos = self::item_transform_translation($it);
                    if ($story_id !== '') {
                        $text_frames[$story_id] = $pos;
                    }
                }

                /** @var \DOMElement $img */
                foreach ($xpath->query('//*[local-name()="Image"]') as $img) {
                    $it = (string)$img->getAttribute('ItemTransform');
                    $pos = self::item_transform_translation($it);

                    $link = $xpath->query('.//*[local-name()="Link"]', $img)->item(0);
                    if (!$link instanceof \DOMElement) {
                        continue;
                    }
                    $uri = (string)$link->getAttribute('LinkResourceURI');
                    if ($uri === '') {
                        continue;
                    }

                    $basename = self::basename_from_link_uri($uri);
                    $images[] = [
                        'x' => $pos['x'],
                        'y' => $pos['y'],
                        'basename' => $basename,
                    ];
                }
            }
        }

        if (!$dom_ok) {
            // Fallback: regex parsing (more tolerant of invalid XML characters).
            if (preg_match_all('/<TextFrame\\b[^>]*ParentStory=\"(u[0-9a-f]+)\"[^>]*ItemTransform=\"([^\"]+)\"/i', $spread_xml, $m, PREG_SET_ORDER)) {
                foreach ($m as $row) {
                    $story_id = (string)$row[1];
                    $pos = self::item_transform_translation((string)$row[2]);
                    $text_frames[$story_id] = $pos;
                }
            }

            if (preg_match_all('/<Image\\b[^>]*ItemTransform=\"([^\"]+)\"[\\s\\S]*?<Link\\b[^>]*LinkResourceURI=\"([^\"]+)\"/i', $spread_xml, $m2, PREG_SET_ORDER)) {
                foreach ($m2 as $row) {
                    $pos = self::item_transform_translation((string)$row[1]);
                    $uri = (string)$row[2];
                    $basename = self::basename_from_link_uri($uri);
                    $images[] = [
                        'x' => $pos['x'],
                        'y' => $pos['y'],
                        'basename' => $basename,
                    ];
                }
            }
        }

        return ['text_frames' => $text_frames, 'images' => $images];
    }

    /**
     * @return array{id:string, text_all:string, paragraph_styles:list<string>, has_url_style:bool, paragraphs:list<array{style:string, text:string, url:string}>}
     */
    private static function parse_story(string $story_xml): array
    {
        $parsed = self::parse_story_dom($story_xml);
        if ($parsed['id'] !== '' || $parsed['paragraphs'] !== []) {
            return $parsed;
        }

        // Fallback: regex parsing (tolerates invalid XML characters).
        return self::parse_story_regex($story_xml);
    }

    /**
     * @return array{id:string, text_all:string, paragraph_styles:list<string>, has_url_style:bool, paragraphs:list<array{style:string, text:string, url:string}>}
     */
    private static function parse_story_dom(string $story_xml): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return [
                'id' => '',
                'text_all' => '',
                'paragraph_styles' => [],
                'has_url_style' => false,
                'paragraphs' => [],
            ];
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = (bool)$dom->loadXML($story_xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return [
                'id' => '',
                'text_all' => '',
                'paragraph_styles' => [],
                'has_url_style' => false,
                'paragraphs' => [],
            ];
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('idPkg', self::IDML_NS);

        // IDML has an outer `<idPkg:Story>` wrapper and an inner `<Story Self="...">` element.
        // We need the inner one to get the story ID.
        $story_node = $xpath->query('//*[local-name()="Story" and @Self]')->item(0);
        if (!$story_node instanceof \DOMElement) {
            return [
                'id' => '',
                'text_all' => '',
                'paragraph_styles' => [],
                'has_url_style' => false,
                'paragraphs' => [],
            ];
        }

        $story_id = (string)$story_node->getAttribute('Self');
        $paragraphs = [];
        $paragraph_styles = [];
        $has_url_style = false;

        /** @var \DOMElement $psr */
        foreach ($xpath->query('.//*[local-name()="ParagraphStyleRange"]', $story_node) as $psr) {
            $style = (string)$psr->getAttribute('AppliedParagraphStyle');
            if ($style !== '') {
                $paragraph_styles[] = $style;
            }

            $text_buf = '';
            $url_buf = '';
            /** @var \DOMElement $csr */
            foreach ($xpath->query('.//*[local-name()="CharacterStyleRange"]', $psr) as $csr) {
                $cstyle = (string)$csr->getAttribute('AppliedCharacterStyle');
                $is_url = self::is_url_character_style($cstyle);
                if ($is_url) {
                    $has_url_style = true;
                }

                foreach ($csr->childNodes as $child) {
                    if (!$child instanceof \DOMElement) {
                        continue;
                    }
                    $ln = $child->localName;
                    if ($ln === 'Content') {
                        $val = $child->nodeValue ?? '';
                        if ($is_url) {
                            $url_buf .= $val;
                        } else {
                            $text_buf .= $val;
                        }
                    } elseif ($ln === 'Br') {
                        if ($is_url) {
                            $url_buf .= "\n";
                        } else {
                            $text_buf .= "\n";
                        }
                    }
                }
            }

            $paragraphs[] = ['style' => $style, 'text' => $text_buf, 'url' => $url_buf];
        }

        $text_all = implode("\n", array_map(static function ($p) {
            return (string)$p['text'];
        }, $paragraphs));

        return [
            'id' => $story_id,
            'text_all' => $text_all,
            'paragraph_styles' => array_values(array_unique($paragraph_styles)),
            'has_url_style' => $has_url_style,
            'paragraphs' => $paragraphs,
        ];
    }

    /**
     * @return array{id:string, text_all:string, paragraph_styles:list<string>, has_url_style:bool, paragraphs:list<array{style:string, text:string, url:string}>}
     */
    private static function parse_story_regex(string $xml): array
    {
        $id = '';
        if (preg_match('/<Story\\b[^>]*\\bSelf=\"(u[0-9a-f]+)\"/i', $xml, $m)) {
            $id = (string)$m[1];
        }

        $paragraphs = [];
        $paragraph_styles = [];
        $has_url_style = false;

        if (preg_match_all('/<ParagraphStyleRange\\b([^>]*)>([\\s\\S]*?)<\\/ParagraphStyleRange>/i', $xml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $attrs = (string)$row[1];
                $inner = (string)$row[2];

                $style = '';
                if (preg_match('/AppliedParagraphStyle=\"([^\"]+)\"/i', $attrs, $sm)) {
                    $style = (string)$sm[1];
                    if ($style !== '') {
                        $paragraph_styles[] = $style;
                    }
                }

                $url_buf = '';
                if (preg_match_all('/<CharacterStyleRange\\b[^>]*AppliedCharacterStyle=\"([^\"]+)\"[^>]*>([\\s\\S]*?)<\\/CharacterStyleRange>/i', $inner, $um, PREG_SET_ORDER)) {
                    $has_url_style = true;
                    $url_lines = [];
                    foreach ($um as $urow) {
                        $cstyle = (string)$urow[1];
                        if (!self::is_url_character_style($cstyle)) {
                            continue;
                        }
                        $url_lines[] = self::extract_textish_from_xml_fragment((string)$urow[2]);
                    }
                    $url_buf = trim(implode("\n", array_filter($url_lines, static function ($l) {
                        return trim((string)$l) !== '';
                    })));
                    if ($url_buf === '') {
                        $has_url_style = false;
                    }
                }

                $text_inner = preg_replace_callback('/<CharacterStyleRange\\b[^>]*AppliedCharacterStyle=\"([^\"]+)\"[^>]*>[\\s\\S]*?<\\/CharacterStyleRange>/i', static function ($m) {
                    $cstyle = (string)($m[1] ?? '');
                    return DM_IDML_Importer_Plugin::is_url_character_style($cstyle) ? '' : (string)($m[0] ?? '');
                }, $inner) ?? $inner;
                $text_buf = self::extract_textish_from_xml_fragment($text_inner);

                $paragraphs[] = [
                    'style' => $style,
                    'text' => $text_buf,
                    'url' => $url_buf,
                ];
            }
        }

        $text_all = implode("\n", array_map(static function ($p) {
            return (string)$p['text'];
        }, $paragraphs));

        return [
            'id' => $id,
            'text_all' => $text_all,
            'paragraph_styles' => array_values(array_unique($paragraph_styles)),
            'has_url_style' => $has_url_style,
            'paragraphs' => $paragraphs,
        ];
    }

    private static function extract_textish_from_xml_fragment(string $frag): string
    {
        $frag = preg_replace('/<Br\\s*\\/?\\s*>/i', "\n", $frag) ?? $frag;
        $frag = preg_replace('/<Content>([\\s\\S]*?)<\\/Content>/i', '$1', $frag) ?? $frag;
        $frag = strip_tags($frag);
        return $frag;
    }

    /**
     * @param list<string> $styles
     */
    private static function story_has_paragraph_style(array $styles, string $needle): bool
    {
        foreach ($styles as $style) {
            $d = self::decode_idml_style_name($style);
            if (self::str_contains($style, $needle) || self::str_contains($d, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $styles
     */
    private static function story_has_any_paragraph_style_prefix(array $styles, string $prefix): bool
    {
        foreach ($styles as $style) {
            $d = self::decode_idml_style_name($style);
            if (self::str_starts_with($style, $prefix) || self::str_starts_with($d, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{id:string, paragraphs:list<array{style:string, text:string, url:string}>} $story
     * @return array{story_id:string, location:string, headline:string, intro:string, url:string, image_basename:string, attachment_id:int}
     */
    private static function extract_teaser_from_story(array $story): array
    {
        $location = '';
        $headline = '';
        $intro = '';
        $url = '';

        $paras = $story['paragraphs'];

        $ort_para = null;
        foreach ($paras as $p) {
            if (self::str_contains($p['style'], '06_Ort_KT_Meldungen')) {
                $ort_para = $p;
                break;
            }
        }

        if ($ort_para !== null) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\\R/u', (string)$ort_para['text']) ?: []), static function ($l) {
                return $l !== '';
            }));
            $location = self::normalize_text((string)($lines[0] ?? ''));
            if ($headline === '' && isset($lines[1])) {
                $headline = self::normalize_text((string)$lines[1]);
            }
        }

        if ($headline === '') {
            foreach ($paras as $p) {
                if (self::str_contains($p['style'], '06_Headline')) {
                    $headline = self::normalize_text((string)$p['text']);
                    break;
                }
            }
        }

        foreach ($paras as $p) {
            if (self::str_contains($p['style'], '03_LT_links')) {
                $intro = self::strip_urls(self::normalize_text((string)$p['text']));
                $url = self::normalize_url(self::normalize_text((string)$p['url']));
                break;
            }
        }

        return [
            'story_id' => (string)$story['id'],
            'location' => $location,
            'headline' => $headline,
            'intro' => $intro,
            'url' => $url,
            'image_basename' => '',
            'attachment_id' => 0,
        ];
    }

    /**
     * @param array{id:string, paragraphs:list<array{style:string, text:string, url:string}>} $story
     * @return array{story_id:string, location:string, title:string, body:string, image_basename:string, attachment_id:int}
     */
    private static function extract_parallax_item_from_story(array $story): array
    {
        $location = '';
        $title = '';
        $body = '';

        foreach ($story['paragraphs'] as $p) {
            if ($location === '' && self::str_contains($p['style'], 'Info_DZ')) {
                $location = self::strip_urls(self::normalize_text((string)$p['text']));
            } elseif ($title === '' && self::str_contains($p['style'], 'Head_Info')) {
                $title = self::strip_urls(self::normalize_text((string)$p['text']));
            } elseif ($body === '' && self::str_contains($p['style'], 'Info_LT')) {
                $body = self::strip_urls(self::normalize_text((string)$p['text']));
            }
        }

        return [
            'story_id' => (string)$story['id'],
            'location' => $location,
            'title' => $title,
            'body' => $body,
            'image_basename' => '',
            'attachment_id' => 0,
        ];
    }

    /**
     * @param array{id:string, paragraphs:list<array{style:string, text:string, url:string}>} $story
     * @return array{story_id:string, location:string, headline:string, intro:string, url:string, image_basename:string, attachment_id:int}
     */
    private static function extract_info_teaser_from_story(array $story): array
    {
        $location = '';
        $headline = '';
        $intro = '';
        $url = '';

        foreach ($story['paragraphs'] as $p) {
            if ($location === '' && self::str_contains($p['style'], 'Info_DZ')) {
                $location = self::strip_urls(self::normalize_text((string)$p['text']));
            } elseif ($headline === '' && self::str_contains($p['style'], 'Head_Info')) {
                $headline = self::strip_urls(self::normalize_text((string)$p['text']));
            } elseif ($intro === '' && self::str_contains($p['style'], 'Info_LT')) {
                $intro = self::strip_urls(self::normalize_text((string)$p['text']));
                $url = self::normalize_url(self::normalize_text((string)$p['url']));
            }
        }

        if ($url === '') {
            [$intro2, $url2] = self::extract_trailing_url($intro);
            $intro = $intro2;
            $url = $url2 !== '' ? $url2 : $url;
        }

        return [
            'story_id' => (string)$story['id'],
            'location' => $location,
            'headline' => $headline,
            'intro' => $intro,
            'url' => $url,
            'image_basename' => '',
            'attachment_id' => 0,
        ];
    }

    /**
     * @param list<array{x:float,y:float,basename:string}> $images
     * @return list<array{x:float,y:float,basename:string}>
     */
    private static function filter_images_for_import(array $images): array
    {
        $filtered = [];
        foreach ($images as $img) {
            $base = (string)($img['basename'] ?? '');
            $ext = strtolower((string)pathinfo(rawurldecode($base), PATHINFO_EXTENSION));
            if ($ext === 'eps' || $ext === 'ai' || $ext === 'pdf') {
                continue;
            }
            if (!in_array($ext, ['tif', 'tiff', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $filtered[] = $img;
        }

        usort($filtered, static function ($a, $b) {
            $ay = (float)($a['y'] ?? 0.0);
            $by = (float)($b['y'] ?? 0.0);
            if ($ay === $by) {
                $ax = (float)($a['x'] ?? 0.0);
                $bx = (float)($b['x'] ?? 0.0);
                return $ax <=> $bx;
            }
            return $ay <=> $by;
        });

        return $filtered;
    }

    /**
     * @param array<int, array{x:float,y:float}> $teaser_positions
     * @param list<array{x:float,y:float,basename:string}> $images
     * @return array<int,int> map teaser_idx -> image_idx
     */
    private static function assign_images_to_teasers(array $teaser_positions, array $images): array
    {
        $n = count($teaser_positions);
        $m = count($images);
        if ($n === 0 || $m === 0) {
            return [];
        }

        if ($m === $n && $n <= 8) {
            $indices = range(0, $m - 1);
            $best = null;
            $best_score = null;

            foreach (self::permute($indices) as $perm) {
                $score = 0.0;
                foreach (range(0, $n - 1) as $i) {
                    $tp = $teaser_positions[$i];
                    $im = $images[$perm[$i]];
                    $dx = $tp['x'] - $im['x'];
                    $dy = $tp['y'] - $im['y'];
                    $score += ($dx * $dx) + ($dy * $dy);
                }
                if ($best_score === null || $score < $best_score) {
                    $best_score = $score;
                    $best = $perm;
                }
            }

            $map = [];
            if ($best !== null) {
                foreach (range(0, $n - 1) as $i) {
                    $map[$i] = (int)$best[$i];
                }
            }
            return $map;
        }

        // Greedy fallback.
        $used = [];
        $map = [];
        foreach (range(0, $n - 1) as $i) {
            $best_j = null;
            $best_score = null;
            foreach (range(0, $m - 1) as $j) {
                if (isset($used[$j])) {
                    continue;
                }
                $tp = $teaser_positions[$i];
                $im = $images[$j];
                $dx = $tp['x'] - $im['x'];
                $dy = $tp['y'] - $im['y'];
                $score = ($dx * $dx) + ($dy * $dy);
                if ($best_score === null || $score < $best_score) {
                    $best_score = $score;
                    $best_j = $j;
                }
            }
            if ($best_j !== null) {
                $used[$best_j] = true;
                $map[$i] = (int)$best_j;
            }
        }
        return $map;
    }

    /**
     * @return \Generator<int, array<int,int>>
     */
    private static function permute(array $items): \Generator
    {
        $count = count($items);
        if ($count <= 1) {
            yield $items;
            return;
        }

        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            $rest = array_values($rest);
            foreach (self::permute($rest) as $perm) {
                yield array_merge([$item], $perm);
            }
        }
    }

    /**
     * @param array{headline:string,lead:string,teasers:list<array{location:string,headline:string,intro:string,url:string,attachment_id:int}>,warnings:list<string>} $parsed
     * @param array{header_image_mode:string,newtab_mode:string} $options
     */
    private static function build_blocks(array $parsed, array $options): string
    {
        $headline = (string)($parsed['headline'] ?? '');
        $lead = (string)($parsed['lead'] ?? '');
        $teasers = (array)($parsed['teasers'] ?? []);
        $info_teasers = (array)($parsed['info_teasers'] ?? []);
        $parallax_items = (array)($parsed['parallax_items'] ?? []);
        $layout = (string)($options['layout'] ?? 'infoseiten');

        // Stable order for MVP: keep as extracted from IDML.
        // (If you want to enforce reading order, add a sort based on spread positions.)

        $header_attachment_id = 0;
        $header_source = $teasers;
        if (count($header_source) === 0 && count($info_teasers) > 0) {
            $header_source = $info_teasers;
        }

        if ($options['header_image_mode'] !== 'none' && count($header_source) > 0) {
            if ($options['header_image_mode'] === 'first') {
                $header_attachment_id = (int)($header_source[0]['attachment_id'] ?? 0);
            } else {
                $last = $header_source[count($header_source) - 1];
                $header_attachment_id = (int)($last['attachment_id'] ?? 0);
            }
        }

        $blocks = [];
        $blocks[] = self::block_acf_ownheader(self::strip_urls($headline), $header_attachment_id);
        if ($lead !== '') {
            $blocks[] = self::block_core_intro_paragraph(self::strip_urls($lead));
        }
        if ($layout === 'fotostrecke') {
            if (count($parallax_items) > 0) {
                $blocks[] = self::block_acf_parallaxbackground($parallax_items);
            }
        } else {
            if (count($teasers) > 0) {
                $blocks[] = self::block_acf_contentteaser($teasers, $options['newtab_mode'] === 'all');
            } elseif (count($info_teasers) > 0) {
                $blocks[] = self::block_acf_contentteaser($info_teasers, $options['newtab_mode'] === 'all');
            }
        }

        return implode("\n\n", array_filter($blocks, static function ($b) {
            return $b !== '';
        })) . "\n";
    }

    private static function block_acf_ownheader(string $headline, int $attachment_id): string
    {
        // Field keys from themes/digitales-magazin/acf-json/group_62cbc7fe10b63.json
        $has_img = $attachment_id > 0;

        $data = [
            'bigHeaderHeadline' => '<p>' . esc_html($headline) . '&nbsp;</p>',
            '_bigHeaderHeadline' => 'field_5a6b3e4ede190',
            'bigHeaderContentType' => 'default',
            '_bigHeaderContentType' => 'field_628b5e17d09f9',
            'bigHeaderHeadlineStyle' => 'blurBox',
            '_bigHeaderHeadlineStyle' => 'field_628b5a49d90eb',
            'bigHeaderHideHeadline' => '0',
            '_bigHeaderHideHeadline' => 'field_65128b4b93682',
            'bigHeaderHeadlineBadge' => '',
            '_bigHeaderHeadlineBadge' => 'field_6551dc08eb41d',
            'bigHeaderType' => $has_img ? 'hasImg' : 'default',
            '_bigHeaderType' => 'field_6283a6cf34726',
            'bigHeaderSlider' => $has_img ? [(string)$attachment_id] : [],
            '_bigHeaderSlider' => 'field_5a6b27f097d00',
            'bigHeaderImgDescription' => '1',
            '_bigHeaderImgDescription' => 'field_6552164a6c46f',
            'bigHeaderImgDisableShadow' => '0',
            '_bigHeaderImgDisableShadow' => 'field_683410b0cf0ab',
        ];

        $attrs = [
            'name' => 'acf/ownheader',
            'data' => $data,
            'mode' => 'edit',
        ];

        return '<!-- wp:acf/ownheader ' . self::json_attrs($attrs) . ' /-->';
    }

    private static function block_core_intro_paragraph(string $lead): string
    {
        $lead = esc_html($lead);
        return implode("\n", [
            '<!-- wp:paragraph {"style":{"typography":{"fontSize":"26px"}}} -->',
            '<p style="font-size:26px">' . $lead . '</p>',
            '<!-- /wp:paragraph -->',
        ]);
    }

    /**
     * @param list<array{location:string,headline:string,intro:string,url:string,attachment_id:int}> $teasers
     */
    private static function block_acf_contentteaser(array $teasers, bool $newtab_all): string
    {
        // Field keys from themes/digitales-magazin/acf-json/group_62a0b8a5e384d.json
        // Block settings keys from themes/digitales-magazin/acf-json/group_654c9ff90677b.json
        $data = [];

        foreach (array_values($teasers) as $i => $t) {
            $orientation = ($i % 2 === 0) ? 'imageLeft' : 'imageRight';
            $location = trim((string)($t['location'] ?? ''));
            $sub = trim((string)($t['headline'] ?? ''));

            $headline = $location;
            if ($location !== '' && $sub !== '') {
                if (stripos($sub, $location) !== false) {
                    $headline = $sub;
                } else {
                    $headline = $location . ': ' . $sub;
                }
            } elseif ($sub !== '') {
                $headline = $sub;
            }

            $intro = trim((string)($t['intro'] ?? ''));
            $url = (string)($t['url'] ?? '');
            $attachment_id = (int)($t['attachment_id'] ?? 0);

            $data["teasers_{$i}_teaserType"] = 'custom';
            $data["_teasers_{$i}_teaserType"] = 'field_62a0b8a5f18ba';
            $data["teasers_{$i}_orientation"] = $orientation;
            $data["_teasers_{$i}_orientation"] = 'field_62a0b8a5f1952';
            $data["teasers_{$i}_customTeaser_headline"] = $headline;
            $data["_teasers_{$i}_customTeaser_headline"] = 'field_62a0b8a62704d';
            $data["teasers_{$i}_customTeaser_intro"] = $intro !== '' ? ($intro . "\r\n") : '';
            $data["_teasers_{$i}_customTeaser_intro"] = 'field_62a0b8a62734e';
            $data["teasers_{$i}_customTeaser_image"] = $attachment_id > 0 ? $attachment_id : '';
            $data["_teasers_{$i}_customTeaser_image"] = 'field_62a0bcedeee6c';
            $data["teasers_{$i}_customTeaser_url"] = $url;
            $data["_teasers_{$i}_customTeaser_url"] = 'field_62a0bdcd53746';
            $data["teasers_{$i}_customTeaser_urlTarget"] = $newtab_all ? '1' : '0';
            $data["_teasers_{$i}_customTeaser_urlTarget"] = 'field_65d715697bd59';
            $data["teasers_{$i}_customTeaser"] = '';
            $data["_teasers_{$i}_customTeaser"] = 'field_62a0b8a5f2e97';
            $data["teasers_{$i}_headingType"] = 'h2';
            $data["_teasers_{$i}_headingType"] = 'field_65d714f87bd57';
            $data["teasers_{$i}_backgroundSize"] = 'cover';
            $data["_teasers_{$i}_backgroundSize"] = 'field_65d715bd7bd5a';
            $data["teasers_{$i}_maxImageHeight_height"] = '';
            $data["_teasers_{$i}_maxImageHeight_height"] = 'field_6744862d295c5';
            $data["teasers_{$i}_maxImageHeight_unit"] = 'px';
            $data["_teasers_{$i}_maxImageHeight_unit"] = 'field_6744864c295c6';
            $data["teasers_{$i}_maxImageHeight"] = '';
            $data["_teasers_{$i}_maxImageHeight"] = 'field_67448601295c4';
            $data["teasers_{$i}_buttonLabel"] = '';
            $data["_teasers_{$i}_buttonLabel"] = 'field_62a0b8a5f3ee6';
        }

        $data['teasers'] = count($teasers);
        $data['_teasers'] = 'field_62a0b8b3fdaf2';
        $data['blockBgColor'] = '';
        $data['_blockBgColor'] = 'field_654c9ff9998ae';

        $attrs = [
            'name' => 'acf/contentteaser',
            'data' => $data,
            'mode' => 'edit',
        ];

        return '<!-- wp:acf/contentteaser ' . self::json_attrs($attrs) . ' /-->';
    }

    /**
     * @param list<array{location:string,title:string,body:string,attachment_id:int}> $items
     */
    private static function block_acf_parallaxbackground(array $items): string
    {
        // Field keys from themes/digitales-magazin/acf-json/group_639b05da52fed.json
        $data = [];

        foreach (array_values($items) as $i => $it) {
            $align = ($i % 2 === 0) ? 'left' : 'right';
            $location = trim((string)($it['location'] ?? ''));
            $title = trim((string)($it['title'] ?? ''));
            $body = trim((string)($it['body'] ?? ''));
            $attachment_id = (int)($it['attachment_id'] ?? 0);

            $content_html = '';
            if ($location !== '') {
                $content_html .= '<em>' . esc_html($location) . '</em>' . "\r\n";
            }
            if ($title !== '') {
                $content_html .= '<h3>' . esc_html($title) . '</h3>' . "\r\n";
            }
            if ($body !== '') {
                $content_html .= esc_html($body) . "\r\n";
            }

            $data["contents_{$i}_content"] = $content_html;
            $data["_contents_{$i}_content"] = 'field_639b06d490c97';
            $data["contents_{$i}_backgroundImg"] = $attachment_id > 0 ? $attachment_id : '';
            $data["_contents_{$i}_backgroundImg"] = 'field_639b06ec90c98';
            $data["contents_{$i}_align"] = $align;
            $data["_contents_{$i}_align"] = 'field_640073524d66d';
            $data["contents_{$i}_bg"] = '1';
            $data["_contents_{$i}_bg"] = 'field_640073714d66e';
            $data["contents_{$i}_bgOpacity"] = '';
            $data["_contents_{$i}_bgOpacity"] = 'field_65782f5da518d';
        }

        $data['contents'] = count($items);
        $data['_contents'] = 'field_639b069f8129b';

        $attrs = [
            'name' => 'acf/parallaxbackground',
            'data' => $data,
            'mode' => 'edit',
        ];

        return '<!-- wp:acf/parallaxbackground ' . self::json_attrs($attrs) . ' /-->';
    }

    private static function json_attrs(array $attrs): string
    {
        return wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize_text(string $text): string
    {
        $text = str_replace(["\u{2028}", "\u{2029}"], ' ', $text); // InDesign forced line breaks.
        $text = str_replace(["\r"], "\n", $text);
        $text = preg_replace('/[\\t\\n ]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function normalize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return 'https://' . $url;
    }

    /**
     * Removes standalone trailing domains/URLs from a text blob.
     */
    private static function strip_urls(string $text): string
    {
        [$text2,] = self::extract_trailing_url($text);
        return $text2;
    }

    /**
     * @return array{0:string,1:string} [text_without_url, url]
     */
    private static function extract_trailing_url(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['', ''];
        }

        // Grab last URL-ish token (domain or full URL).
        if (preg_match('/(https?:\\/\\/[^\\s]+|(?:www\\.)?[a-z0-9.-]+\\.[a-z]{2,}(?:\\/[\\w\\-\\.\\/%#\\?=&+]*)?)[\\s\\p{P}]*$/iu', $text, $m)) {
            $raw = (string)$m[1];
            $url = self::normalize_url(rtrim($raw, ".,;:!?)”'\""));
            $text = trim(preg_replace('/' . preg_quote($m[0], '/') . '$/u', '', $text) ?? $text);
            return [$text, $url];
        }

        return [$text, ''];
    }

    /**
     * @return array{x:float,y:float}
     */
    private static function item_transform_translation(string $item_transform): array
    {
        $parts = preg_split('/\\s+/', trim($item_transform));
        if (!is_array($parts) || count($parts) < 6) {
            return ['x' => 0.0, 'y' => 0.0];
        }
        $x = (float)$parts[count($parts) - 2];
        $y = (float)$parts[count($parts) - 1];
        return ['x' => $x, 'y' => $y];
    }

    private static function basename_from_link_uri(string $uri): string
    {
        $decoded = rawurldecode($uri);
        $decoded = str_replace('file:', '', $decoded);
        $decoded = str_replace('file://', '', $decoded);
        $decoded = trim($decoded);
        $basename = basename($decoded);
        return $basename !== '' ? $basename : basename($uri);
    }

    private static function to_small_jpg_basename(string $orig_basename): string
    {
        $orig_basename = trim($orig_basename);
        if ($orig_basename === '') {
            return '';
        }
        $decoded = rawurldecode($orig_basename);
        $decoded = basename($decoded);
        $name = pathinfo($decoded, PATHINFO_FILENAME);
        if ($name === '') {
            return '';
        }
        $raw = $name . '_small.jpg';
        $san = function_exists('sanitize_file_name') ? sanitize_file_name($raw) : $raw;
        return $san !== '' ? $san : $raw;
    }

    private static function find_attachment_id_by_filename(string $filename): int
    {
        global $wpdb;

        $filename = trim($filename);
        if ($filename === '') {
            return 0;
        }

        $like = '%' . $wpdb->esc_like($filename);
        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
            $like
        );
        $found = (int)$wpdb->get_var($sql);
        if ($found > 0) {
            return $found;
        }

        $slug = sanitize_title(pathinfo($filename, PATHINFO_FILENAME));
        if ($slug === '') {
            return 0;
        }

        $sql2 = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_name=%s ORDER BY ID DESC LIMIT 1",
            $slug
        );
        return (int)$wpdb->get_var($sql2);
    }

    private static function find_attachment_id_by_link_basename(string $link_basename): int
    {
        global $wpdb;

        $link_basename = trim($link_basename);
        if ($link_basename === '') {
            return 0;
        }

        $decoded = rawurldecode($link_basename);
        $decoded = basename($decoded);
        $stem = pathinfo($decoded, PATHINFO_FILENAME);
        if ($stem === '') {
            return 0;
        }

        $expected = $stem . '_small.jpg';
        $expected_san = function_exists('sanitize_file_name') ? sanitize_file_name($expected) : $expected;
        $needle = pathinfo($expected_san, PATHINFO_FILENAME);
        if ($needle === '') {
            $needle = pathinfo($expected, PATHINFO_FILENAME);
        }
        if ($needle === '') {
            return 0;
        }

        // Pull a small candidate set and score best match by normalized basename similarity.
        $like = '%' . $wpdb->esc_like($needle) . '%';
        $sql = $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 50",
            $like
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || count($rows) === 0) {
            return 0;
        }

        $target_norm = self::normalize_filename_token($expected_san);
        $best_id = 0;
        $best_score = -1.0;

        foreach ($rows as $row) {
            $post_id = isset($row['post_id']) ? (int)$row['post_id'] : 0;
            $meta_value = (string)($row['meta_value'] ?? '');
            if ($post_id <= 0 || $meta_value === '') {
                continue;
            }
            $base = basename($meta_value);
            $base_norm = self::normalize_filename_token($base);
            if ($base_norm === '') {
                continue;
            }

            $score = 0.0;
            similar_text($target_norm, $base_norm, $percent);
            $score = (float)$percent;

            if ($score > $best_score) {
                $best_score = $score;
                $best_id = $post_id;
            }
        }

        return $best_id;
    }

    private static function normalize_filename_token(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\\.[a-z0-9]{2,5}$/i', '', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/i', '', $name) ?? $name;
        return $name;
    }
}

add_action('init', [DM_IDML_Importer_Plugin::class, 'register_wp_cli']);
add_action('init', [DM_IDML_Importer_Plugin::class, 'register_admin']);
add_action('init', [DM_IDML_Importer_Plugin::class, 'register_update_notifications']);

// Backwards compatibility: some installs may still reference the old class name.
if (!class_exists('DM_IDML_Importer', false)) {
    class_alias('DM_IDML_Importer_Plugin', 'DM_IDML_Importer');
}
