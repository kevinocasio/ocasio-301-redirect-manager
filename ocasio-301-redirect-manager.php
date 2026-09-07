<?php
/**
 * Plugin Name: Ocasio 301 Redirect Manager
 * Plugin URI:  https://kevinocasio.com/wordpress-plugins/ocasio-301-redirect-manager/
 * Description: A lightweight, high-performance tool to manage 301 permanent redirects and fix broken links.
 * Version:     1.0.0
 * Author:      Kevin Ocasio
 * Author URI:  https://kevinocasio.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ocasio-301-redirect-manager
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Helper: Smart Brand URL Resolver
 */
function ocasio_301_brand_url($path = '/tools/') {
	$http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
	if (!empty($http_host) && strpos($http_host, '.local') !== false) {
		return home_url($path);
	}
	return 'https://kevinocasio.com' . $path;
}

/**
 * Helper: Get redirects with automatic migration from old option key if present
 */
function ocasio_301_get_redirects() {
	$redirects = get_option('ocasio_301_redirects', null);
	if ($redirects === null) {
		$old_redirects = get_option('ko_301_redirects', null);
		if ($old_redirects !== null && is_array($old_redirects)) {
			update_option('ocasio_301_redirects', $old_redirects);
			return $old_redirects;
		}
		return array();
	}
	return is_array($redirects) ? $redirects : array();
}

// -------------------------------------------------------------------------
// 1. CORE LOGIC: REDIRECTION & AJAX SEARCH
// -------------------------------------------------------------------------

/**
 * Perform Redirection
 * Intercepts requests on template_redirect before full page rendering.
 */
function ocasio_301_perform_redirect() {
	if (is_admin()) {
		return;
	}

	$redirects = ocasio_301_get_redirects();
	if (empty($redirects) || !is_array($redirects)) {
		return;
	}

	$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
	if (empty($request_uri)) {
		return;
	}

	// 1. Decode URL path
	$path = urldecode((string) wp_parse_url($request_uri, PHP_URL_PATH));

	// 2. Check Exact Match
	if (array_key_exists($path, $redirects)) {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External destination URLs are an intended feature of 301 redirects.
		wp_redirect($redirects[$path], 301);
		exit;
	}

	// 3. Check Trailing / Untrailed Slash Variations
	$path_untrailed = untrailingslashit($path);
	$path_trailed   = trailingslashit($path);

	if (array_key_exists($path_untrailed, $redirects)) {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External destination URLs are an intended feature of 301 redirects.
		wp_redirect($redirects[$path_untrailed], 301);
		exit;
	}

	if (array_key_exists($path_trailed, $redirects)) {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External destination URLs are an intended feature of 301 redirects.
		wp_redirect($redirects[$path_trailed], 301);
		exit;
	}
}
add_action('template_redirect', 'ocasio_301_perform_redirect', 1);

/**
 * AJAX Handler for Destination Autocomplete Search
 */
function ocasio_301_search_content_ajax() {
	check_ajax_referer('ocasio_301_search_nonce', 'nonce');

	if (!current_user_can('manage_options')) {
		wp_send_json_error('Unauthorized', 403);
	}

	$term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';

	$query = new WP_Query(array(
		'post_type'      => array('post', 'page'),
		'post_status'    => 'publish',
		'posts_per_page' => 15,
		's'              => $term,
	));

	$results = array();
	if ($query->have_posts()) {
		while ($query->have_posts()) {
			$query->the_post();
			$results[] = array(
				'label' => get_the_title(),
				'value' => get_permalink(),
			);
		}
	}
	wp_reset_postdata();

	wp_send_json($results);
}
add_action('wp_ajax_ocasio_301_search_content', 'ocasio_301_search_content_ajax');

/**
 * AJAX Handler for Deleting Redirects
 */
function ocasio_301_delete_redirect_ajax() {
	check_ajax_referer('ocasio_301_search_nonce', 'nonce');

	if (!current_user_can('manage_options')) {
		wp_send_json_error('Unauthorized', 403);
	}

	$path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
	if (empty($path)) {
		wp_send_json_error('Missing path');
	}

	$redirects = ocasio_301_get_redirects();
	if (is_array($redirects) && isset($redirects[$path])) {
		unset($redirects[$path]);
		update_option('ocasio_301_redirects', $redirects);
		wp_send_json_success(array(
			'total' => count($redirects),
		));
	}

	wp_send_json_error('Path not found');
}
add_action('wp_ajax_ocasio_301_delete_redirect', 'ocasio_301_delete_redirect_ajax');

