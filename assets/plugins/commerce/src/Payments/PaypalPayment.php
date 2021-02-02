<?php

namespace Commerce\Payments;

use Commerce\Interfaces\Payment as PaymentInterface;
use Exception;

class PaypalPayment extends Payment implements PaymentInterface
{
    protected $token;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('paypal');
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('client_id')) || empty($this->getSetting('client_secret'))) {
            return '<span class="error" style="color: red;">' . $this->lang['paypal.error.empty_client_credentials'] . '</span>';
        }

        return '';
    }

    public function getPaymentLink()
    {
        $debug = !empty($this->getSetting('debug'));

        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], $order['amount']);

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $order['currency'],
                        'value'         => $payment['amount'],
                        'breakdown'     => [
                            'item_total' => [
                                'currency_code' => $order['currency'],
                                'value'         => $payment['amount'],
                            ],
                        ],
                    ],
                    'description' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                        'order_id'  => $order['id'],
                        'site_name' => $this->modx->getConfig('site_name'),
                    ]),
                    'custom_id' => $payment['id'],
                    'invoice_id' => $order['id'],
                ],
            ],
            'application_context' => [
                'user_action' => 'PAY_NOW',
                'return_url'  => $this->modx->getConfig('site_url') . 'commerce/paypal/payment-process?' . http_build_query(['paymentHash' => $payment['hash']]),
                'cancel_url'  => $this->modx->getConfig('site_url') . 'commerce/paypal/payment-failed',
            ],
        ];

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $data['payer'] = [
                'email_address' => $order['email'],
            ];
        }

        $items = $this->prepareItems($processor->getCart());
        $vat_code = $this->getSetting('vat_code');

        $isPartialPayment = $payment['amount'] < $order['amount'];

        if ($isPartialPayment) {
            $items = $this->decreaseItemsAmount($items, $order['amount'], $payment['amount']);
        }

        $products = [];

        foreach ($items as $i => $item) {
            $products[] = [
                'name'     => mb_substr($item['name'], 0, 127),
                'quantity' => (int) $item['count'],
                'sku'      => $item['id'],
                'unit_amount' => [
                    'currency_code' => $order['currency'],
                    'value'         => $item['price'],
                ],
            ];
        }

        $data['purchase_units'][0]['items'] = $products;

        $response = $this->request('v2/checkout/orders', $data);

        if (!empty($response->id) && !empty($response->links)) {
            ci()->db->update(['original_order_id' => $response->id], $this->modx->getFullTablename('commerce_order_payments'), "`id` = '{$payment['id']}'");

            foreach ($response->links as $link) {
                if ($link->rel == 'approve') {
                    return $link->href;
                }
            }
        }

        return false;
    }

    public function handleCallback()
    {
        if (!isset($_GET['token']) || !is_string($_GET['token']) || !preg_match('/^[A-Z0-9]+$/', $_GET['token'])) {
            return false;
        }

        if (!isset($_GET['paymentHash']) || !is_string($_GET['paymentHash']) || !preg_match('/^[a-z0-9]+$/', $_GET['paymentHash'])) {
            return false;
        }

        if ($this->getSetting('debug')) {
            $this->modx->logEvent(0, 1, htmlentities(print_r($_GET, true)), 'Commerce PayPal Payment');
        }

        try {
            $response = $this->request('v2/checkout/orders/' . $_GET['token'] . '/capture');
        } catch (Exception $e) {
            $this->modx->logEvent(0, 3, 'Order status request failed: ' . $e->getMessage(), 'Commerce PayPal Payment');
            return false;
        }

        if (!empty($response->status) && $response->status == 'COMPLETED') {
            $amount = 0;

            if (!empty($response->purchase_units[0]->payments->captures)) {
                foreach ($response->purchase_units[0]->payments->captures as $capture) {
                    $amount += $capture->amount->value;
                }
            }

            $processor = $this->modx->commerce->loadProcessor();

            try {
                $payment = $processor->loadPaymentByHash($_GET['paymentHash']);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($_GET['paymentHash'], true)) . '" . not found!');
                }

                $processor->processPayment($payment, $amount);
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce PayPal Payment');
                return false;
            }

            $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/paypal/payment-success?paymentHash=' . $_REQUEST['paymentHash']);
        }

        return false;
    }

    public function getRequestPaymentHash()
    {
        if (isset($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }

    protected function request($method, $data = [], $curlParams = [], $headers = [])
    {
        $debug = $this->getSetting('debug');
        $url = $debug ? 'https://api.sandbox.paypal.com' : 'https://api.paypal.com';

        $headers = [
            'Content-Type: application/json',
        ];

        $ch = curl_init();

        if (is_null($this->token)) {
            if ($method == 'v1/oauth2/token') {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $this->getSetting('client_id') . ':' . $this->getSetting('client_secret'));

                $headers = [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ];
            } else {
                $this->token = $this->getToken();
            }
        }

        if (!is_null($this->token)) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $url .= '/' . $method;

        if (is_array($data) && !empty($data)) {
            $data = json_encode($data, JSON_PRETTY_PRINT);
        }

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_POST, true);

        $response = curl_exec($ch);

        if ($debug) {
            $this->modx->logEvent(0, 1, "URL: <pre>$url</pre>\n\Headers: <pre>" . htmlentities(print_r($headers, true)) . "</pre>\n\nRequest data: <pre>" . htmlentities(print_r($data, true)) . "</pre>\n\nResponse data: <pre>" . htmlentities(print_r($response, true)) . "</pre>" . (curl_errno($ch) ? "\n\nError: <pre>" . htmlentities(curl_error($ch)) . "</pre>" : ''), 'Commerce PayPal Payment Debug: request');
        }

        curl_close($ch);
        return json_decode($response);
    }

    protected function getToken()
    {
        $response = $this->request('v1/oauth2/token', 'grant_type=client_credentials');
        return $response->access_token ?: false;
    }
}
