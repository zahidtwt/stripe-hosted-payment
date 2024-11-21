<?php
class WC_Stripe_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'stripe';
        $this->icon = STRIPE_PLUGIN_URL . 'assets/images/stripe.png'; // Add your stripe logo
        $this->has_fields = false;
        $this->method_title = 'Stripe';
        $this->method_description = 'Accept payments through Stripe';
        $this->supports = array('products', 'refunds');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->private_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('live_secret_key');
        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('live_publishable_key');
        $this->webhook_secret = $this->get_option('webhook_secret');

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_stripe_webhook', array($this, 'handle_webhook'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'stripe-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Stripe Payment', 'stripe-payment-gateway'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'stripe-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'stripe-payment-gateway'),
                'default' => __('Credit Card (Stripe)', 'stripe-payment-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'stripe-payment-gateway'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'stripe-payment-gateway'),
                'default' => __('Pay with your credit card via Stripe.', 'stripe-payment-gateway')
            ),
            'testmode' => array(
                'title' => __('Test mode', 'stripe-payment-gateway'),
                'label' => __('Enable Test Mode', 'stripe-payment-gateway'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode.', 'stripe-payment-gateway'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'test_publishable_key' => array(
                'title' => __('Test Publishable Key', 'stripe-payment-gateway'),
                'type' => 'text'
            ),
            'test_secret_key' => array(
                'title' => __('Test Secret Key', 'stripe-payment-gateway'),
                'type' => 'password',
            ),
            'live_publishable_key' => array(
                'title' => __('Live Publishable Key', 'stripe-payment-gateway'),
                'type' => 'text'
            ),
            'live_secret_key' => array(
                'title' => __('Live Secret Key', 'stripe-payment-gateway'),
                'type' => 'password'
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'stripe-payment-gateway'),
                'type' => 'password',
                'description' => __('Get this from your Stripe Dashboard when creating a webhook endpoint.', 'stripe-payment-gateway'),
            ),
            'statement_descriptor' => array(
                'title' => __('Statement Descriptor', 'stripe-payment-gateway'),
                'type' => 'text',
                'description' => __('This will appear on your customer\'s credit card statement (22 characters maximum).', 'stripe-payment-gateway'),
                'default' => get_bloginfo('name'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'maxlength' => 22,
                    'pattern' => '[a-zA-Z0-9\s]+' // Only alphanumeric and spaces
                )
            ),
            'statement_descriptor_suffix' => array(
                'title' => __('Statement Descriptor Suffix', 'stripe-payment-gateway'),
                'type' => 'text',
                'description' => __('Additional information that will appear on your customer\'s credit card statement (12 characters maximum).', 'stripe-payment-gateway'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'maxlength' => 12,
                    'pattern' => '[a-zA-Z0-9\s]+' // Only alphanumeric and spaces
                )
            )
        );
    }

    public function process_payment($order_id) {
        global $woocommerce;
        $order = wc_get_order($order_id);

        try {
            // Initialize Stripe
            \Stripe\Stripe::setApiKey($this->private_key);

            // Add note when payment starts
            $order->add_order_note('Customer initiated Stripe payment.');

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower(get_woocommerce_currency()),
                        'product_data' => [
                            'name' => 'Order #' . $order->get_order_number(),
                        ],
                        'unit_amount' => (int)($order->get_total() * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => add_query_arg('order_id', $order->get_id(), $this->get_return_url($order)),
                'cancel_url' => $order->get_cancel_order_url(),
                'metadata' => array(
                    'order_id' => $order->get_id(),
                    'customer_email' => $order->get_billing_email()
                )
            ]);

            // Add note when checkout session is created
            $order->add_order_note(sprintf(
                'Stripe checkout session created (ID: %s). Customer redirected to Stripe.',
                $session->id
            ));

            // Update order meta with Stripe Session ID
            $order->update_meta_data('_stripe_session_id', $session->id);
            $order->save();

            return array(
                'result' => 'success',
                'redirect' => $session->url
            );

        } catch (Exception $e) {
            // Add note about the error
            $order->add_order_note('Stripe payment failed: ' . $e->getMessage());
            wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
            return;
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $payment_intent_id = $order->get_meta('_stripe_payment_intent');

        if (!$payment_intent_id) {
            return new WP_Error('error', __('Stripe Payment Intent ID not found.', 'stripe-payment-gateway'));
        }

        try {
            \Stripe\Stripe::setApiKey($this->private_key);
            $refund = \Stripe\Refund::create([
                'payment_intent' => $payment_intent_id,
                'amount' => $amount * 100,
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'order_id' => $order_id,
                    'reason' => $reason
                ]
            ]);

            if ($refund->status === 'succeeded') {
                $order->add_order_note(
                    sprintf(__('Refunded %s via Stripe - Refund ID: %s', 'stripe-payment-gateway'),
                    wc_price($amount),
                    $refund->id)
                );
                return true;
            }

            return false;

        } catch (Exception $e) {
            Stripe_Payment_Gateway_Plugin::log('Refund Error: ' . $e->getMessage());
            return new WP_Error('error', $e->getMessage());
        }
    }

    /**
     * Generate Admin Form HTML
     * Add custom HTML before the settings form
     */
    public function admin_options() {
        $webhook_url = rest_url('stripe-gateway/v1/webhook');
        ?>
        <h2><?php _e('Stripe Payment Gateway', 'stripe-payment-gateway'); ?></h2>
        
        <?php if ($this->testmode): ?>
            <div class="notice notice-warning">
                <p><?php _e('Test mode is enabled', 'stripe-payment-gateway'); ?></p>
            </div>
        <?php endif; ?>

        <div class="notice notice-info">
            <p><strong><?php _e('Webhook URL:', 'stripe-payment-gateway'); ?></strong></p>
            <p>
                <code style="background:#eee;padding:5px 10px;"><?php echo esc_url($webhook_url); ?></code>
                <button type="button" class="button button-secondary" onclick="copyWebhookUrl()">Copy URL</button>
                <button type="button" class="button button-primary" onclick="testStripeConnection()">Test Connection</button>
            </p>
            <p><?php _e('Add this URL in your Stripe Dashboard under Developers → Webhooks → Add endpoint', 'stripe-payment-gateway'); ?></p>
            <div id="stripe-connection-result"></div>
        </div>

        <script>
        function copyWebhookUrl() {
            const el = document.createElement('textarea');
            el.value = '<?php echo esc_js($webhook_url); ?>';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            alert('Webhook URL copied to clipboard!');
        }

        function testStripeConnection() {
            const resultDiv = document.getElementById('stripe-connection-result');
            resultDiv.innerHTML = '<div class="notice notice-info"><p>Testing connection...</p></div>';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'test_stripe_connection',
                    'nonce': '<?php echo wp_create_nonce('test-stripe-connection'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<div class="notice notice-success"><p>' + data.data.message + '</p></div>';
                } else {
                    resultDiv.innerHTML = '<div class="notice notice-error"><p>Error: ' + data.data.message + '</p></div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>Error: ' + error.message + '</p></div>';
            });
        }
        </script>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
}