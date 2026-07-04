<?php
if (!defined('ABSPATH')) {
    exit;
}

class AIBIMA_AI {
    public static function invoice_from_prompt($prompt, $settings = array()) {
        $raw = self::generate_invoice_text($prompt);
        if (is_wp_error($raw)) {
            return $raw;
        }

        $json = self::extract_json($raw);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new WP_Error('aibima_ai_json', __('AI returned text but not valid JSON. Please try a clearer prompt.', 'aibill-maker'));
        }

        return AIBIMA_Parser::normalize_ai_invoice($data, $prompt, $settings);
    }

    public static function test_connection($settings = array()) {
        if (!function_exists('wp_ai_client_prompt')) {
            return new WP_Error('aibima_ai_client_missing', __('WordPress AI Client is not available. This plugin requires WordPress 7.0 or later.', 'aibill-maker'));
        }

        $builder = self::prompt_builder(__('Return the word ready.', 'aibill-maker'));
        if (is_wp_error($builder)) {
            return $builder;
        }

        if (!$builder->is_supported_for_text_generation()) {
            return new WP_Error('aibima_ai_not_configured', __('No text-generation AI provider is configured in WordPress Connectors. Open Settings > Connectors and configure an AI provider first.', 'aibill-maker'));
        }

        return true;
    }

    public static function is_text_generation_supported() {
        if (!function_exists('wp_ai_client_prompt')) {
            return false;
        }

        $builder = self::prompt_builder(__('Test AI text generation support.', 'aibill-maker'));
        if (is_wp_error($builder)) {
            return false;
        }

        return (bool) $builder->is_supported_for_text_generation();
    }

    private static function generate_invoice_text($prompt) {
        if (!function_exists('wp_ai_client_prompt')) {
            return new WP_Error('aibima_ai_client_missing', __('WordPress AI Client is not available. This plugin requires WordPress 7.0 or later.', 'aibill-maker'));
        }

        $builder = self::prompt_builder(self::full_prompt($prompt));
        if (is_wp_error($builder)) {
            return $builder;
        }

        if (!$builder->is_supported_for_text_generation()) {
            return new WP_Error('aibima_ai_not_configured', __('No text-generation AI provider is configured in WordPress Connectors. Open Settings > Connectors and configure an AI provider first, or use a structured prompt that can be parsed locally.', 'aibill-maker'));
        }

        $result = $builder->generate_text();
        if (is_wp_error($result)) {
            return $result;
        }

        return self::result_to_text($result);
    }

    private static function prompt_builder($prompt) {
        try {
            return wp_ai_client_prompt()
                ->with_text(self::limit_text($prompt, 6000))
                ->using_temperature(0.1);
        } catch (Exception $exception) {
            return new WP_Error('aibima_ai_prompt_error', $exception->getMessage());
        }
    }

    private static function full_prompt($prompt) {
        return "You are AiBill Maker, an invoice extraction assistant. Convert the user's invoice prompt into a clean invoice JSON object. Return JSON only, no markdown. Never invent GSTIN, phone, email, address, HSN/SAC, or bank details. If unknown, use empty string. Use ISO currency codes like INR, USD, EUR, GBP. Dates must be YYYY-MM-DD. Today is " . current_time('Y-m-d') . ".\n\n" . self::schema_instruction() . "\n\nUser invoice prompt:\n" . self::limit_text($prompt, 4000);
    }

    private static function schema_instruction() {
        return 'Return a JSON object using this shape: {' .
            '"invoice_type":"invoice",' .
            '"currency":"INR",' .
            '"issue_date":"YYYY-MM-DD",' .
            '"due_date":"YYYY-MM-DD",' .
            '"tax_component":"cgst_sgst|igst|none",' .
            '"tax_inclusive":false,' .
            '"customer":{"name":"","email":"","phone":"","gstin":"","address":""},' .
            '"business":{"name":"","email":"","phone":"","gstin":"","address":""},' .
            '"items":[{"name":"","description":"","quantity":1,"unit":"pcs","unit_price":0,"tax_rate":18,"tax_inclusive":false,"hsn_sac":""}],' .
            '"notes":"",' .
            '"terms":"",' .
            '"payment_instructions":""' .
            '}. Use numbers for quantity, unit_price, and tax_rate. Extract GST inclusive/exclusive and IGST/CGST/SGST from prompt.';
    }

    private static function result_to_text($result) {
        if (is_string($result)) {
            return $result;
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        if (is_object($result)) {
            foreach (array('get_text', 'getText', 'text', '__toString') as $method) {
                if (method_exists($result, $method)) {
                    $value = $result->{$method}();
                    if (is_string($value) || is_scalar($value)) {
                        return (string) $value;
                    }
                }
            }
        }

        if (is_array($result)) {
            foreach (array('text', 'content', 'output_text') as $key) {
                if (isset($result[$key]) && (is_string($result[$key]) || is_scalar($result[$key]))) {
                    return (string) $result[$key];
                }
            }
        }

        return '';
    }

    private static function extract_json($content) {
        $content = trim((string) $content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }
        return $content;
    }

    private static function limit_text($text, $max) {
        $text = trim((string) $text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }
}