/**
 * Handle Add, Edit, and Delete Form Submissions
 */
function ocasio_301_handle_submit() {
	if (!current_user_can('manage_options')) {
		return;
	}

	$redirects = ocasio_301_get_redirects();

	// DELETE Action via Standard URL
	if (isset($_GET['delete_path']) && isset($_GET['ocasio_301_nonce'])) {
		$nonce = sanitize_text_field(wp_unslash($_GET['ocasio_301_nonce']));
		if (!wp_verify_nonce($nonce, 'ocasio_301_action')) {
			return;
		}
		$raw_delete_path = sanitize_text_field(wp_unslash($_GET['delete_path']));
		$path_to_delete  = base64_decode($raw_delete_path);
		if (isset($redirects[$path_to_delete])) {
			unset($redirects[$path_to_delete]);
			update_option('ocasio_301_redirects', $redirects);
			wp_safe_redirect(admin_url('tools.php?page=ocasio-301-redirect-manager&deleted=true'));
			exit;
		}
	}

	// ADD / UPDATE Action
	if (isset($_POST['ocasio_301_request_path']) && isset($_POST['ocasio_301_destination_url'])) {
		if (!isset($_POST['ocasio_301_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ocasio_301_nonce'])), 'ocasio_301_action')) {
			return;
		}

		$path = trim(sanitize_text_field(wp_unslash($_POST['ocasio_301_request_path'])));
		$dest = trim(esc_url_raw(wp_unslash($_POST['ocasio_301_destination_url'])));

		if (empty($path) || empty($dest)) {
			add_settings_error('ocasio_301_messages', 'ocasio_301_empty', 'Both the Request Path and Destination URL are required.', 'error');
			return;
		}

		// Ensure request path starts with /
		if (substr($path, 0, 1) !== '/') {
			$path = '/' . $path;
		}

		// Handle Edit: Remove old path if key changed
		if (!empty($_POST['ocasio_301_original_path'])) {
			$raw_orig_path = sanitize_text_field(wp_unslash($_POST['ocasio_301_original_path']));
			$original_path = base64_decode($raw_orig_path);
			if ($original_path !== $path && isset($redirects[$original_path])) {
				unset($redirects[$original_path]);
			}
		}

		$redirects[$path] = $dest;
		update_option('ocasio_301_redirects', $redirects);

		wp_safe_redirect(admin_url('admin.php?page=ocasio-301-redirect-manager&updated=true'));
		exit;
	}
}
add_action('admin_init', 'ocasio_301_handle_submit');

// -------------------------------------------------------------------------
// 2. ADMIN MENU & ASSETS
// -------------------------------------------------------------------------

/**
 * Register Admin Menu under Ocasio Plugins -> 301 Redirects (Position 65)
 */
function ocasio_301_register_menu() {
	if (empty($GLOBALS['admin_page_hooks']['ocasio-plugins-main'])) {
		$icon_url = plugins_url('assets/favicon.svg', __FILE__);

		add_menu_page(
			'Ocasio Plugins',
			'Ocasio Plugins',
			'manage_options',
			'ocasio-plugins-main',
			'ocasio_plugins_suite_dashboard_html',
			$icon_url,
			65
		);

		add_submenu_page(
			'ocasio-plugins-main',
			'Ocasio Plugins',
			'Dashboard',
			'manage_options',
			'ocasio-plugins-main',
			'ocasio_plugins_suite_dashboard_html'
		);
	}

	add_submenu_page(
		'ocasio-plugins-main',
		'Ocasio 301 Redirect Manager',
		'301 Redirects',
		'manage_options',
		'ocasio-301-redirect-manager',
		'ocasio_301_render_page'
	);
}
add_action('admin_menu', 'ocasio_301_register_menu');

/**
 * Settings Link on Plugins Screen
 */
