<?php
/**
 * payfast.php
 *
 *  Copyright (c) 2025 Payfast (Pty) Ltd
 *
 *  Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * @author     Payfast (Pty) Ltd
 * @version    1.1.0
 */

use Tygh\Http;
use Tygh\Registry;
use Payfast\PayfastCommon\Aggregator\Request\PaymentRequest;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}


require_once __DIR__ . '/../vendor/autoload.php';

// Return from payfast website
if (defined('PAYMENT_NOTIFICATION')) {
    if (empty($processor_data)) {
        $order_id       = $_GET['order_id'];
        $order_id       = filter_var($order_id, FILTER_SANITIZE_NUMBER_INT);
        $order_info     = $order_id ? fn_get_order_info($order_id) : null;
        $processor_data = $order_info['payment_id'] ?? null ? fn_get_processor_data($order_info['payment_id']) : null;
    }
    $payfast_merchant_id  = $processor_data['processor_params']['merchant_id'];
    $payfast_merchant_key = $processor_data['processor_params']['merchant_key'];

    $pfHost = $processor_data['processor_params']['mode'] == 'sandbox' ? "sandbox.payfast.co.za" : "www.payfast.co.za";

    $passphrase = !empty($processor_data['processor_params']['passphrase']) ? $processor_data['processor_params']['passphrase'] : null;

    $testmode       = $processor_data['processor_params']['mode'] == 'sandbox';
    $payfastRequest = new PaymentRequest($testmode);

    define('PF_DEBUG', (bool)$processor_data['processor_params']['debug']);

    /** @noinspection PhpUndefinedVariableInspection */
    if ($mode == 'notify' && !empty($_REQUEST['order_id'])) {
        if (fn_check_payment_script('payfast.php', $_POST['m_payment_id'], $processor_data)) {
            $pp_response      = array();
            $payfast_statuses = fn_get_simple_statuses();
            $pfError          = false;
            $order_id         = $_REQUEST['order_id'];
            $pfErrMsg         = '';
            $pfDone           = false;
            $pfData           = array();
            $pfParamString    = '';
            $payfastRequest->pflog($pfHost);
            $payfastRequest->pflog('Payfast ITN call received');

            // Notify Payfast that information has been received
            if (!$pfError && !$pfDone) {
                header('HTTP/1.0 200 OK');
                flush();

                //If order is not new die and notify Payfast
                if ($order_info["status"] != "N") {
                    $payfastRequest->pflog('Order status ' . $order_info["status"] . ' die');
                    die;
                }
            }

            // Get data sent by Payfast
            if (!$pfError && !$pfDone) {
                $payfastRequest->pflog('Get posted data');

                // Posted variables from ITN
                $pfData = $payfastRequest->pfGetData();

                $payfastRequest->pflog('Payfast Data: ' . print_r($pfData, true));

                if ($pfData === false) {
                    $pfError  = true;
                    $pfErrMsg = $payfastRequest::PF_ERR_BAD_ACCESS;
                }
            }

            // Verify security signature
            if (!$pfError && !$pfDone) {
                $payfastRequest->pflog('Verify security signature');

                // If signature different, log for debugging
                if (!$payfastRequest->pfValidSignature($pfData, $pfParamString, $passphrase)) {
                    $pfError  = true;
                    $pfErrMsg = $payfastRequest::PF_ERR_INVALID_SIGNATURE;
                }
            }

            // Verify source IP (If not in debug mode)
            if (!$pfError && !$pfDone && !PF_DEBUG) {
                $payfastRequest->pflog('Verify source IP');

                if (!$payfastRequest->pfValidIP($_SERVER['REMOTE_ADDR'])) {
                    $pfError  = true;
                    $pfErrMsg = $payfastRequest::PF_ERR_BAD_SOURCE_IP;
                }
            }
            // Get internal cart
            if (!$pfError && !$pfDone) {
                $payfastRequest->pflog("Purchase:\n" . print_r($order_info, true));
            }

            // Verify data received
            if (!$pfError) {
                $payfastRequest->pflog('Verify data received');
                $moduleInfo = [
                    "pfSoftwareName"       => 'CSCart',
                    "pfSoftwareVer"        => "4.18.3",
                    "pfSoftwareModuleName" => 'Payfast-CSCart4',
                    "pfModuleVer"          => '1.1.0',
                ];

                $pfValid = $payfastRequest->pfValidData($moduleInfo, $pfHost, $pfParamString);

                if (!$pfValid) {
                    $pfError  = true;
                    $pfErrMsg = $payfastRequest::PF_ERR_BAD_ACCESS;
                }
            }

            // Check data against internal order & Check order amount
            if (!$pfError && !$pfDone && (!$payfastRequest->pfAmountsEqual(
                    $pfData['amount_gross'],
                    fn_format_price(
                        $order_info['total'],
                        $processor_data['processor_params']['currency']
                    )
                ))) {
                $pfError  = true;
                $pfErrMsg = $payfastRequest::PF_ERR_AMOUNT_MISMATCH;
            }

            // Check status and update order
            if (!$pfError && !$pfDone) {
                $payfastRequest->pflog('Check status and update order');


                $transaction_id = $pfData['pf_payment_id'];

                switch ($pfData['payment_status']) {
                    case 'COMPLETE':
                        $payfastRequest->pflog('- Complete');
                        $pp_response['order_status'] = $payfast_statuses['C'];
                        break;

                    case 'FAILED':
                        $payfastRequest->pflog('- Failed');
                        $pp_response['order_status'] = $payfast_statuses['F'];
                        break;

                    case 'PENDING':
                        $payfastRequest->pflog('- Pending');
                        $pp_response['order_status'] = $payfast_statuses['O'];
                        break;

                    default:
                        // If unknown status, do nothing (safest course of action)
                        $payfastRequest->pflog('- Unknown status');
                        break;
                }


                $pp_response['reason_text']    = $pfData['payment_status'];
                $pp_response['transaction_id'] = $transaction_id;
                $pp_response['customer_email'] = $pfData['email_address'];

                if ($pp_response['order_status'] == 'pending') {
                    fn_change_order_status($order_id, $pp_response['order_status']);
                } else {
                    fn_finish_payment($order_id, $pp_response);
                }
            }
        }
        exit;
    } elseif ($mode == 'return') {
        $order_id = $_REQUEST['order_id'];
        if (fn_check_payment_script('payfast.php', $order_id)) {
            $order_info = fn_get_order_info($order_id, true);

            /** @noinspection PhpUndefinedConstantInspection */
            if (fn_allowed_for('MULTIVENDOR') && ($order_info['status'] == STATUS_PARENT_ORDER)) {
                $child_orders = db_get_hash_single_array(
                    "SELECT order_id, status FROM ?:orders WHERE parent_order_id = ?i",
                    array('order_id', 'status'),
                    $order_id
                );

                foreach ($child_orders as $order_id => $order_status) {
                    /** @noinspection PhpUndefinedConstantInspection */
                    if ($order_status == STATUS_INCOMPLETED_ORDER) {
                        fn_change_order_status($order_id, 'O', '', false);
                    }
                }
            }
        }
        fn_order_placement_routines('route', $order_id, false);
    } elseif ($mode == 'cancel') {
        $order_id   = $_REQUEST['order_id'];
        $order_info = fn_get_order_info($order_id);

        $pp_response['order_status'] = 'N';
        $pp_response["reason_text"]  = __('text_transaction_cancelled');

        fn_finish_payment($order_id, $pp_response, false);
        fn_order_placement_routines('route', $order_id);
    }
} else {
    if (empty($processor_data)) {
        $order_id_raw   = $_POST['m_payment_id'] ?? null;
        $order_id       = filter_var($order_id_raw, FILTER_SANITIZE_NUMBER_INT);
        $order_info     = $order_id ? fn_get_order_info($order_id) : null;
        $processor_data = $order_info['payment_id'] ?? null ? fn_get_processor_data($order_info['payment_id']) : null;
    }

    $payfast_merchant_id  = $processor_data['processor_params']['merchant_id'];
    $payfast_merchant_key = $processor_data['processor_params']['merchant_key'];

    $pfHost = $processor_data['processor_params']['mode'] == 'sandbox' ? "sandbox.payfast.co.za" : "www.payfast.co.za";

    $passphrase = !empty($processor_data['processor_params']['passphrase']) ? $processor_data['processor_params']['passphrase'] : null;

    $testmode       = $processor_data['processor_params']['mode'] == 'sandbox';
    $payfastRequest = new PaymentRequest($testmode);

    $total        = fn_format_price($order_info['total'], $processor_data['processor_params']['currency']);
    $m_payment_id = $order_info['order_id'];
    /** @noinspection PhpUndefinedConstantInspection */
    $return_url = fn_url("payment_notification.return?payment=payfast&order_id=$m_payment_id", AREA, 'current');
    /** @noinspection PhpUndefinedConstantInspection */
    $cancel_url = fn_url("payment_notification.cancel?payment=payfast&order_id=$m_payment_id", AREA, 'current');
    /** @noinspection PhpUndefinedConstantInspection */
    $notify_url = fn_url("payment_notification.notify?payment=payfast&order_id=$m_payment_id", AREA, 'current');

    $payArray = array(
        'merchant_id'      => $payfast_merchant_id,
        'merchant_key'     => $payfast_merchant_key,
        'return_url'       => $return_url,
        'cancel_url'       => $cancel_url,
        'notify_url'       => $notify_url,
        'name_first'       => $order_info['b_firstname'],
        'name_last'        => $order_info['b_lastname'],
        'email_address'    => $order_info['email'],
        'm_payment_id'     => $m_payment_id,
        'amount'           => $total,
        'item_name'        => __('text_payfast_item_name') . ' - ' . $order_info['order_id'],
        'item_description' => __('total_product_cost')
    );
    echo <<<HTML
<p>Kindly wait while you're redirected to Payfast ...</p>
HTML;

    $payfastRequest->createTransaction($payArray, $passphrase, $testmode);
}
exit;
