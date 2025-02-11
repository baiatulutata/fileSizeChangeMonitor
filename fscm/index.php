<?php
/**
 * Plugin Name: File Size Change Monitor
 * Description: Monitors file size changes on your website and notifies the admin via email.
 * Version: 1.0.0
 * Author: Ionut Baldazar
 */

// Activation hook
register_activation_hook(__FILE__, 'fscm_activate');

function fscm_activate() {
    // Schedule the cron job (default every 24 hours)
    if (!wp_next_scheduled('fscm_scan_files')) {
        wp_schedule_event(time(), 'daily', 'fscm_scan_files'); // You can customize the interval
    }

    // Create the options if they don't exist
    add_option('fscm_email', get_bloginfo('admin_email')); // Default to admin email
    add_option('fscm_interval', 'daily'); // Default to daily
    add_option('fscm_exceptions', ''); // Default no exceptions
    add_option('fscm_last_scan_data', ''); // Store file size data
}


// Deactivation hook (optional: clear cron)
register_deactivation_hook(__FILE__, 'fscm_deactivate');

function fscm_deactivate() {
    wp_clear_scheduled_hook('fscm_scan_files');
}



// Cron function
add_action('fscm_scan_files', 'fscm_scan');

function fscm_scan() {
    $email = get_option('fscm_email');
    $exceptions = explode(',', str_replace(' ', '', get_option('fscm_exceptions'))); // Handle exceptions
    $last_scan_data = get_option('fscm_last_scan_data');

    $current_scan_data = fscm_get_file_sizes(ABSPATH, $exceptions);

    $changes = fscm_compare_file_sizes($last_scan_data, $current_scan_data);

    if (!empty($changes)) {
        fscm_send_email($email, $changes);
    }

    update_option('fscm_last_scan_data', $current_scan_data);
}


function fscm_get_file_sizes($dir, $exceptions = array()) {
    $files = array();
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $path = $dir . '/' . $item;

        if (in_array(str_replace(ABSPATH, '', $path), $exceptions)) continue; // Skip exceptions

        if (is_dir($path)) {
            $files = array_merge($files, fscm_get_file_sizes($path, $exceptions)); // Recursive
        } else if (is_file($path)) {
            $files[str_replace(ABSPATH, '', $path)] = filesize($path);
        }
    }
    return $files;
}

function fscm_compare_file_sizes($last_data, $current_data) {
    $changes = array();

    if (empty($last_data)) {
        return $changes; // First run, no comparison
    }

    foreach ($current_data as $file => $size) {
        if (!isset($last_data[$file]) || $last_data[$file] != $size) {
            $changes[$file] = array('old' => isset($last_data[$file]) ? $last_data[$file] : 'N/A', 'new' => $size);
        }
    }

    return $changes;
}

function fscm_send_email($email, $changes) {
    $subject = 'File Size Changes Detected';
    $message = "The following file sizes have changed on your website:\n\n";

    foreach ($changes as $file => $sizes) {
        $message .= $file . ": Old Size: " . $sizes['old'] . " bytes, New Size: " . $sizes['new'] . " bytes\n";
    }

    wp_mail($email, $subject, $message);
}

// Admin settings page
add_action('admin_menu', 'fscm_add_settings_page');

function fscm_add_settings_page() {
    add_options_page('File Size Monitor Settings', 'File Size Monitor', 'manage_options', 'fscm-settings', 'fscm_settings_page');
}

function fscm_settings_page() {
    ?>
    <div class="wrap">
        <h1>File Size Monitor Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('fscm-settings-group'); ?>
            <?php do_settings_sections('fscm-settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fscm_email">Email Address</label></th>
                    <td><input type="email" id="fscm_email" name="fscm_email" value="<?php echo esc_attr(get_option('fscm_email')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="fscm_interval">Scan Interval</label></th>
                    <td>
                        <select name="fscm_interval" id="fscm_interval">
                            <option value="daily" <?php selected(get_option('fscm_interval'), 'daily'); ?>>Daily</option>
                            <option value="hourly" <?php selected(get_option('fscm_interval'), 'hourly'); ?>>Hourly</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fscm_exceptions">Folder Exceptions (comma-separated)</label></th>
                    <td><input type="text" id="fscm_exceptions" name="fscm_exceptions" value="<?php echo esc_attr(get_option('fscm_exceptions')); ?>" class="regular-text" /> <span class="description">e.g., wp-content/uploads/cache, another/folder</span></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'fscm_register_settings');

function fscm_register_settings() {
    register_setting('fscm-settings-group', 'fscm_email', 'sanitize_email');
    register_setting('fscm-settings-group', 'fscm_interval');
    register_setting('fscm-settings-group', 'fscm_exceptions', 'sanitize_text_field');
}