function ocasio_301_action_links($links) {
	$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=ocasio-301-redirect-manager')) . '">Settings</a>';
	array_unshift($links, $settings_link);
	return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ocasio_301_action_links');

/**
 * Enqueue Admin Assets
 */
function ocasio_301_admin_assets($hook) {
	wp_add_inline_style('common', '#adminmenu .toplevel_page_ocasio-plugins-main .wp-menu-image img { width:20px!important; height:20px!important; padding:5px 0 0 0!important; opacity:1!important; }');

	if (strpos($hook, 'ocasio-301-redirect-manager') !== false || $hook === 'toplevel_page_ocasio-plugins-main') {
		wp_enqueue_style('ocasio-301-admin-css', plugins_url('assets/admin.css', __FILE__), array(), '1.0.1');
		wp_enqueue_script('jquery-ui-autocomplete');
		wp_enqueue_script('ocasio-301-admin-js', plugins_url('assets/admin.js', __FILE__), array('jquery', 'jquery-ui-autocomplete'), '1.0.0', true);
		wp_localize_script('ocasio-301-admin-js', 'ocasio_301_vars', array(
			'ajaxurl'     => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('ocasio_301_search_nonce'),
			'suite_nonce' => wp_create_nonce('ocasio_suite_toggle_nonce'),
		));
	}
}
add_action('admin_enqueue_scripts', 'ocasio_301_admin_assets');

// -------------------------------------------------------------------------
// 3. DEDICATED SETTINGS PAGE
// -------------------------------------------------------------------------

/**
 * Render Settings Screen
 */
function ocasio_301_render_page() {
	$redirects  = ocasio_301_get_redirects();
	$errors     = get_settings_errors('ocasio_301_messages');
	$author_url = ocasio_301_brand_url('/');
	$hub_url    = ocasio_301_brand_url('/wordpress-plugins/');

	// Form values (default empty, populated client-side via admin.js on inline edit)
	$is_edit            = false;
	$edit_path_original = '';
	$val_path           = '';
	$val_dest           = '';
	?>
	<div class="wrap ko-plugin-wrap">
		<div class="ko-plugin-card" style="max-width:780px;">
			<!-- Header -->
			<div class="ko-plugin-header">
				<h1 class="ko-plugin-header-title">
					<span class="ko-logo-ocasio">OCASIO</span>
					<span class="ko-title-text">301 REDIRECT MANAGER</span>
				</h1>
			</div>

			<!-- Body Stage -->
			<div class="ko-plugin-body">
				<p class="ko-plugin-intro">Create fast 301 permanent redirects to fix broken links and send old URLs to new destinations.</p>

				<?php if (!empty($errors)): ?>
					<?php foreach ($errors as $error): ?>
						<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:6px; margin-bottom:18px; font-size:13px; font-weight:500;">
							<?php echo esc_html($error['message']); ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<!-- Add / Edit Form -->
				<form method="post" action="" id="ocasio-redirect-form">
					<?php wp_nonce_field('ocasio_301_action', 'ocasio_301_nonce'); ?>

					<?php if ($is_edit): ?>
						<input type="hidden" name="ocasio_301_original_path" value="<?php echo esc_attr(base64_encode($edit_path_original)); ?>">
						<div class="ko-edit-banner" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:10px 14px; border-radius:6px; margin-bottom:18px; font-size:13px; font-weight:500;">
							<strong>Editing:</strong> <code style="background:none; color:#1e40af; font-weight:700;"><?php echo esc_html($edit_path_original); ?></code>
							<a href="<?php echo esc_url(admin_url('tools.php?page=ocasio-301-redirect-manager')); ?>" style="margin-left:12px; color:#e11d48; font-weight:600; text-decoration:none;">Cancel</a>
						</div>
					<?php endif; ?>

					<div class="ko-setting-box">
						<!-- Row 1: Request Path -->
						<div class="ko-setting-row" style="flex-direction:column; align-items:flex-start; gap:8px;">
							<div class="ko-setting-info">
								<strong>Request Path (Old URL)</strong>
								<p>The relative URL you want to redirect away from (e.g. <code>/old-page</code>).</p>
							</div>
							<input type="text" name="ocasio_301_request_path" value="<?php echo esc_attr($val_path); ?>" placeholder="/old-page" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:13.5px;">
						</div>

						<!-- Row 2: Destination URL -->
						<div class="ko-setting-row" style="flex-direction:column; align-items:flex-start; gap:8px;">
							<div class="ko-setting-info">
								<strong>Destination URL (New URL)</strong>
								<p>The new target URL (type page or post title for instant autocomplete suggestions).</p>
							</div>
							<input type="text" name="ocasio_301_destination_url" value="<?php echo esc_attr($val_dest); ?>" placeholder="https://yoursite.com/new-page" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:13.5px;">
						</div>
					</div>

					<div class="ko-submit-wrap">
						<?php
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI notice flag.
						$is_saved  = (isset($_GET['updated']) && sanitize_text_field(wp_unslash($_GET['updated'])) === 'true');
						$btn_text  = $is_saved ? 'Redirect Saved!' : 'Add Redirect';
						$btn_class = 'ko-btn-submit' . ($is_saved ? ' ko-btn-saved' : '');
						?>
						<button type="submit" name="submit" id="ocasio-save-btn" class="<?php echo esc_attr($btn_class); ?>">
							<?php echo esc_html($btn_text); ?>
						</button>
					</div>
				</form>

				<!-- Divider -->
				<div style="border-top:1px solid #e2e8f0; margin: 28px 0 20px 0;"></div>

				<!-- Active Redirects Section -->
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
					<h3 style="margin:0; font-size:14.5px; font-weight:700; color:#0f172a;">Active Redirects</h3>
					<span style="font-size:11.5px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">
						Total: <strong style="color:#0f172a;" id="ko-total-count"><?php echo count($redirects); ?></strong>
					</span>
				</div>

				<div class="ko-table-container">
					<?php if (empty($redirects)): ?>
						<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:22px; text-align:center; color:#94a3b8; font-size:13px;">
							No active redirects yet. Add your first redirect rule above.
						</div>
					<?php else: ?>
						<div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
							<table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px; table-layout:fixed;">
								<thead>
									<tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
										<th style="padding:10px 14px; font-weight:700; color:#0f172a; width:48%;">Request Path (Click to Test)</th>
										<th style="padding:10px 14px; font-weight:700; color:#0f172a; width:42%;">Destination</th>
										<th style="padding:10px 14px; font-weight:700; color:#0f172a; text-align:right; width:80px;">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($redirects as $path => $dest): ?>
										<tr data-path="<?php echo esc_attr($path); ?>" data-dest="<?php echo esc_attr($dest); ?>" style="border-bottom:1px solid #f1f5f9;">
											<td style="padding:10px 14px; overflow:hidden;">
												<a href="<?php echo esc_url(home_url($path)); ?>" target="_blank" rel="noopener noreferrer" title="Test Redirect in New Tab" style="display:inline-flex; align-items:center; gap:5px; text-decoration:none; color:#0f172a; word-break:break-all; outline:none; box-shadow:none;" onmouseover="this.style.color='#e11d48'" onmouseout="this.style.color='#0f172a'">
													<code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600; color:inherit; font-size:12px;"><?php echo esc_html($path); ?></code>
													<span class="dashicons dashicons-external" style="font-size:12px; width:12px; height:12px; color:#94a3b8; flex-shrink:0;"></span>
												</a>
											</td>
											<td style="padding:10px 14px; overflow:hidden;">
												<a href="<?php echo esc_url($dest); ?>" target="_blank" rel="noopener noreferrer" style="color:#64748b; text-decoration:none; font-size:12.5px; word-break:break-all; display:inline-flex; align-items:center; gap:4px;" onmouseover="this.style.color='#e11d48'" onmouseout="this.style.color='#64748b'">
													<?php echo esc_html($dest); ?>
													<span class="dashicons dashicons-external" style="font-size:12px; width:12px; height:12px; flex-shrink:0;"></span>
												</a>
											</td>
											<td style="padding:10px 14px; text-align:right; white-space:nowrap; width:80px;">
												<div class="ko-action-cell-wrap" style="display:inline-flex; align-items:center; justify-content:flex-end; width:62px;">
													<!-- Default State (2 buttons) -->
													<div class="ko-action-state-default" style="display:inline-flex; align-items:center; gap:6px;">
														<a href="#" class="ko-icon-btn ko-btn-edit" title="Edit Redirect" style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:#f8fafc; border:1px solid #e2e8f0; color:#64748b; text-decoration:none; transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9'; this.style.color='#0f172a'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.background='#f8fafc'; this.style.color='#64748b'; this.style.borderColor='#e2e8f0';">
															<span class="dashicons dashicons-edit" style="font-size:15px; width:15px; height:15px; line-height:1;"></span>
														</a>
														<a href="#" class="ko-icon-btn ko-btn-trash" title="Delete Redirect" style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:#f8fafc; border:1px solid #e2e8f0; color:#94a3b8; text-decoration:none; transition:all 0.15s ease;" onmouseover="this.style.background='#fef2f2'; this.style.color='#e11d48'; this.style.borderColor='#fecaca';" onmouseout="this.style.background='#f8fafc'; this.style.color='#94a3b8'; this.style.borderColor='#e2e8f0';">
															<span class="dashicons dashicons-trash" style="font-size:15px; width:15px; height:15px; line-height:1;"></span>
														</a>
													</div>

													<!-- Confirm State (2 buttons) -->
													<div class="ko-action-state-confirm" style="display:none; align-items:center; gap:6px;">
														<a href="#" class="ko-icon-btn ko-btn-confirm-no" title="Cancel" style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:#f1f5f9; border:1px solid #cbd5e1; color:#64748b; text-decoration:none; transition:all 0.15s ease;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#0f172a';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b';">
															<span class="dashicons dashicons-no-alt" style="font-size:15px; width:15px; height:15px; line-height:1;"></span>
														</a>
														<a href="#" class="ko-icon-btn ko-btn-confirm-yes" title="Confirm Delete" style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:#e11d48; border:1px solid #e11d48; color:#ffffff; text-decoration:none; transition:all 0.15s ease;" onmouseover="this.style.background='#be123c';" onmouseout="this.style.background='#e11d48';">
															<span class="dashicons dashicons-yes" style="font-size:16px; width:16px; height:16px; line-height:1;"></span>
														</a>
													</div>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Card Footer -->
			<div class="ko-plugin-footer">
				<p><a href="<?php echo esc_url($author_url); ?>" target="_blank" rel="noopener noreferrer">Kevin Ocasio</a> built this and other <a href="<?php echo esc_url($hub_url); ?>" target="_blank" rel="noopener noreferrer">WordPress plugins</a>.</p>
			</div>
		</div>
	</div>
	<?php
}

// -------------------------------------------------------------------------
// 4. MASTER OCASIO PLUGINS SUITE DASHBOARD CALLBACK (17-PLUGIN GRID)
// -------------------------------------------------------------------------

if (!function_exists('ocasio_plugins_suite_dashboard_html')) {
	function ocasio_plugins_suite_dashboard_html() {
		$all_plugins = array(
			'ocasio-admin-bar-hider' => array(
				'title'         => 'Admin Bar Hider',
				'desc'          => 'Hides the front-end WordPress admin bar for all users with a single toggle.',
				'file'          => 'ocasio-admin-bar-hider/ocasio-admin-bar-hider.php',
				'fallback_file' => 'ko-admin-bar-hider/ko-admin-bar-hider.php',
				'opt_toggle'    => 'ocasio_abh_enabled',
				'fallback_opt'  => 'ko_abh_enabled',
				'has_options'   => false,
			),
			'ocasio-admin-username-changer' => array(
				'title'         => 'Admin Username Changer',
				'desc'          => 'Safely changes the primary administrator username directly without touching phpMyAdmin.',
				'file'          => 'ocasio-admin-username-changer/ocasio-admin-username-changer.php',
				'fallback_file' => 'ko-admin-username-changer/ko-admin-username-changer.php',
				'fallback_slug' => 'ko-admin-username-changer',
				'opt_toggle'    => null,
				'has_options'   => true,
			),
			'ocasio-auto-copyright-year' => array(
				'title'         => 'Auto Copyright Year',
				'desc'          => 'Displays the current year, symbol, or translated text via simple shortcodes.',
				'file'          => 'ocasio-auto-copyright-year/ocasio-auto-copyright-year.php',
				'fallback_file' => 'ko-auto-copyright-year/ko-auto-copyright-year.php',
				'fallback_slug' => 'ko-auto-copyright-year',
				'opt_toggle'    => null,
				'has_options'   => true,
			),
			'ocasio-clean-image-filenames' => array(
				'title'         => 'Clean Image Filenames',
				'desc'          => 'Sanitizes uploaded media filenames into clean, lowercase, URL-friendly slugs.',
				'file'          => 'ocasio-clean-image-filenames/ocasio-clean-image-filenames.php',
				'fallback_file' => 'ko-clean-image-filenames/ko-clean-image-filenames.php',
				'opt_toggle'    => 'ocasio_cif_enabled',
				'fallback_opt'  => 'ko_cif_enabled',
				'has_options'   => false,
			),
			'ocasio-comment-link-remover' => array(
				'title'         => 'Comment Link Remover',
				'desc'          => 'Strips hyperlinked website URLs from author comments to eliminate backlink spam.',
				'file'          => 'ocasio-comment-link-remover/ocasio-comment-link-remover.php',
				'fallback_file' => 'ko-comment-link-remover/ko-comment-link-remover.php',
				'opt_toggle'    => 'ocasio_clr_enabled',
				'fallback_opt'  => 'ko_clr_enabled',
				'has_options'   => false,
			),
			'ocasio-disable-comments-globally' => array(
				'title'         => 'Disable Comments Globally',
				'desc'          => 'Closes comments and trackbacks across the entire site, posts, and media.',
				'file'          => 'ocasio-disable-comments-globally/ocasio-disable-comments-globally.php',
				'fallback_file' => 'ko-disable-comments-globally/ko-disable-comments-globally.php',
				'opt_toggle'    => 'ocasio_dcg_enabled',
				'fallback_opt'  => 'ko_dcg_enabled',
				'has_options'   => false,
			),
			'ocasio-disable-emojis' => array(
				'title'         => 'Disable Emojis',
				'desc'          => 'Removes WordPress core emoji scripts, styles, and DNS prefetch requests to boost page speed.',
				'file'          => 'ocasio-disable-emojis/ocasio-disable-emojis.php',
				'fallback_file' => 'ko-disable-emojis/ko-disable-emojis.php',
				'opt_toggle'    => 'ocasio_de_enabled',
				'fallback_opt'  => 'ko_de_enabled',
				'has_options'   => false,
			),
			'ocasio-disable-gutenberg' => array(
				'title'         => 'Disable Gutenberg',
				'desc'          => 'Restores the Classic Editor and removes block library CSS for a cleaner authoring workflow.',
				'file'          => 'ocasio-disable-gutenberg/ocasio-disable-gutenberg.php',
				'fallback_file' => 'ko-disable-gutenberg/ko-disable-gutenberg.php',
				'opt_toggle'    => 'ocasio_dg_enabled',
				'fallback_opt'  => 'ko_dg_enabled',
				'has_options'   => false,
			),
			'ocasio-disable-xml-rpc' => array(
				'title'         => 'Disable XML-RPC',
				'desc'          => 'Blocks XML-RPC API access to protect your site against brute-force attacks.',
				'file'          => 'ocasio-disable-xml-rpc/ocasio-disable-xml-rpc.php',
				'fallback_file' => 'ko-disable-xml-rpc/ko-disable-xml-rpc.php',
				'opt_toggle'    => 'ocasio_dxml_enabled',
				'fallback_opt'  => 'ko_dxml_enabled',
				'has_options'   => false,
			),
			'ocasio-duplicate-post-button' => array(
				'title'         => 'Duplicate Post Button',
				'desc'          => 'Adds a one-click Clone action to duplicate any post or page into a new draft.',
				'file'          => 'ocasio-duplicate-post-button/ocasio-duplicate-post-button.php',
				'fallback_file' => 'ko-duplicate-post-button/ko-duplicate-post-button.php',
				'fallback_slug' => 'ko-duplicate-post-button',
				'opt_toggle'    => null,
				'has_options'   => true,
			),
			'ocasio-estimated-reading-time' => array(
				'title'         => 'Estimated Reading Time',
				'desc'          => 'Calculates and displays article read time above post content automatically.',
				'file'          => 'ocasio-estimated-reading-time/ocasio-estimated-reading-time.php',
				'fallback_file' => 'ko-estimated-reading-time/ko-estimated-reading-time.php',
				'opt_toggle'    => 'ocasio_ert_enabled',
				'fallback_opt'  => 'ko_ert_enabled',
				'has_options'   => false,
			),
			'ocasio-external-links-new-tab' => array(
				'title'         => 'External Links New Tab',
				'desc'          => 'Forces external links to open in a new tab with target="_blank" and rel="noopener".',
				'file'          => 'ocasio-external-links-new-tab/ocasio-external-links-new-tab.php',
				'fallback_file' => 'ko-external-links-new-tab/ko-external-links-new-tab.php',
				'opt_toggle'    => 'ocasio_elnt_enabled',
				'fallback_opt'  => 'ko_elnt_enabled',
				'has_options'   => false,
			),
			'ocasio-hide-version' => array(
				'title'         => 'Hide Version',
				'desc'          => 'Removes WordPress version generator tags and script query strings for security.',
				'file'          => 'ocasio-hide-version/ocasio-hide-version.php',
				'fallback_file' => 'ko-hide-version/ko-hide-version.php',
				'opt_toggle'    => 'ocasio_hv_enabled',
				'fallback_opt'  => 'ko_hv_enabled',
				'has_options'   => false,
			),
			'ocasio-limit-login-attempts' => array(
				'title'         => 'Limit Login Attempts',
				'desc'          => 'Throttles repeated failed login attempts by IP address to block brute-force attacks.',
				'file'          => 'ocasio-limit-login-attempts/ocasio-limit-login-attempts.php',
				'fallback_file' => 'ko-limit-login-attempts/ko-limit-login-attempts.php',
				'fallback_slug' => 'ko-limit-login-attempts',
				'opt_toggle'    => null,
				'has_options'   => true,
			),
			'ocasio-show-current-template' => array(
				'title'         => 'Show Current Template',
				'desc'          => 'Displays active template hierarchy filename in the admin bar for developers.',
				'file'          => 'ocasio-show-current-template/ocasio-show-current-template.php',
				'fallback_file' => 'ko-show-current-template/ko-show-current-template.php',
				'opt_toggle'    => 'ocasio_sct_enabled',
				'fallback_opt'  => 'ko_sct_enabled',
				'has_options'   => false,
			),
			'ocasio-301-redirect-manager' => array(
				'title'         => '301 Redirect Manager',
				'desc'          => 'Manages 301 permanent redirects and fixes broken links cleanly inside WordPress.',
				'file'          => 'ocasio-301-redirect-manager/ocasio-301-redirect-manager.php',
				'fallback_file' => 'ko-simple-301-redirects/ko-simple-301-redirects.php',
				'fallback_slug' => 'ko-simple-301-redirects',
				'opt_toggle'    => null,
				'has_options'   => true,
			),
			'ocasio-simple-maintenance-mode' => array(
				'title'         => 'Simple Maintenance Mode',
				'desc'          => 'Displays a clean splash page to visitors while admins work on the site.',
				'file'          => 'ocasio-simple-maintenance-mode/ocasio-simple-maintenance-mode.php',
				'fallback_file' => 'ko-simple-maintenance-mode/ko-simple-maintenance-mode.php',
				'fallback_slug' => 'ko-simple-maintenance-mode',
				'opt_toggle'    => null,
				'has_options'   => true,
			),
		);

		if (!function_exists('is_plugin_active')) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		$active_count      = 0;

		foreach ($all_plugins as $slug => $data) {
			$active_file = $data['file'];
			if (!is_plugin_active($active_file) && !empty($data['fallback_file']) && is_plugin_active($data['fallback_file'])) {
				$active_file = $data['fallback_file'];
			}
			if (is_plugin_active($active_file)) {
				$active_count++;
			}
		}

		$author_url = 'https://kevinocasio.com/';
		$hub_url    = 'https://kevinocasio.com/wordpress-plugins/';
		?>
		<div class="wrap ko-dash-wrap">
			<div class="ko-dash-hero">
				<div class="ko-dash-hero-left">
					<h1>
						<a href="<?php echo esc_url($hub_url); ?>" target="_blank" rel="noopener noreferrer" class="ko-dash-logo">
							<span class="ko-logo-ocasio">OCASIO</span>
							<span class="ko-logo-suite">PLUGINS SUITE</span>
						</a>
					</h1>
				</div>
				<div class="ko-dash-hero-right">
					<span class="ko-dash-count-pill"><?php echo esc_html($active_count); ?> of 17 Active</span>
				</div>
			</div>

			<div class="ko-dash-grid">
				<?php
				foreach ($all_plugins as $slug => $data):
					$active_file = $data['file'];
					if (!is_plugin_active($active_file) && !empty($data['fallback_file']) && is_plugin_active($data['fallback_file'])) {
						$active_file = $data['fallback_file'];
					}
					$is_installed = isset($installed_plugins[$active_file]) || isset($installed_plugins[$data['file']]) || (!empty($data['fallback_file']) && isset($installed_plugins[$data['fallback_file']]));
					$is_active    = is_plugin_active($active_file);
					$is_fallback  = ($active_file !== $data['file'] && !empty($data['fallback_file']));
					$page_slug    = ($is_fallback && !empty($data['fallback_slug'])) ? $data['fallback_slug'] : $slug;
					$settings_url = admin_url('admin.php?page=' . $page_slug);
					$activate_url = wp_nonce_url(admin_url('plugins.php?action=activate&plugin=' . urlencode($data['file'])), 'activate-plugin_' . $data['file']);
					?>
					<div class="ko-dash-card">
						<div class="ko-dash-card-header">
							<h3 class="ko-dash-card-title"><?php echo esc_html($data['title']); ?></h3>
							<?php if ($is_active): ?>
								<?php if (!empty($data['opt_toggle'])):
									$opt_key      = (!empty($data['fallback_opt']) && get_option($data['opt_toggle'], null) === null) ? $data['fallback_opt'] : $data['opt_toggle'];
									$toggle_state = (int) get_option($opt_key, 1);
									$b_class      = ($toggle_state === 1) ? 'badge-active' : 'badge-paused';
									$b_label      = ($toggle_state === 1) ? 'Active' : 'Paused';
									?>
									<span class="ko-dash-badge <?php echo esc_attr($b_class); ?>" id="badge-<?php echo esc_attr($slug); ?>"><?php echo esc_html($b_label); ?></span>
								<?php else: ?>
									<span class="ko-dash-badge badge-active">Active</span>
								<?php endif; ?>
							<?php elseif ($is_installed): ?>
								<span class="ko-dash-badge badge-inactive">Inactive</span>
							<?php else: ?>
								<span class="ko-dash-badge badge-available">Available</span>
							<?php endif; ?>
						</div>

						<p class="ko-dash-card-desc"><?php echo esc_html($data['desc']); ?></p>

						<div class="ko-dash-card-footer">
							<?php if ($is_active): ?>
								<?php if ($data['has_options']): ?>
									<a href="<?php echo esc_url($settings_url); ?>" class="ko-dash-btn-primary">Manage Settings</a>
								<?php elseif (!empty($data['opt_toggle'])):
									$opt_key    = (!empty($data['fallback_opt']) && get_option($data['opt_toggle'], null) === null) ? $data['fallback_opt'] : $data['opt_toggle'];
									$toggle_val = (int) get_option($opt_key, 1);
									?>
									<div class="ko-dash-card-toggle-row">
										<span class="ko-dash-toggle-label">Active on Site</span>
										<div class="ko-dash-toggle-action">
											<span class="ko-dash-saved-pill" id="saved-<?php echo esc_attr($slug); ?>" style="display:none;">Saved</span>
											<label class="ko-switch">
												<input type="checkbox"
													class="ko-ajax-toggle"
													data-slug="<?php echo esc_attr($slug); ?>"
													data-option="<?php echo esc_attr($opt_key); ?>"
													value="1" <?php checked($toggle_val, 1); ?>>
												<span class="ko-slider"></span>
											</label>
										</div>
									</div>
								<?php else: ?>
									<span class="ko-dash-badge badge-active">Active</span>
								<?php endif; ?>
							<?php elseif ($is_installed): ?>
								<a href="<?php echo esc_url($activate_url); ?>" class="ko-dash-btn-activate">Activate</a>
							<?php else: ?>
								<a href="<?php echo esc_url($hub_url); ?>" target="_blank" rel="noopener noreferrer" class="ko-dash-btn-outline">Learn More</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="ko-dash-global-footer">
				<p>Built with pride by <a href="<?php echo esc_url($author_url); ?>" target="_blank" rel="noopener noreferrer">Kevin Ocasio</a>. Explore all <a href="<?php echo esc_url($hub_url); ?>" target="_blank" rel="noopener noreferrer">17 lightweight WordPress tools</a>.</p>
			</div>
		</div>
		<?php
	}
}

// -------------------------------------------------------------------------
// 5. MASTER AJAX HANDLER FOR DASHBOARD GRID IN-CARD TOGGLES
// -------------------------------------------------------------------------

if (!function_exists('ocasio_suite_save_toggle_ajax_callback')) {
	function ocasio_suite_save_toggle_ajax_callback() {
		check_ajax_referer('ocasio_suite_toggle_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error('Unauthorized', 403);
		}

		$option_name  = isset($_POST['option_name']) ? sanitize_key($_POST['option_name']) : '';
		$option_value = isset($_POST['option_value']) ? absint($_POST['option_value']) : 0;

		$allowed_options = array(
			'ko_abh_enabled',
			'ko_cif_enabled',
			'ko_clr_enabled',
			'ko_dcg_enabled',
			'ko_de_enabled',
			'ko_dg_enabled',
			'ko_dxml_enabled',
			'ko_elnt_enabled',
			'ko_ert_enabled',
			'ko_hv_enabled',
			'ko_sct_enabled',
			'ocasio_abh_enabled',
			'ocasio_cif_enabled',
			'ocasio_clr_enabled',
			'ocasio_dcg_enabled',
			'ocasio_de_enabled',
			'ocasio_dg_enabled',
			'ocasio_dxml_enabled',
			'ocasio_elnt_enabled',
			'ocasio_ert_enabled',
			'ocasio_hv_enabled',
			'ocasio_sct_enabled',
		);

		if (in_array($option_name, $allowed_options, true)) {
			update_option($option_name, $option_value);
			wp_send_json_success();
		}

		wp_send_json_error('Invalid option key');
	}
	add_action('wp_ajax_ocasio_suite_save_toggle', 'ocasio_suite_save_toggle_ajax_callback');
}
