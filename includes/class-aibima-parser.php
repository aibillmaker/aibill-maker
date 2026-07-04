<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prompt parser for structured invoice text.
 * This keeps simple prompts fast and reduces unnecessary AI API cost.
 */
class AIBIMA_Parser {
    public static function parse($prompt, $settings = array()) {
        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            return new WP_Error('aibima_empty_prompt', __('Please write invoice details first.', 'aibill-maker'));
        }

        $customer = self::extract_customer($prompt);
        $currency = self::extract_currency($prompt, $settings['default_currency'] ?? 'INR');
        $tax = self::extract_tax($prompt, isset($settings['default_tax_rate']) ? (float) $settings['default_tax_rate'] : 18.0);
        $items = self::extract_items($prompt, $tax);

        if (empty($items)) {
            return null;
        }

        $invoice = array(
            'source' => 'local',
            'invoice_type' => 'invoice',
            'invoice_number' => self::next_invoice_number(),
            'issue_date' => current_time('Y-m-d'),
            'due_date' => self::extract_due_date($prompt),
            'currency' => $currency,
            'customer' => array(
                'name' => $customer,
                'email' => self::extract_email($prompt),
                'phone' => self::extract_phone($prompt),
                'gstin' => self::extract_gstin($prompt),
                'address' => '',
            ),
            'business' => array(
                'name' => $settings['business_name'] ?? get_bloginfo('name'),
                'email' => $settings['business_email'] ?? get_bloginfo('admin_email'),
                'phone' => $settings['business_phone'] ?? '',
                'gstin' => $settings['business_gstin'] ?? '',
                'address' => $settings['business_address'] ?? '',
            ),
            'tax_component' => $tax['component'],
            'tax_inclusive' => $tax['inclusive'],
            'items' => $items,
            'notes' => $settings['default_notes'] ?? __('Thank you for your business.', 'aibill-maker'),
            'terms' => $settings['default_terms'] ?? __('Payment due as per due date.', 'aibill-maker'),
            'payment_instructions' => $settings['payment_instructions'] ?? '',
        );

