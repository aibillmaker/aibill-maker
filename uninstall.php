<?php
/**
 * Uninstall AiBill Maker.
 *
 * Keeps invoice data by default to avoid accidental business record loss.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Settings are removed on uninstall. Invoice records are intentionally kept.
delete_option('aibima_settings');
