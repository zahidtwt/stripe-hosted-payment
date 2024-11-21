<?php
class Stripe_Webhook_Handler {
    private $webhook_secret;
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }

    public function register_webhook_endpoint() {
        register_rest_route('stripe-gateway/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook')
        ));
    }

    public function verify_webhook() {
        $gateway = new WC_Stripe_Gateway();
        $this->webhook_secret = $gateway->get_option('webhook_secret');
        return true; // Basic verification, enhance as needed
    }

    public function handle_webhook(WP_REST_Request $request) {
        try {
            $payload = $request->get_body();
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $this->webhook_secret
            );

            switch ($event->type) {
                case 'checkout.session.completed':
                    $session = $event->data->object;
                    $order_id = $session->metadata->order_id;
                    $order = wc_get_order($order_id);
                    
                    if ($order) {
                        $order->payment_complete($session->payment_intent);
                        $order->add_order_note(sprintf(
                            'Stripe payment completed successfully. Payment Intent ID: %s | Amount: %s',
                            $session->payment_intent,
                            wc_price($order->get_total())
                        ));
                    }
                    break;

                case 'charge.refunded':
                    $charge = $event->data->object;
                    $order_id = $charge->metadata->order_id;
                    $order = wc_get_order($order_id);
                    
                    if ($order) {
                        $order->update_status('refunded');
                        $order->add_order_note(sprintf(
                            'Payment refunded via Stripe. Amount: %s | Refund ID: %s | Reason: %s',
                            wc_price($charge->amount_refunded / 100),
                            $charge->id,
                            $charge->refunds->data[0]->reason ?? 'Not specified'
                        ));
                    }
                    break;

                case 'payment_intent.payment_failed':
                    $intent = $event->data->object;
                    $order_id = $intent->metadata->order_id;
                    $order = wc_get_order($order_id);
                    
                    if ($order) {
                        $order->update_status('failed');
                        $order->add_order_note(sprintf(
                            'Stripe payment failed. Error: %s',
                            $intent->last_payment_error ? $intent->last_payment_error->message : 'Unknown error'
                        ));
                    }
                    break;
            }

            return new WP_REST_Response('Webhook handled', 200);

        } catch(\UnexpectedValueException $e) {
            Stripe_Payment_Gateway_Plugin::log('Webhook Error: ' . $e->getMessage());
            return new WP_REST_Response('Invalid payload', 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            Stripe_Payment_Gateway_Plugin::log('Webhook Error: ' . $e->getMessage());
            return new WP_REST_Response('Invalid signature', 400);
        } catch(Exception $e) {
            Stripe_Payment_Gateway_Plugin::log('Webhook Error: ' . $e->getMessage());
            return new WP_REST_Response('Error processing webhook', 500);
        }
    }
}

new Stripe_Webhook_Handler();