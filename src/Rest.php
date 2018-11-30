<?php

namespace CFPP;

use WP_REST_Server;
use WP_REST_Request;
use CFPP\Shipping\ShippingCalculator;
use CFPP\Exceptions\ShippingCalculatorException;

/**
 * Class Rest
 * Handles REST requests and returns a response.
 *
 * @package CFPP
 */
class Rest
{
    /**
     * Register REST routes in WordPress
     */
    public function registerRoutes()
    {
        /**
         * @route calculate
         * @param1 int WC_Product ID
         * @param2 int Destination Postcode
         * @param3 int Quantity (optional, defaults to 1)
         * @param4 string Selected Variation (optional, captures a-z, 0-9 and -)
         */
        register_rest_route('cfpp/v1', '/calculate/(?P<product_id>\d+)/(?P<destination_postcode>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'calculate'],
            'args' => [
                'product_id' => [
                    'validate_callback' => function($product_id) {
                        return wc_get_product($product_id) instanceof \WC_Product;
                    }
                ],
                'destination_postcode' => [
                    'validate_callback' => function($destination_postcode) {
                        return strlen($destination_postcode) === 8;
                    }
                ],
                'quantity' => [
                    'validate_callback' => function($quantity) {
                        return intval($quantity) > 0;
                    },
                    'default' => 1
                ],
                'selected_variation' => [
                    'validate_callback' => function($selected_variation) {
                        return $selected_variation === sanitize_title($selected_variation);
                    },
                    'default' => null
                ]
            ],
            'permission_callback' => function(WP_REST_Request $request) {
                if (current_user_can('manage_woocommerce')) {
                    return true;
                }

                $is_visible = wc_get_product($request['product_id'])->is_visible();
                $password   = get_post($request['product_id'])->post_password;

                return $is_visible && empty($password);
            }
        ]);
    }

    /**
     * Callback for Calculate route
     *
     * Receives a REST Request, creates the payload object and pass it
     * to Costs to calculate it, then send the JSON response
     *
     * @param \WP_REST_Request $request
     */
    public function calculate(WP_REST_Request $request)
    {
        $product              = wc_get_product($request['product_id']);
        $destination_postcode = absint($request['destination_postcode']);
        $quantity             = absint($request['quantity']);
        $selected_variation   = $request['selected_variation'];

        try {
            $shipping = new ShippingCalculator($product, $destination_postcode, $quantity, $selected_variation);
            $response = $shipping->processRestRequest();

            do_action('cfpp_before_send_calculate_success_response', $response);
            wp_send_json_success($response);

        } catch(ShippingCalculatorException $e) {
            /** Generic error catcher */
            do_action('cfpp_exception_shipping_calculator', $e, $request);
            wp_send_json_error($e->getMessage());
        }
    }
}