        return self::calculate($invoice);
    }

    public static function normalize_ai_invoice($data, $prompt, $settings = array()) {
        if (!is_array($data)) {
            return new WP_Error('aibima_ai_invalid', __('AI returned an invalid invoice format.', 'aibill-maker'));
        }

        $tax = self::extract_tax($prompt, isset($settings['default_tax_rate']) ? (float) $settings['default_tax_rate'] : 18.0);
        $currency = self::extract_currency($prompt, $settings['default_currency'] ?? ($data['currency'] ?? 'INR'));
        $items = array();

        $raw_items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
        foreach ($raw_items as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $name = self::clean_text($raw['name'] ?? $raw['description'] ?? '');
            if ($name === '') {
                continue;
            }
            $qty = self::positive_float($raw['quantity'] ?? $raw['qty'] ?? 1, 1);
            $price = self::positive_float($raw['unit_price'] ?? $raw['rate'] ?? $raw['price'] ?? 0, 0);
            $item_tax = self::positive_float($raw['tax_rate'] ?? $tax['rate'], $tax['rate']);
            $items[] = array(
                'name' => $name,
                'description' => self::clean_text($raw['description'] ?? ''),
                'quantity' => $qty,
                'unit' => self::clean_text($raw['unit'] ?? 'pcs'),
                'unit_price' => $price,
                'tax_rate' => $item_tax,
                'tax_inclusive' => !empty($raw['tax_inclusive']) || !empty($raw['gst_inclusive']) || $tax['inclusive'],
                'hsn_sac' => self::clean_text($raw['hsn_sac'] ?? ''),
            );
        }

        if (empty($items)) {
            return new WP_Error('aibima_ai_no_items', __('AI could not find invoice items. Try adding one item per line, for example: 2 x Design @ 5000.', 'aibill-maker'));
        }

        $customer = isset($data['customer']) && is_array($data['customer']) ? $data['customer'] : array();
        $business = isset($data['business']) && is_array($data['business']) ? $data['business'] : array();

        $invoice = array(
            'source' => 'ai',
            'invoice_type' => self::clean_text($data['invoice_type'] ?? 'invoice'),
            'invoice_number' => self::clean_text($data['invoice_number'] ?? '') ?: self::next_invoice_number(),
            'issue_date' => self::valid_date($data['issue_date'] ?? '') ?: current_time('Y-m-d'),
            'due_date' => self::valid_date($data['due_date'] ?? '') ?: self::extract_due_date($prompt),
            'currency' => $currency,
            'customer' => array(
                'name' => self::clean_text($customer['name'] ?? $customer['display_name'] ?? $customer['company_name'] ?? ($data['customer_name'] ?? self::extract_customer($prompt))),
                'email' => sanitize_email($customer['email'] ?? self::extract_email($prompt)),
                'phone' => self::clean_text($customer['phone'] ?? self::extract_phone($prompt)),
                'gstin' => self::clean_text($customer['gstin'] ?? self::extract_gstin($prompt)),
                'address' => self::clean_text($customer['address'] ?? $customer['billing_address'] ?? ''),
            ),
            'business' => array(
                'name' => self::clean_text($business['name'] ?? $business['business_name'] ?? ($settings['business_name'] ?? get_bloginfo('name'))),
                'email' => sanitize_email($business['email'] ?? ($settings['business_email'] ?? get_bloginfo('admin_email'))),
                'phone' => self::clean_text($business['phone'] ?? ($settings['business_phone'] ?? '')),
                'gstin' => self::clean_text($business['gstin'] ?? ($settings['business_gstin'] ?? '')),
                'address' => self::clean_text($business['address'] ?? ($settings['business_address'] ?? '')),
            ),
            'tax_component' => self::clean_text($data['tax_component'] ?? $data['gst_component'] ?? $tax['component']),
            'tax_inclusive' => !empty($data['tax_inclusive']) || !empty($data['gst_inclusive']) || $tax['inclusive'],
            'items' => $items,
            'notes' => wp_kses_post($data['notes'] ?? ($settings['default_notes'] ?? __('Thank you for your business.', 'aibill-maker'))),
            'terms' => wp_kses_post($data['terms'] ?? ($settings['default_terms'] ?? __('Payment due as per due date.', 'aibill-maker'))),
            'payment_instructions' => wp_kses_post($data['payment_instructions'] ?? ($settings['payment_instructions'] ?? '')),
        );

        return self::calculate($invoice);
    }

    public static function calculate($invoice) {
        $subtotal = 0.0;
        $tax_total = 0.0;
        $grand_total = 0.0;
        $items = array();

        foreach (($invoice['items'] ?? array()) as $item) {
            $qty = self::positive_float($item['quantity'] ?? 1, 1);
            $price = self::positive_float($item['unit_price'] ?? 0, 0);
            $tax_rate = max(0, min(100, self::positive_float($item['tax_rate'] ?? 0, 0)));
            $inclusive = !empty($item['tax_inclusive']);
            $gross = round($qty * $price, 2);

            if ($inclusive && $tax_rate > 0) {
                $taxable = round($gross / (1 + ($tax_rate / 100)), 2);
                $tax_amount = round($gross - $taxable, 2);
                $line_total = $gross;
            } else {
                $taxable = $gross;
                $tax_amount = round($taxable * $tax_rate / 100, 2);
                $line_total = round($taxable + $tax_amount, 2);
            }

            $subtotal += $taxable;
            $tax_total += $tax_amount;
            $grand_total += $line_total;

            $items[] = array(
                'name' => self::clean_text($item['name'] ?? ''),
                'description' => self::clean_text($item['description'] ?? ''),
                'quantity' => $qty,
                'unit' => self::clean_text($item['unit'] ?? 'pcs'),
                'unit_price' => $price,
                'tax_rate' => $tax_rate,
                'tax_inclusive' => $inclusive,
                'taxable_amount' => round($taxable, 2),
                'tax_amount' => round($tax_amount, 2),
                'line_total' => round($line_total, 2),
                'hsn_sac' => self::clean_text($item['hsn_sac'] ?? ''),
            );
        }

        $invoice['items'] = $items;
        $invoice['subtotal'] = round($subtotal, 2);
        $invoice['tax_total'] = round($tax_total, 2);
        $invoice['grand_total'] = round($grand_total, 2);
        $invoice['amount_paid'] = 0.0;
        $invoice['amount_due'] = $invoice['grand_total'];
        $invoice['created_at'] = current_time('mysql');

        $component = $invoice['tax_component'] ?? 'cgst_sgst';
        if ($component === 'igst') {
            $invoice['tax_breakup'] = array('igst' => round($tax_total, 2));
        } elseif ($component === 'none' || $tax_total <= 0) {
            $invoice['tax_breakup'] = array();
        } else {
            $invoice['tax_breakup'] = array(
                'cgst' => round($tax_total / 2, 2),
                'sgst' => round($tax_total / 2, 2),
            );
        }

        return $invoice;
    }

    private static function extract_items($prompt, $tax) {
        $items = array();
        $lines = preg_split('/\R+/', (string) $prompt);
        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '') {
                continue;
            }
            $line = str_replace(array('×', 'X'), 'x', $raw);
            $matched = false;

            // Format: 5 x Pencil @ 50/-
            if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:x|\*)\s*(.+?)\s*(?:@|at|for)\s*(?:₹|rs\.?|inr|\$)?\s*([0-9][0-9,]*(?:\.\d+)?)/i', $line, $m)) {
                $items[] = array(
                    'quantity' => self::positive_float($m[1], 1),
                    'name' => self::clean_text($m[2]),
                    'unit' => 'pcs',
                    'unit_price' => self::positive_float(str_replace(',', '', $m[3]), 0),
                    'tax_rate' => $tax['rate'],
                    'tax_inclusive' => $tax['inclusive'],
                    'description' => '',
                    'hsn_sac' => '',
                );
                $matched = true;
            }

            // Format: Pencil qty 5 rate 50
            if (!$matched && preg_match('/^\s*(.+?)\s+(?:qty|quantity)\s*[:\-]?\s*(\d+(?:\.\d+)?)\s+(?:rate|price)\s*[:\-]?\s*(?:₹|rs\.?|inr|\$)?\s*([0-9][0-9,]*(?:\.\d+)?)/i', $line, $m)) {
                $items[] = array(
                    'quantity' => self::positive_float($m[2], 1),
                    'name' => self::clean_text($m[1]),
                    'unit' => 'pcs',
                    'unit_price' => self::positive_float(str_replace(',', '', $m[3]), 0),
                    'tax_rate' => $tax['rate'],
                    'tax_inclusive' => $tax['inclusive'],
                    'description' => '',
                    'hsn_sac' => '',
                );
            }
        }
        if (empty($items)) {
            $fallback_item = self::extract_single_amount_item($prompt, $tax);
            if (!empty($fallback_item)) {
                $items[] = $fallback_item;
            }
        }

        return $items;
    }

    private static function extract_single_amount_item($prompt, $tax) {
        $amount = self::extract_main_amount($prompt);
        if ($amount <= 0) {
            return array();
        }

        $name = self::extract_item_name($prompt);
        if ($name === '') {
            $name = __('Service', 'aibill-maker');
        }

        return array(
            'quantity' => 1,
            'name' => $name,
            'unit' => 'pcs',
            'unit_price' => $amount,
            'tax_rate' => $tax['rate'],
            'tax_inclusive' => $tax['inclusive'],
            'description' => '',
            'hsn_sac' => '',
        );
    }

    private static function extract_main_amount($prompt) {
        $amounts = array();

        if (preg_match_all('/(?:₹|rs\.?|inr|usd|\$|eur|€|gbp|£)\s*([0-9][0-9,]*(?:\.\d+)?)/i', $prompt, $matches)) {
            foreach ($matches[1] as $value) {
                $amounts[] = self::positive_float(str_replace(',', '', $value), 0);
            }
        }

        if (preg_match_all('/\b([0-9][0-9,]*(?:\.\d+)?)\s*(?:rupees?|rs\.?|inr|usd|dollars?|eur|euros?|gbp|pounds?)\b/i', $prompt, $matches)) {
            foreach ($matches[1] as $value) {
                $amounts[] = self::positive_float(str_replace(',', '', $value), 0);
            }
        }

        $amounts = array_filter($amounts, static function ($value) {
            return (float) $value > 0;
        });

        if (empty($amounts)) {
            return 0.0;
        }

        return (float) max($amounts);
    }

    private static function extract_item_name($prompt) {
        if (preg_match('/(?:invoice|bill)\s+for\s+[^,;]+[,;]\s*(.+?)(?:₹|rs\.?|inr|usd|\$|eur|€|gbp|£|\b[0-9][0-9,]*(?:\.\d+)?\s*(?:rupees?|rs\.?|inr|usd|dollars?|eur|euros?|gbp|pounds?)\b)/i', $prompt, $match)) {
            $candidate = self::clean_text($match[1]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $text = self::clean_text($prompt);
        $text = preg_replace('/\b(?:create|make|generate)\s+(?:an?\s+)?(?:invoice|bill)\b/i', '', $text);
        $text = preg_replace('/\b(?:invoice|bill)\s+(?:for|to)\s+[^,;]+[,;]/i', '', $text);
        $text = preg_replace('/\b(?:customer|client)\s*[:\-]\s*[^,;]+[,;]?/i', '', $text);
        $text = preg_replace('/\b(?:gst|igst|cgst|sgst|tax)\s*(?:@|at|of)?\s*\d+(?:\.\d+)?\s*%?/i', '', $text);
        $text = preg_replace('/\bdue\s+(?:in\s+)?\d{1,3}\s+days?\b/i', '', $text);
        $text = preg_replace('/\bdue\s+(?:date\s*)?[:\-]?\s*\d{4}-\d{2}-\d{2}\b/i', '', $text);
        $text = preg_replace('/(?:₹|rs\.?|inr|usd|\$|eur|€|gbp|£)\s*[0-9][0-9,]*(?:\.\d+)?/i', '', $text);
        $text = preg_replace('/\b[0-9][0-9,]*(?:\.\d+)?\s*(?:rupees?|rs\.?|inr|usd|dollars?|eur|euros?|gbp|pounds?)\b/i', '', $text);
        $text = preg_replace('/\b(?:for|to|with|and|of)\b/i', ' ', $text);
        $text = self::clean_text($text);

        if (strlen($text) > 80) {
            $parts = preg_split('/[,;]+/', $text);
            $text = self::clean_text($parts[0] ?? $text);
        }

        return $text;
    }

    private static function extract_customer($prompt) {
        $patterns = array(
            '/(?:create\s+|make\s+|generate\s+)?(?:an?\s+)?invoice\s+for\s+(.+?)(?:,|;|\s+with\s+|\s+for\s+)/i',
            '/(?:create\s+|make\s+|generate\s+)?(?:an?\s+)?bill\s+for\s+(.+?)(?:,|;|\s+with\s+|\s+for\s+)/i',
            '/invoice\s+(.+?)\s+with\s+/i',
            '/bill\s+(.+?)\s+with\s+/i',
            '/customer\s*[:\-]\s*(.+)$/im',
            '/client\s*[:\-]\s*(.+)$/im',
            '/to\s+(.+?)\s+(?:for|with)\s+/i',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $m)) {
                $name = self::clean_text($m[1]);
                $name = preg_replace('/\s+(?:gst|igst|cgst|sgst|tax).*$/i', '', $name);
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return __('Customer', 'aibill-maker');
    }

    private static function extract_tax($prompt, $default_rate = 18.0) {
        $rate = $default_rate;
        if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*(?:gst|igst|cgst|sgst|tax)?/i', $prompt, $m)) {
            $rate = (float) $m[1];
        } elseif (preg_match('/(?:gst|igst|tax)\s*(?:@|at|of)?\s*(\d+(?:\.\d+)?)\s*%?/i', $prompt, $m)) {
            $rate = (float) $m[1];
        } elseif (!preg_match('/\b(gst|igst|tax)\b/i', $prompt)) {
            $rate = 0.0;
        }

        $inclusive = (bool) preg_match('/\b(inclusive|including\s+gst|including\s+tax|tax\s+included|gst\s+included)\b/i', $prompt);
        $component = 'cgst_sgst';
        if (preg_match('/\bigst\b/i', $prompt)) {
            $component = 'igst';
        }
        if ($rate <= 0 || preg_match('/\b(no\s+gst|without\s+gst|no\s+tax)\b/i', $prompt)) {
            $component = 'none';
            $rate = 0.0;
        }

        return array(
            'rate' => max(0, min(100, round($rate, 2))),
            'inclusive' => $inclusive,
            'component' => $component,
        );
    }

    private static function extract_currency($prompt, $fallback = 'INR') {
        $map = array(
            '₹' => 'INR', 'rs' => 'INR', 'inr' => 'INR', 'rupee' => 'INR', 'rupees' => 'INR',
            '$' => 'USD', 'usd' => 'USD', 'dollar' => 'USD', 'dollars' => 'USD',
            '€' => 'EUR', 'eur' => 'EUR', 'euro' => 'EUR',
            '£' => 'GBP', 'gbp' => 'GBP', 'pound' => 'GBP',
            'aed' => 'AED', 'dirham' => 'AED',
        );
        foreach ($map as $needle => $code) {
            if (stripos($prompt, $needle) !== false) {
                return $code;
            }
        }
        $fallback = strtoupper((string) $fallback);
        return preg_match('/^[A-Z]{3}$/', $fallback) ? $fallback : 'INR';
    }

    private static function extract_due_date($prompt) {
        if (preg_match('/due\s+(?:in\s+)?(\d{1,3})\s+days?/i', $prompt, $m)) {
            return gmdate('Y-m-d', strtotime('+' . absint($m[1]) . ' days', current_time('timestamp')));
        }
        if (preg_match('/due\s+(?:date\s*)?[:\-]?\s*(\d{4}-\d{2}-\d{2})/i', $prompt, $m)) {
            return self::valid_date($m[1]) ?: gmdate('Y-m-d', strtotime('+7 days', current_time('timestamp')));
        }
        return gmdate('Y-m-d', strtotime('+7 days', current_time('timestamp')));
    }

    private static function extract_email($prompt) {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $prompt, $m)) {
            return sanitize_email($m[0]);
        }
        return '';
    }

    private static function extract_phone($prompt) {
        if (preg_match('/(?:phone|mobile|mob|tel)\s*[:\-]?\s*([+0-9][0-9\s\-()]{7,18})/i', $prompt, $m)) {
            return self::clean_text($m[1]);
        }
        return '';
    }

    private static function extract_gstin($prompt) {
        if (preg_match('/\b[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]\b/i', $prompt, $m)) {
            return strtoupper($m[0]);
        }
        return '';
    }

    public static function currency_symbol($currency) {
        $map = array('INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'AUD' => 'A$', 'CAD' => 'C$', 'SGD' => 'S$');
        $currency = strtoupper((string) $currency);
        return $map[$currency] ?? ($currency . ' ');
    }

    public static function money($amount, $currency = 'INR') {
        return self::currency_symbol($currency) . number_format((float) $amount, 2);
    }

    private static function clean_text($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    private static function positive_float($value, $fallback = 0) {
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }
        if (!is_numeric($value)) {
            return (float) $fallback;
        }
        return max(0, round((float) $value, 3));
    }

    private static function valid_date($value) {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) ? $value : null;
    }

    private static function next_invoice_number() {
        $date = current_time('Ymd');
        $settings = get_option('aibima_settings', array());
        $prefix = 'INV';
        if (is_array($settings) && !empty($settings['invoice_prefix'])) {
            $prefix = strtoupper(sanitize_key($settings['invoice_prefix']));
        }
        if ($prefix === '') {
            $prefix = 'INV';
        }
        $counts = wp_count_posts('aibima_invoice');
        $count = (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0) + 1;
        return $prefix . '-' . $date . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
