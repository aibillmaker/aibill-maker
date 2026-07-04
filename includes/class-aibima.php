<?php
if (!defined('ABSPATH')) {
    exit;
}

class AIBIMA_Plugin {
    const OPTION = 'aibima_settings';
    const CPT = 'aibima_invoice';
    const NONCE_ACTION = 'aibima_admin_nonce';
    const VIEW_NONCE_ACTION = 'aibima_view_invoice';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_aibima_generate_invoice', array($this, 'ajax_generate_invoice'));
        add_action('admin_post_aibima_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_post_aibima_save_invoice', array($this, 'handle_save_invoice'));
        add_action('admin_post_aibima_delete_invoice', array($this, 'handle_delete_invoice'));
        add_action('admin_post_aibima_print_invoice', array($this, 'handle_print_invoice'));
        add_action('admin_post_aibima_download_pdf', array($this, 'handle_download_pdf'));
        add_filter('plugin_action_links_' . plugin_basename(AIBIMA_FILE), array($this, 'plugin_action_links'));
    }

    public static function activate() {
        if (!get_option(self::OPTION)) {
            add_option(self::OPTION, self::defaults());
        }
        $plugin = self::instance();
        $plugin->register_post_type();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function defaults() {
        return array(
            'default_currency' => 'INR',
            'default_tax_rate' => '18',
            'invoice_prefix' => 'INV',
            'business_name' => get_bloginfo('name'),
            'business_email' => get_bloginfo('admin_email'),
            'business_phone' => '',
            'business_gstin' => '',
            'business_address' => '',
            'payment_instructions' => '',
            'default_terms' => __('Payment due as per due date.', 'aibill-maker'),
            'default_notes' => __('Thank you for your business.', 'aibill-maker'),
        );
    }

    public static function settings() {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    public function register_post_type() {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name' => __('AiBill Invoices', 'aibill-maker'),
                'singular_name' => __('AiBill Invoice', 'aibill-maker'),
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array('title', 'author'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
        ));
    }

    public function admin_menu() {
        add_menu_page(
            __('AiBill Maker', 'aibill-maker'),
            __('AiBill Maker', 'aibill-maker'),
            'manage_options',
            'aibima-maker',
            array($this, 'invoices_page'),
            'dashicons-media-spreadsheet',
            56
        );

        add_submenu_page(
            'aibima-maker',
            __('Invoices', 'aibill-maker'),
            __('Invoices', 'aibill-maker'),
            'manage_options',
            'aibima-maker',
            array($this, 'invoices_page')
        );

        add_submenu_page(
            'aibima-maker',
            __('Create Invoice', 'aibill-maker'),
            __('Create Invoice', 'aibill-maker'),
            'manage_options',
            'aibima-create',
            array($this, 'create_invoice_page')
        );

        add_submenu_page(
            'aibima-maker',
            __('Settings', 'aibill-maker'),
            __('Settings', 'aibill-maker'),
            'manage_options',
            'aibima-settings',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('aibima_settings_group', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => self::defaults(),
        ));
    }

    public function sanitize_settings($input) {
        $input = is_array($input) ? wp_unslash($input) : array();
        $old = self::settings();
        $out = self::defaults();

        $out['default_currency'] = strtoupper(sanitize_text_field($input['default_currency'] ?? $old['default_currency']));
        if (!preg_match('/^[A-Z]{3}$/', $out['default_currency'])) {
            $out['default_currency'] = 'INR';
        }
        $out['default_tax_rate'] = (string) max(0, min(100, (float) ($input['default_tax_rate'] ?? $old['default_tax_rate'])));
        $out['invoice_prefix'] = sanitize_key($input['invoice_prefix'] ?? $old['invoice_prefix']);
        if ($out['invoice_prefix'] === '') {
            $out['invoice_prefix'] = 'INV';
        }
        $out['business_name'] = sanitize_text_field($input['business_name'] ?? '');
        $out['business_email'] = sanitize_email($input['business_email'] ?? '');
        $out['business_phone'] = sanitize_text_field($input['business_phone'] ?? '');
        $out['business_gstin'] = strtoupper(sanitize_text_field($input['business_gstin'] ?? ''));
        $out['business_address'] = sanitize_textarea_field($input['business_address'] ?? '');
        $out['payment_instructions'] = wp_kses_post($input['payment_instructions'] ?? '');
        $out['default_terms'] = wp_kses_post($input['default_terms'] ?? '');
        $out['default_notes'] = wp_kses_post($input['default_notes'] ?? '');

        return $out;
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (false === strpos((string) $hook_suffix, 'aibima')) {
            return;
        }

        wp_enqueue_style('aibima-admin', AIBIMA_URL . 'assets/css/admin.css', array(), AIBIMA_ASSET_VERSION);
        wp_enqueue_script('aibima-admin', AIBIMA_URL . 'assets/js/admin.js', array(), AIBIMA_ASSET_VERSION, true);
        wp_localize_script('aibima-admin', 'AibimaAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'samplePrompt' => $this->sample_prompt(),
            'strings' => array(
                'generating' => __('Generating invoice…', 'aibill-maker'),
                'generated' => __('Invoice generated and saved.', 'aibill-maker'),
                'error' => __('Something went wrong. Please try again.', 'aibill-maker'),
                'confirmDelete' => __('Delete this invoice? This cannot be undone.', 'aibill-maker'),
                'copy' => __('Copy invoice text', 'aibill-maker'),
                'copied' => __('Copied', 'aibill-maker'),
                'viewInvoice' => __('View invoice', 'aibill-maker'),
                'editInvoice' => __('Edit invoice', 'aibill-maker'),
                'printInvoice' => __('Print invoice', 'aibill-maker'),
                'downloadPdf' => __('Download PDF', 'aibill-maker'),
            ),
        ));
    }

    public function invoices_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action_raw = filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW);
        $invoice_id_raw = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT);
        $action = sanitize_key(null !== $action_raw ? $action_raw : 'list');
        $invoice_id = absint(false !== $invoice_id_raw && null !== $invoice_id_raw ? $invoice_id_raw : 0);

        if ('view' === $action && $invoice_id) {
            $this->view_invoice_page($invoice_id);
            return;
        }

        if ('edit' === $action && $invoice_id) {
            $this->edit_invoice_page($invoice_id);
            return;
        }

        $this->list_invoices_page(false);
    }

    public function create_invoice_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $this->list_invoices_page(true);
    }

    private function list_invoices_page($open_prompt = false) {
        $settings = self::settings();
        $paged_raw = filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT);
        $paged = max(1, absint(false !== $paged_raw && null !== $paged_raw ? $paged_raw : 1));
        $per_page = 20;
        $query = new WP_Query(array(
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
        ));
        $ai_supported = AIBIMA_AI::is_text_generation_supported();
        ?>
        <div class="wrap aibill-admin-wrap" data-aibill-open-prompt="<?php echo $open_prompt ? '1' : '0'; ?>">
            <div class="aibill-admin-head">
                <div>
                    <h1><?php esc_html_e('AiBill Maker', 'aibill-maker'); ?></h1>
                    <p><?php esc_html_e('Create invoices inside WordPress admin from a simple prompt. No public page or shortcode is required.', 'aibill-maker'); ?></p>
                </div>
                <div class="aibill-head-actions">
                    <button type="button" class="button button-primary button-hero" data-aibill-open-modal><?php esc_html_e('Create Invoice', 'aibill-maker'); ?></button>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=aibima-settings')); ?>"><?php esc_html_e('Settings', 'aibill-maker'); ?></a>
                </div>
            </div>

            <?php $this->admin_notice_from_query(); ?>

            <?php if (!$ai_supported): ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('WordPress AI Client text generation is not configured yet. Structured prompts can still work locally. For natural-language prompts, configure an AI provider in Settings > Connectors.', 'aibill-maker'); ?></p></div>
            <?php endif; ?>

            <div class="aibill-card aibill-list-card">
                <div class="aibill-card-head">
                    <h2><?php esc_html_e('Invoice List', 'aibill-maker'); ?></h2>
                    <p><?php esc_html_e('View, edit, print, or download invoices as PDF.', 'aibill-maker'); ?></p>
                </div>
                <table class="widefat striped aibill-invoice-list">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Invoice', 'aibill-maker'); ?></th>
                        <th><?php esc_html_e('Customer', 'aibill-maker'); ?></th>
                        <th><?php esc_html_e('Total', 'aibill-maker'); ?></th>
                        <th><?php esc_html_e('Source', 'aibill-maker'); ?></th>
                        <th><?php esc_html_e('Date', 'aibill-maker'); ?></th>
                        <th><?php esc_html_e('Actions', 'aibill-maker'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($query->have_posts()): ?>
                        <?php while ($query->have_posts()): $query->the_post(); $post_id = get_the_ID(); $invoice = $this->get_invoice($post_id); ?>
                            <tr>
                                <td><strong><?php echo esc_html($invoice['invoice_number'] ?? get_the_title()); ?></strong></td>
                                <td><?php echo esc_html($invoice['customer']['name'] ?? get_post_meta($post_id, '_aibima_customer_name', true)); ?></td>
                                <td><?php echo esc_html(AIBIMA_Parser::money($invoice['grand_total'] ?? 0, $invoice['currency'] ?? 'INR')); ?></td>
                                <td><?php echo esc_html(ucfirst((string) ($invoice['source'] ?? 'local'))); ?></td>
                                <td><?php echo esc_html(get_the_date()); ?></td>
                                <td class="aibill-row-actions">
                                    <a href="<?php echo esc_url($this->invoice_url($post_id, 'view')); ?>"><?php esc_html_e('View', 'aibill-maker'); ?></a>
                                    <span>|</span>
                                    <a href="<?php echo esc_url($this->invoice_url($post_id, 'edit')); ?>"><?php esc_html_e('Edit', 'aibill-maker'); ?></a>
                                    <span>|</span>
                                    <a target="_blank" rel="noopener" href="<?php echo esc_url($this->print_url($post_id)); ?>"><?php esc_html_e('Print', 'aibill-maker'); ?></a>
                                    <span>|</span>
                                    <a href="<?php echo esc_url($this->download_url($post_id)); ?>"><?php esc_html_e('Download PDF', 'aibill-maker'); ?></a>
                                    <span>|</span>
                                    <a class="submitdelete" data-aibill-confirm-delete href="<?php echo esc_url($this->delete_url($post_id)); ?>"><?php esc_html_e('Delete', 'aibill-maker'); ?></a>
                                </td>
                            </tr>
                        <?php endwhile; wp_reset_postdata(); ?>
                    <?php else: ?>
                        <tr><td colspan="6"><p><?php esc_html_e('No invoices yet. Click Create Invoice to generate your first invoice.', 'aibill-maker'); ?></p></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php $this->pagination($query, $paged); ?>
            </div>

            <?php $this->prompt_modal(); ?>
        </div>
        <?php
    }

    private function prompt_modal() {
        ?>
        <div class="aibill-modal" data-aibill-modal hidden>
            <div class="aibill-modal-backdrop" data-aibill-close-modal></div>
            <div class="aibill-modal-panel" role="dialog" aria-modal="true" aria-labelledby="aibill-create-title">
                <button type="button" class="aibill-modal-close" data-aibill-close-modal aria-label="<?php esc_attr_e('Close', 'aibill-maker'); ?>">×</button>
                <h2 id="aibill-create-title"><?php esc_html_e('Create invoice from prompt', 'aibill-maker'); ?></h2>
                <p><?php esc_html_e('Write customer, items, quantity, rate, GST/tax, and due date. AiBill will save the invoice automatically.', 'aibill-maker'); ?></p>
                <form data-aibill-prompt-form>
                    <label for="aibill-maker-prompt" class="screen-reader-text"><?php esc_html_e('Invoice prompt', 'aibill-maker'); ?></label>
                    <textarea id="aibill-maker-prompt" name="prompt" rows="9" required placeholder="<?php echo esc_attr($this->sample_prompt()); ?>"></textarea>
                    <div class="aibill-helper-grid">
                        <span><strong><?php esc_html_e('Example:', 'aibill-maker'); ?></strong> <?php esc_html_e('5 x Pencil @ 50', 'aibill-maker'); ?></span>
                        <span><strong><?php esc_html_e('GST:', 'aibill-maker'); ?></strong> <?php esc_html_e('write 18% GST exclusive or inclusive', 'aibill-maker'); ?></span>
                        <span><strong><?php esc_html_e('Due:', 'aibill-maker'); ?></strong> <?php esc_html_e('write due in 7 days', 'aibill-maker'); ?></span>
                    </div>
                    <div class="aibill-form-actions">
                        <button type="submit" class="button button-primary button-hero"><?php esc_html_e('Generate & Save Invoice', 'aibill-maker'); ?></button>
                        <button type="button" class="button" data-aibill-use-sample><?php esc_html_e('Use Sample', 'aibill-maker'); ?></button>
                    </div>
                    <div class="aibill-message" data-aibill-message hidden></div>
                </form>
                <div class="aibill-generated-result" data-aibill-result hidden></div>
            </div>
        </div>
        <?php
    }

    private function view_invoice_page($post_id) {
        $invoice = $this->get_invoice($post_id);
        if (!$invoice) {
            $this->not_found_page();
            return;
        }
        ?>
        <div class="wrap aibill-admin-wrap">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=aibima-maker')); ?>">← <?php esc_html_e('Back to invoices', 'aibill-maker'); ?></a></p>
            <div class="aibill-view-actions no-print">
                <a class="button button-primary" href="<?php echo esc_url($this->invoice_url($post_id, 'edit')); ?>"><?php esc_html_e('Edit Invoice', 'aibill-maker'); ?></a>
                <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($this->print_url($post_id)); ?>"><?php esc_html_e('Print Invoice', 'aibill-maker'); ?></a>
                <a class="button" href="<?php echo esc_url($this->download_url($post_id)); ?>"><?php esc_html_e('Download PDF', 'aibill-maker'); ?></a>
            </div>
            <?php echo AIBIMA_Renderer::invoice($invoice, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
    }

    private function edit_invoice_page($post_id) {
        $invoice = $this->get_invoice($post_id);
        if (!$invoice) {
            $this->not_found_page();
            return;
        }
        $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : array();
        ?>
        <div class="wrap aibill-admin-wrap">
            <p><a href="<?php echo esc_url($this->invoice_url($post_id, 'view')); ?>">← <?php esc_html_e('Back to invoice', 'aibill-maker'); ?></a></p>
            <h1><?php esc_html_e('Edit Invoice', 'aibill-maker'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aibill-edit-form">
                <input type="hidden" name="action" value="aibima_save_invoice" />
                <input type="hidden" name="invoice_id" value="<?php echo esc_attr($post_id); ?>" />
                <?php wp_nonce_field('aibima_save_invoice_' . $post_id); ?>

                <div class="aibill-grid-2">
                    <div class="aibill-card">
                        <h2><?php esc_html_e('Invoice Details', 'aibill-maker'); ?></h2>
                        <?php $this->edit_input('invoice_number', __('Invoice number', 'aibill-maker'), $invoice['invoice_number'] ?? ''); ?>
                        <?php $this->edit_input('issue_date', __('Issue date', 'aibill-maker'), $invoice['issue_date'] ?? '', 'date'); ?>
                        <?php $this->edit_input('due_date', __('Due date', 'aibill-maker'), $invoice['due_date'] ?? '', 'date'); ?>
                        <?php $this->edit_input('currency', __('Currency', 'aibill-maker'), $invoice['currency'] ?? 'INR', 'text', 3); ?>
                        <p>
                            <label for="aibill-tax-component"><strong><?php esc_html_e('Tax component', 'aibill-maker'); ?></strong></label>
                            <select id="aibill-tax-component" name="tax_component">
                                <option value="cgst_sgst" <?php selected($invoice['tax_component'] ?? '', 'cgst_sgst'); ?>><?php esc_html_e('CGST + SGST', 'aibill-maker'); ?></option>
                                <option value="igst" <?php selected($invoice['tax_component'] ?? '', 'igst'); ?>><?php esc_html_e('IGST', 'aibill-maker'); ?></option>
                                <option value="none" <?php selected($invoice['tax_component'] ?? '', 'none'); ?>><?php esc_html_e('No tax', 'aibill-maker'); ?></option>
                            </select>
                        </p>
                    </div>

                    <div class="aibill-card">
                        <h2><?php esc_html_e('Customer', 'aibill-maker'); ?></h2>
                        <?php $customer = is_array($invoice['customer'] ?? null) ? $invoice['customer'] : array(); ?>
                        <?php $this->edit_input('customer_name', __('Customer name', 'aibill-maker'), $customer['name'] ?? ''); ?>
                        <?php $this->edit_input('customer_email', __('Customer email', 'aibill-maker'), $customer['email'] ?? '', 'email'); ?>
                        <?php $this->edit_input('customer_phone', __('Customer phone', 'aibill-maker'), $customer['phone'] ?? ''); ?>
                        <?php $this->edit_input('customer_gstin', __('Customer GSTIN', 'aibill-maker'), $customer['gstin'] ?? ''); ?>
                        <?php $this->edit_textarea('customer_address', __('Customer address', 'aibill-maker'), $customer['address'] ?? ''); ?>
                    </div>
                </div>

                <div class="aibill-card">
                    <div class="aibill-card-head inline">
                        <h2><?php esc_html_e('Items', 'aibill-maker'); ?></h2>
                        <button type="button" class="button" data-aibill-add-item><?php esc_html_e('Add item', 'aibill-maker'); ?></button>
                    </div>
                    <table class="widefat aibill-items-edit" data-aibill-items-table>
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('Qty', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('Unit', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('Rate', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('Tax %', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('Inclusive', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('HSN/SAC', 'aibill-maker'); ?></th>
                            <th><?php esc_html_e('Remove', 'aibill-maker'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($items)) { $items[] = array(); } ?>
                        <?php foreach ($items as $index => $item): ?>
                            <?php $this->edit_item_row($index, $item); ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="aibill-grid-2">
                    <div class="aibill-card">
                        <h2><?php esc_html_e('Business', 'aibill-maker'); ?></h2>
                        <?php $business = is_array($invoice['business'] ?? null) ? $invoice['business'] : array(); ?>
                        <?php $this->edit_input('business_name', __('Business name', 'aibill-maker'), $business['name'] ?? ''); ?>
                        <?php $this->edit_input('business_email', __('Business email', 'aibill-maker'), $business['email'] ?? '', 'email'); ?>
                        <?php $this->edit_input('business_phone', __('Business phone', 'aibill-maker'), $business['phone'] ?? ''); ?>
                        <?php $this->edit_input('business_gstin', __('Business GSTIN', 'aibill-maker'), $business['gstin'] ?? ''); ?>
                        <?php $this->edit_textarea('business_address', __('Business address', 'aibill-maker'), $business['address'] ?? ''); ?>
                    </div>
                    <div class="aibill-card">
                        <h2><?php esc_html_e('Notes & Terms', 'aibill-maker'); ?></h2>
                        <?php $this->edit_textarea('notes', __('Notes', 'aibill-maker'), $invoice['notes'] ?? ''); ?>
                        <?php $this->edit_textarea('terms', __('Terms', 'aibill-maker'), $invoice['terms'] ?? ''); ?>
                        <?php $this->edit_textarea('payment_instructions', __('Payment instructions', 'aibill-maker'), $invoice['payment_instructions'] ?? ''); ?>
                    </div>
                </div>

                <?php submit_button(__('Save Invoice', 'aibill-maker')); ?>
            </form>
        </div>
        <?php
    }

    private function edit_item_row($index, $item) {
        ?>
        <tr>
            <td><input type="text" name="items[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($item['name'] ?? ''); ?>" class="regular-text" /></td>
            <td><input type="number" step="0.001" min="0" name="items[<?php echo esc_attr($index); ?>][quantity]" value="<?php echo esc_attr($item['quantity'] ?? 1); ?>" /></td>
            <td><input type="text" name="items[<?php echo esc_attr($index); ?>][unit]" value="<?php echo esc_attr($item['unit'] ?? 'pcs'); ?>" /></td>
            <td><input type="number" step="0.01" min="0" name="items[<?php echo esc_attr($index); ?>][unit_price]" value="<?php echo esc_attr($item['unit_price'] ?? 0); ?>" /></td>
            <td><input type="number" step="0.01" min="0" max="100" name="items[<?php echo esc_attr($index); ?>][tax_rate]" value="<?php echo esc_attr($item['tax_rate'] ?? 0); ?>" /></td>
            <td><label><input type="checkbox" name="items[<?php echo esc_attr($index); ?>][tax_inclusive]" value="1" <?php checked(!empty($item['tax_inclusive'])); ?> /> <?php esc_html_e('Yes', 'aibill-maker'); ?></label></td>
            <td><input type="text" name="items[<?php echo esc_attr($index); ?>][hsn_sac]" value="<?php echo esc_attr($item['hsn_sac'] ?? ''); ?>" /></td>
            <td><button type="button" class="button-link-delete" data-aibill-remove-item><?php esc_html_e('Remove', 'aibill-maker'); ?></button></td>
        </tr>
        <?php
    }

    private function edit_input($name, $label, $value, $type = 'text', $maxlength = 0) {
        ?>
        <p>
            <label for="aibill-<?php echo esc_attr($name); ?>"><strong><?php echo esc_html($label); ?></strong></label>
            <input id="aibill-<?php echo esc_attr($name); ?>" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $maxlength ? 'maxlength="' . esc_attr($maxlength) . '"' : ''; ?> />
        </p>
        <?php
    }

    private function edit_textarea($name, $label, $value) {
        ?>
        <p>
            <label for="aibill-<?php echo esc_attr($name); ?>"><strong><?php echo esc_html($label); ?></strong></label>
            <textarea id="aibill-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" rows="3"><?php echo esc_textarea($value); ?></textarea>
        </p>
        <?php
    }

    public function ajax_generate_invoice() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to create invoices.', 'aibill-maker')), 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $prompt_raw = filter_input(INPUT_POST, 'prompt', FILTER_UNSAFE_RAW);
        $prompt = trim(wp_strip_all_tags((string) (null !== $prompt_raw ? $prompt_raw : '')));
        if (strlen($prompt) < 3) {
            wp_send_json_error(array('message' => __('Please write invoice details first.', 'aibill-maker')), 422);
        }
        if (strlen($prompt) > 10000) {
            $prompt = substr($prompt, 0, 10000);
        }

        $settings = self::settings();
        $invoice = AIBIMA_Parser::parse($prompt, $settings);
        if (null === $invoice && AIBIMA_AI::is_text_generation_supported()) {
            $invoice = AIBIMA_AI::invoice_from_prompt($prompt, $settings);
        }
        if (null === $invoice) {
            wp_send_json_error(array('message' => __('Could not detect invoice items or amount from the prompt. Add an item and amount, for example: Website design ₹15000 GST 18% for Rajesh Kumar.', 'aibill-maker')), 422);
        }
        if (is_wp_error($invoice)) {
            wp_send_json_error(array('message' => $invoice->get_error_message()), 422);
        }

        $post_id = $this->save_invoice_record($invoice, $prompt);
        if (!$post_id) {
            wp_send_json_error(array('message' => __('Invoice could not be saved.', 'aibill-maker')), 500);
        }
        $invoice['post_id'] = $post_id;

        wp_send_json_success(array(
            'message' => __('Invoice generated and saved.', 'aibill-maker'),
            'id' => $post_id,
            'viewUrl' => $this->invoice_url($post_id, 'view'),
            'editUrl' => $this->invoice_url($post_id, 'edit'),
            'printUrl' => $this->print_url($post_id),
            'downloadUrl' => $this->download_url($post_id),
            'html' => AIBIMA_Renderer::invoice($invoice, false),
        ));
    }

    public function handle_save_invoice() {
        $post_id_raw = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
        $post_id = absint(false !== $post_id_raw && null !== $post_id_raw ? $post_id_raw : 0);
        if (!$post_id || !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save this invoice.', 'aibill-maker'));
        }
        check_admin_referer('aibima_save_invoice_' . $post_id);

        $post_data = filter_input_array(INPUT_POST, FILTER_UNSAFE_RAW);
        $invoice = $this->invoice_from_post(is_array($post_data) ? $post_data : array());
        $saved_id = $this->save_invoice_record($invoice, get_post_meta($post_id, '_aibima_prompt', true), $post_id);
        if (!$saved_id) {
            wp_die(esc_html__('Invoice could not be saved.', 'aibill-maker'));
        }

        wp_safe_redirect(add_query_arg(array('page' => 'aibima-maker', 'action' => 'view', 'invoice_id' => $saved_id, 'aibima_notice' => 'saved'), admin_url('admin.php')));
        exit;
    }

    public function handle_delete_invoice() {
        $post_id_raw = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT);
        $post_id = absint(false !== $post_id_raw && null !== $post_id_raw ? $post_id_raw : 0);
        if (!$post_id || !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to delete this invoice.', 'aibill-maker'));
        }
        check_admin_referer('aibima_delete_invoice_' . $post_id);
        wp_trash_post($post_id);
        wp_safe_redirect(add_query_arg(array('page' => 'aibima-maker', 'aibima_notice' => 'deleted'), admin_url('admin.php')));
        exit;
    }

    public function handle_print_invoice() {
        $post_id_raw = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT);
        $post_id = absint(false !== $post_id_raw && null !== $post_id_raw ? $post_id_raw : 0);
        if (!$post_id || !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to print this invoice.', 'aibill-maker'));
        }
        check_admin_referer('aibima_print_invoice_' . $post_id);
        $invoice = $this->get_invoice($post_id);
        if (!$invoice) {
            wp_die(esc_html__('Invoice not found.', 'aibill-maker'));
        }
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        wp_enqueue_style('aibima-print', AIBIMA_URL . 'assets/css/print.css', array(), AIBIMA_ASSET_VERSION);
        wp_enqueue_script('aibima-print', AIBIMA_URL . 'assets/js/print.js', array(), AIBIMA_ASSET_VERSION, false);
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html($invoice['invoice_number'] ?? __('Invoice', 'aibill-maker')); ?></title>
            <?php wp_print_styles('aibima-print'); ?>
            <?php wp_print_scripts('aibima-print'); ?>
        </head>
        <body class="aibill-print-body">
            <div class="aibill-print-toolbar no-print">
                <button type="button" data-aibill-print-page><?php esc_html_e('Print', 'aibill-maker'); ?></button>
                <button type="button" data-aibill-close-window><?php esc_html_e('Close', 'aibill-maker'); ?></button>
            </div>
            <?php echo AIBIMA_Renderer::invoice($invoice, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </body>
        </html>
        <?php
        exit;
    }

    public function handle_download_pdf() {
        $post_id_raw = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT);
        $post_id = absint(false !== $post_id_raw && null !== $post_id_raw ? $post_id_raw : 0);
        if (!$post_id || !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to download this invoice.', 'aibill-maker'));
        }
        check_admin_referer('aibima_download_pdf_' . $post_id);
        $invoice = $this->get_invoice($post_id);
        if (!$invoice) {
            wp_die(esc_html__('Invoice not found.', 'aibill-maker'));
        }
        $filename = sanitize_file_name(($invoice['invoice_number'] ?? 'invoice') . '.pdf');
        $pdf = AIBIMA_PDF::invoice($invoice);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
        exit;
    }

    private function invoice_from_post($post) {
        $settings = self::settings();
        $currency = strtoupper(sanitize_text_field($post['currency'] ?? 'INR'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'INR';
        }
        $tax_component = sanitize_key($post['tax_component'] ?? 'cgst_sgst');
        if (!in_array($tax_component, array('cgst_sgst', 'igst', 'none'), true)) {
            $tax_component = 'cgst_sgst';
        }

        $items = array();
        $raw_items = is_array($post['items'] ?? null) ? $post['items'] : array();
        foreach ($raw_items as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $name = sanitize_text_field($raw['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $items[] = array(
                'name' => $name,
                'description' => sanitize_text_field($raw['description'] ?? ''),
                'quantity' => max(0, (float) ($raw['quantity'] ?? 1)),
                'unit' => sanitize_text_field($raw['unit'] ?? 'pcs'),
                'unit_price' => max(0, (float) ($raw['unit_price'] ?? 0)),
                'tax_rate' => max(0, min(100, (float) ($raw['tax_rate'] ?? 0))),
                'tax_inclusive' => !empty($raw['tax_inclusive']),
                'hsn_sac' => sanitize_text_field($raw['hsn_sac'] ?? ''),
            );
        }

        if (empty($items)) {
            $items[] = array(
                'name' => __('Item', 'aibill-maker'),
                'description' => '',
                'quantity' => 1,
                'unit' => 'pcs',
                'unit_price' => 0,
                'tax_rate' => (float) ($settings['default_tax_rate'] ?? 0),
                'tax_inclusive' => false,
                'hsn_sac' => '',
            );
        }

        $invoice = array(
            'source' => sanitize_text_field($post['source'] ?? 'edited'),
            'invoice_type' => 'invoice',
            'invoice_number' => sanitize_text_field($post['invoice_number'] ?? ''),
            'issue_date' => $this->sanitize_date($post['issue_date'] ?? current_time('Y-m-d')),
            'due_date' => $this->sanitize_date($post['due_date'] ?? gmdate('Y-m-d', current_time('timestamp') + WEEK_IN_SECONDS)),
            'currency' => $currency,
            'customer' => array(
                'name' => sanitize_text_field($post['customer_name'] ?? ''),
                'email' => sanitize_email($post['customer_email'] ?? ''),
                'phone' => sanitize_text_field($post['customer_phone'] ?? ''),
                'gstin' => strtoupper(sanitize_text_field($post['customer_gstin'] ?? '')),
                'address' => sanitize_textarea_field($post['customer_address'] ?? ''),
            ),
            'business' => array(
                'name' => sanitize_text_field($post['business_name'] ?? ''),
                'email' => sanitize_email($post['business_email'] ?? ''),
                'phone' => sanitize_text_field($post['business_phone'] ?? ''),
                'gstin' => strtoupper(sanitize_text_field($post['business_gstin'] ?? '')),
                'address' => sanitize_textarea_field($post['business_address'] ?? ''),
            ),
            'tax_component' => $tax_component,
            'tax_inclusive' => false,
            'items' => $items,
            'notes' => wp_kses_post($post['notes'] ?? ''),
            'terms' => wp_kses_post($post['terms'] ?? ''),
            'payment_instructions' => wp_kses_post($post['payment_instructions'] ?? ''),
        );

        if ($invoice['invoice_number'] === '') {
            $invoice['invoice_number'] = $this->next_invoice_number();
        }

        return AIBIMA_Parser::calculate($invoice);
    }

    private function save_invoice_record($invoice, $prompt = '', $post_id = 0) {
        $customer = is_array($invoice['customer'] ?? null) ? $invoice['customer'] : array();
        $invoice_number = sanitize_text_field($invoice['invoice_number'] ?? $this->next_invoice_number());
        $title = trim($invoice_number . ' - ' . ($customer['name'] ?? __('Customer', 'aibill-maker')));

        $postarr = array(
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_author' => get_current_user_id() ?: 0,
        );
        if ($post_id) {
            $postarr['ID'] = $post_id;
            $saved = wp_update_post($postarr, true);
        } else {
            $saved = wp_insert_post($postarr, true);
        }

        if (is_wp_error($saved)) {
            return 0;
        }
        $post_id = (int) $saved;

        update_post_meta($post_id, '_aibima_invoice_json', $invoice);
        update_post_meta($post_id, '_aibima_prompt', sanitize_textarea_field($prompt));
        update_post_meta($post_id, '_aibima_invoice_number', $invoice_number);
        update_post_meta($post_id, '_aibima_customer_name', sanitize_text_field($customer['name'] ?? ''));
        update_post_meta($post_id, '_aibima_total', (float) ($invoice['grand_total'] ?? 0));
        update_post_meta($post_id, '_aibima_currency', sanitize_text_field($invoice['currency'] ?? 'INR'));
        update_post_meta($post_id, '_aibima_source', sanitize_text_field($invoice['source'] ?? 'local'));

        return $post_id;
    }

    private function get_invoice($post_id) {
        $post = get_post($post_id);
        if (!$post || self::CPT !== $post->post_type || 'trash' === $post->post_status) {
            return null;
        }
        $invoice = get_post_meta($post_id, '_aibima_invoice_json', true);
        if (!is_array($invoice)) {
            return null;
        }
        return $invoice;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        $ai_supported = AIBIMA_AI::is_text_generation_supported();
        $test_notice = get_transient('aibima_test_notice_' . get_current_user_id());
        if ($test_notice) {
            delete_transient('aibima_test_notice_' . get_current_user_id());
        }
        ?>
        <div class="wrap aibill-admin-wrap">
            <h1><?php esc_html_e('AiBill Maker Settings', 'aibill-maker'); ?></h1>
            <p><?php esc_html_e('Configure the business profile and invoice defaults. AI provider credentials are managed by WordPress in Settings > Connectors.', 'aibill-maker'); ?></p>
            <?php if ($test_notice): ?>
                <div class="notice notice-<?php echo esc_attr($test_notice['type']); ?> is-dismissible"><p><?php echo esc_html($test_notice['message']); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php" class="aibill-settings-form">
                <?php settings_fields('aibima_settings_group'); ?>
                <div class="aibill-card">
                    <h2><?php esc_html_e('WordPress AI Client', 'aibill-maker'); ?></h2>
                    <?php if ($ai_supported): ?>
                        <div class="notice notice-success inline"><p><?php esc_html_e('Text-generation AI support is available through WordPress Connectors.', 'aibill-maker'); ?></p></div>
                    <?php else: ?>
                        <div class="notice notice-warning inline"><p><?php esc_html_e('No text-generation AI provider is configured yet. Go to Settings > Connectors and add an AI provider before using natural-language prompts.', 'aibill-maker'); ?></p></div>
                    <?php endif; ?>
                    <p><?php esc_html_e('AiBill Maker does not store provider API keys. WordPress manages provider selection, API keys, and model routing through the built-in AI Client and Connectors screen.', 'aibill-maker'); ?></p>
                </div>

                <div class="aibill-card">
                    <h2><?php esc_html_e('Invoice Defaults', 'aibill-maker'); ?></h2>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th scope="row"><label for="aibill-default-currency"><?php esc_html_e('Default currency', 'aibill-maker'); ?></label></th><td><input type="text" id="aibill-default-currency" name="<?php echo esc_attr(self::OPTION); ?>[default_currency]" value="<?php echo esc_attr($settings['default_currency']); ?>" maxlength="3" class="small-text" /></td></tr>
                        <tr><th scope="row"><label for="aibill-default-tax"><?php esc_html_e('Default tax/GST rate', 'aibill-maker'); ?></label></th><td><input type="number" id="aibill-default-tax" name="<?php echo esc_attr(self::OPTION); ?>[default_tax_rate]" value="<?php echo esc_attr($settings['default_tax_rate']); ?>" min="0" max="100" step="0.01" class="small-text" />%</td></tr>
                        <tr><th scope="row"><label for="aibill-invoice-prefix"><?php esc_html_e('Invoice prefix', 'aibill-maker'); ?></label></th><td><input type="text" id="aibill-invoice-prefix" name="<?php echo esc_attr(self::OPTION); ?>[invoice_prefix]" value="<?php echo esc_attr($settings['invoice_prefix']); ?>" class="small-text" /></td></tr>
                    </tbody></table>
                </div>

                <div class="aibill-card">
                    <h2><?php esc_html_e('Business Profile', 'aibill-maker'); ?></h2>
                    <table class="form-table" role="presentation"><tbody>
                        <?php $this->settings_text_field('business_name', __('Business name', 'aibill-maker'), $settings['business_name']); ?>
                        <?php $this->settings_text_field('business_email', __('Business email', 'aibill-maker'), $settings['business_email'], 'email'); ?>
                        <?php $this->settings_text_field('business_phone', __('Business phone', 'aibill-maker'), $settings['business_phone']); ?>
                        <?php $this->settings_text_field('business_gstin', __('Business GSTIN', 'aibill-maker'), $settings['business_gstin']); ?>
                        <?php $this->settings_textarea_field('business_address', __('Business address', 'aibill-maker'), $settings['business_address']); ?>
                        <?php $this->settings_textarea_field('payment_instructions', __('Payment instructions', 'aibill-maker'), $settings['payment_instructions']); ?>
                        <?php $this->settings_textarea_field('default_terms', __('Default terms', 'aibill-maker'), $settings['default_terms']); ?>
                        <?php $this->settings_textarea_field('default_notes', __('Default notes', 'aibill-maker'), $settings['default_notes']); ?>
                    </tbody></table>
                </div>
                <?php submit_button(__('Save AiBill Settings', 'aibill-maker')); ?>
            </form>

            <div class="aibill-card">
                <h2><?php esc_html_e('Check AI Client Status', 'aibill-maker'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="aibima_test_connection" />
                    <?php wp_nonce_field('aibima_test_connection'); ?>
                    <?php submit_button(__('Check AI Client status', 'aibill-maker'), 'secondary', 'submit', false); ?>
                    <p class="description"><?php esc_html_e('This checks whether WordPress Connectors has a text-generation AI provider available. It does not send an invoice prompt.', 'aibill-maker'); ?></p>
                </form>
            </div>

            <?php $this->ai_client_help_box(); ?>
        </div>
        <?php
    }

    private function settings_text_field($key, $label, $value, $type = 'text') {
        ?>
        <tr>
            <th scope="row"><label for="aibill-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><input type="<?php echo esc_attr($type); ?>" class="regular-text" id="aibill-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" /></td>
        </tr>
        <?php
    }

    private function settings_textarea_field($key, $label, $value) {
        ?>
        <tr>
            <th scope="row"><label for="aibill-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><textarea class="large-text" rows="3" id="aibill-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]"><?php echo esc_textarea($value); ?></textarea></td>
        </tr>
        <?php
    }

    public function handle_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to test this connection.', 'aibill-maker'));
        }
        check_admin_referer('aibima_test_connection');
        $result = AIBIMA_AI::test_connection(self::settings());
        if (is_wp_error($result)) {
            set_transient('aibima_test_notice_' . get_current_user_id(), array('type' => 'error', 'message' => $result->get_error_message()), 60);
        } else {
            set_transient('aibima_test_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('WordPress AI Client text generation is available.', 'aibill-maker')), 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=aibima-settings'));
        exit;
    }

    private function ai_client_help_box() {
        ?>
        <div class="aibill-card aibill-provider-help">
            <h2><?php esc_html_e('How AI setup works', 'aibill-maker'); ?></h2>
            <p><?php esc_html_e('AiBill uses local parsing first for structured prompts. When a prompt is more natural or messy, it asks the WordPress AI Client for text generation.', 'aibill-maker'); ?></p>
            <ol>
                <li><strong><?php esc_html_e('Open Connectors:', 'aibill-maker'); ?></strong> <?php esc_html_e('Go to Settings > Connectors in WordPress admin.', 'aibill-maker'); ?></li>
                <li><strong><?php esc_html_e('Configure provider:', 'aibill-maker'); ?></strong> <?php esc_html_e('Add any AI provider supported by WordPress Connectors and your site.', 'aibill-maker'); ?></li>
                <li><strong><?php esc_html_e('Generate invoice:', 'aibill-maker'); ?></strong> <?php esc_html_e('Return to AiBill Maker and use the Create Invoice prompt.', 'aibill-maker'); ?></li>
            </ol>
            <p><?php esc_html_e('AiBill Maker does not include direct provider endpoints and does not store AI API keys in its own settings.', 'aibill-maker'); ?></p>
        </div>
        <?php
    }

    private function sample_prompt() {
        return "Invoice Malhotra Industries with 18% GST exclusive\n5 x Pencil @ 50\n5 x Paper @ 20\n2 x Office Chair @ 2000\nDue in 7 days";
    }

    private function next_invoice_number() {
        $settings = self::settings();
        $prefix = strtoupper(sanitize_key($settings['invoice_prefix'] ?? 'INV')) ?: 'INV';
        $count = (int) wp_count_posts(self::CPT)->publish + 1;
        return $prefix . '-' . current_time('Ymd') . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function sanitize_date($value) {
        $value = sanitize_text_field($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return current_time('Y-m-d');
        }
        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) ? $value : current_time('Y-m-d');
    }

    private function invoice_url($post_id, $action) {
        return add_query_arg(array('page' => 'aibima-maker', 'action' => sanitize_key($action), 'invoice_id' => absint($post_id)), admin_url('admin.php'));
    }

    private function print_url($post_id) {
        return wp_nonce_url(add_query_arg(array('action' => 'aibima_print_invoice', 'invoice_id' => absint($post_id)), admin_url('admin-post.php')), 'aibima_print_invoice_' . absint($post_id));
    }

    private function download_url($post_id) {
        return wp_nonce_url(add_query_arg(array('action' => 'aibima_download_pdf', 'invoice_id' => absint($post_id)), admin_url('admin-post.php')), 'aibima_download_pdf_' . absint($post_id));
    }

    private function delete_url($post_id) {
        return wp_nonce_url(add_query_arg(array('action' => 'aibima_delete_invoice', 'invoice_id' => absint($post_id)), admin_url('admin-post.php')), 'aibima_delete_invoice_' . absint($post_id));
    }

    private function pagination($query, $paged) {
        if ($query->max_num_pages <= 1) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo wp_kses_post(paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'current' => max(1, $paged),
            'total' => (int) $query->max_num_pages,
        )));
        echo '</div></div>';
    }

    private function admin_notice_from_query() {
        $notice_raw = filter_input(INPUT_GET, 'aibima_notice', FILTER_UNSAFE_RAW);
        $notice = sanitize_key(null !== $notice_raw ? $notice_raw : '');
        if ('saved' === $notice) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Invoice saved.', 'aibill-maker') . '</p></div>';
        } elseif ('deleted' === $notice) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Invoice moved to Trash.', 'aibill-maker') . '</p></div>';
        }
    }

    private function not_found_page() {
        ?>
        <div class="wrap"><h1><?php esc_html_e('Invoice not found', 'aibill-maker'); ?></h1><p><a href="<?php echo esc_url(admin_url('admin.php?page=aibima-maker')); ?>"><?php esc_html_e('Back to invoices', 'aibill-maker'); ?></a></p></div>
        <?php
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=aibima-settings')) . '">' . esc_html__('Settings', 'aibill-maker') . '</a>';
        $invoices_link = '<a href="' . esc_url(admin_url('admin.php?page=aibima-maker')) . '">' . esc_html__('Invoices', 'aibill-maker') . '</a>';
        array_unshift($links, $settings_link, $invoices_link);
        return $links;
    }
}
