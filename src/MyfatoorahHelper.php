<?php

namespace Drupal\commerce_myfatoorah_v2;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * Class MyfatoorahHelper
 * Helper class Myfatoorah Lib.
 *
 * @package Drupal\commerce_myfatoorah_v2\PluginForm
 */
class MyfatoorahHelper {

    /**
     * @var \Drupal\commerce_order\Entity\OrderInterface
     */
    private $order;
    private $orderId;
    private $mfVersion = '2.0.0.1';

//---------------------------------------------------------------------------------------------------------------------------------------------------
    /**
     * MyfatoorahHelper constructor.
     *
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     * @param $configuration
     */
    /**
     * MyfatoorahHelper constructor.
     *
     * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
     */
//    public function __construct($payment) {
//
//        //order
//        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
//        $order = $payment->getOrder();
//
//        // Payment data. //?????????
//        // $data['variables[payment_gateway]'] = $payment->getPaymentGatewayId();
//
//        $this->order   = $order;
//        $this->orderId = $order->id();
//
//        // Payment gateway configuration data.
//        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
//        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
//        $configuration          = $payment_gateway_plugin->getConfiguration();
//
//        $this->apiKey     = $configuration['api_key'];
//        $this->gatewayUrl = ($configuration['mode'] == 'test') ? 'https://apitest.myfatoorah.com' : 'https://api.myfatoorah.com';
//        $this->pluginlog  = drupal_get_path('module', 'commerce_myfatoorah_v2') . '/myfatoorah.log';
//    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    public function __construct(OrderInterface $order, $configuration) {

        $this->order   = $order;
        $this->orderId = $order->id();

        $this->apiKey     = $configuration['api_key'];
        $this->gatewayUrl = ($configuration['mode'] == 'test') ? 'https://apitest.myfatoorah.com' : 'https://api.myfatoorah.com';
        $this->pluginlog  = drupal_get_path('module', 'commerce_myfatoorah_v2') . '/myfatoorah.log';
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    //PaymentId, InvoiceId
    public function refund($keyId, $amount) {

        $url = $this->gatewayUrl . '/v2/MakeRefund';

        $curlData = [
            'KeyType'                 => 'PaymentId',
            'Key'                     => $keyId,
            'RefundChargeOnCustomer'  => false,
            'ServiceChargeOnCustomer' => false,
            'Amount'                  => $amount,
            'Comment'                 => 'Refund',
        ];

        $this->callAPI($url, $curlData, $this->orderId, 'Make Refund');
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    //PaymentId, InvoiceId
    public function getPaymentStatus($keyId) {

        $url = $this->gatewayUrl . '/v2/GetPaymentStatus';

        $curlData = [
            'Key'     => $keyId,
            'KeyType' => 'PaymentId'
        ];

        $json = $this->callAPI($url, $curlData, $this->orderId, 'Get Payment Status');

        $price       = $this->order->getTotalPrice();
        $pricePieces = explode(' ', $json->Data->InvoiceDisplayValue);

        if ($json->Data->CustomerReference != $this->orderId) { //confirm same order
            $err = 'Trying to call data of another order.';
        } else if ($price->getNumber() != floatval(preg_replace('/[^\d.]/', '', $pricePieces[0]))) { //confirm same price
            $err = 'Charged amount not equal to order amount.';
//        } else if ($price->getCurrencyCode() != $pricePieces[1]) { //confirm same currency
//            $err = 'The provided prices have mismatched currencies: ' . $price->getNumber() . ' ' . $price->getCurrencyCode() . ', ' . $json->Data->InvoiceDisplayValue;
        } else if ($json->Data->InvoiceStatus != 'Paid') {

            //payment is pending .. user has not paid yet and the invoice is not expired
            $err = 'Pending Payment';

            $lastInvoiceTransactions = end($json->Data->InvoiceTransactions);
            if ($lastInvoiceTransactions && $lastInvoiceTransactions->Error) {
                $err = $lastInvoiceTransactions->Error;
            } else {

                //all myfatoorah gateway is set to Asia/Kuwait
                $ExpiryDate  = new \DateTime($json->Data->ExpiryDate, new \DateTimeZone('Asia/Kuwait'));
                $ExpiryDate->modify('+1 day'); ///????????????$ExpiryDate without any hour so for i added the 1 day just in case. this should be changed after adding the tome to the expire date
                $currentDate = new \DateTime('now', new \DateTimeZone('Asia/Kuwait'));

                $err = ($ExpiryDate < $currentDate) ? 'Invoice is expired since: ' . $ExpiryDate->format('Y-m-d') : '';
            }
        }

        if ($err) {
            $msgLog = "Order #$this->orderId ----- Get Payment Status";

            error_log("$msgLog - Result: Failed with Error: $err", 3, $this->pluginlog);
            throw new PaymentGatewayException($err);
        }
        return $json;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    public function getInvoiceUrl($form) {

        $curlData = $this->getPayload($form);

        $gateway = 'myfatoorah';
        if ($gateway == 'myfatoorah' || $gateway == null || $gateway == 'undefined') {
            return $this->sendPayment($curlData);
        } else {
            return $this->executePayment($curlData, $gateway);
        }
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    public function getPayload($form) {

        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order   = $this->order;
        $orderId = $this->orderId;
        $amount  = $order->getTotalPrice()->getNumber();

        //billing address
        $billingAddress = $order->getBillingProfile()->get('address')->first();
        $country_list   = \Drupal::service('address.country_repository')->getList();
        $country        = $country_list[$billingAddress->getCountryCode()];

        //phone
        //$phone = $this->getPhone($billingAddress->getData('telephone'));
        $phone = '';

        //expiryDate
        //$lifeTime   = $this->getPendingOrderLifetime();
        //$KuwaitDate = new \DateTime('now', new \DateTimeZone('Asia/Kuwait')); //all myfatoorah gateway is set to Asia/Kuwait
        //$KuwaitDate->modify("+$lifeTime minutes");
        $expiryDate = ''; //$KuwaitDate->format('Y-m-d\TH:i:s');

        $data = [
            'CustomerName'       => $billingAddress->getGivenName() . ' ' . $billingAddress->getFamilyName(),
            'DisplayCurrencyIso' => $order->getTotalPrice()->getCurrencyCode(),
            'MobileCountryCode'  => '', //trim($phone[0]),
            'CustomerMobile'     => '', //trim($phone[1]),
            'CustomerEmail'      => $order->getEmail(),
            'InvoiceValue'       => $amount,
            'CallBackUrl'        => $form['#return_url'],
            'ErrorUrl'           => $form['#cancel_url'],
            'Language'           => \Drupal::languageManager()->getCurrentLanguage()->getId(),
            'CustomerReference'  => $orderId,
            'CustomerCivilId'    => $orderId,
            'UserDefinedField'   => 'Custom field',
            'ExpiryDate'         => $expiryDate,
            'SourceInfo'         => 'Drupal ' . \Drupal::VERSION . ' - myfatoorah_v2 ' . $this->mfVersion,
//            'CustomerAddress'    => [
//                'Block'               => $billingAddress->getAddressLine1(),
//                'Street'              => $billingAddress->getAddressLine2(),
//                'HouseBuildingNo'     => '',
//                'Address'             => $billingAddress->getLocality() . ', ' . $billingAddress->getAdministrativeArea() . ', ' . $billingAddress->getPostalCode() . ', ' . $country,
//                'AddressInstructions' => ''
//            ],
            'InvoiceItems'       => [array('ItemName' => 'Total Amount Order #' . $orderId, 'Quantity' => 1, 'UnitPrice' => $amount)]
                //'InvoiceItems'       => $this->getInvoiceItems($order)
        ];

        return $data;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    private function getInvoiceItems($order) {

        //items
//        $currencyRate = (double) $this->objectManager->create('Magento\Store\Model\StoreManagerInterface')->getStore()->getCurrentCurrencyRate();
        $items = $order->getItems();
        foreach ($items as $item) {
            $product_name      = $item->getTitle();
//            $itemPrice    = $item->getUnitPrice()->getNumber() * $currencyRate;
            $itemPrice         = $item->getUnitPrice()->getNumber(); //$item->getTotalPrice()
            $qty               = $item->getQuantity();
            $invoiceItemsArr[] = array('ItemName' => $product_name, 'Quantity' => intval($qty), 'UnitPrice' => $itemPrice);
        }

//        //shipping
//        $shipping = $order->getShippingItems() + $order->getShippingTaxAmount();
//        if ($shipping != '0') {
//            $invoiceItemsArr[] = array('ItemName' => 'Shipping Amount', 'Quantity' => 1, 'UnitPrice' => $shipping);
//        }
//        //discount
//        $discount = $order->getDiscountAmount();
//        if ($discount != '0') {
//            $invoiceItemsArr[] = array('ItemName' => 'Discount Amount', 'Quantity' => 1, 'UnitPrice' => $discount);
//            //            $amount = $amount + $discount;
//        }
//        error_log(print_r($invoiceItemsArr, 1));

        return $invoiceItemsArr;
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /*
     * Matching regular expression pattern: ^(?:(\+)|(00)|(\\*)|())[0-9]{3,14}((\\#)|())$
     * if (!preg_match('/^(?:(\+)|(00)|(\\*)|())[0-9]{3,14}((\\#)|())$/iD', $inputString))
     * String length: inclusive between 0 and 11
     */
    private function getPhone($inputString) {

        //remove any arabic digit
        $newNumbers = range(0, 9);

        $persianDecimal = array('&#1776;', '&#1777;', '&#1778;', '&#1779;', '&#1780;', '&#1781;', '&#1782;', '&#1783;', '&#1784;', '&#1785;'); // 1. Persian HTML decimal
        $arabicDecimal  = array('&#1632;', '&#1633;', '&#1634;', '&#1635;', '&#1636;', '&#1637;', '&#1638;', '&#1639;', '&#1640;', '&#1641;'); // 2. Arabic HTML decimal
        $arabic         = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'); // 3. Arabic Numeric
        $persian        = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'); // 4. Persian Numeric

        $string0 = str_replace($persianDecimal, $newNumbers, $inputString);
        $string1 = str_replace($arabicDecimal, $newNumbers, $string0);
        $string2 = str_replace($arabic, $newNumbers, $string1);
        $string3 = str_replace($persian, $newNumbers, $string2);

        //Keep Only digits
        $string4 = preg_replace('/[^0-9]/', '', $string3);

        //remove 00 at start
        if (strpos($string4, '00') === 0) {
            $string4 = substr($string4, 2);
        }

        //$this->log->info($string4);
        //check for the allowed length
        $len = strlen($string4);
        if ($len < 3 || $len > 14) {
            throw new \Exception('Phone Number lenght must be between 3 to 14 digits');
        }

        //get the phone arr
        if (strlen(substr($string4, 3)) > 3) {
            return [
                substr($string4, 0, 3),
                substr($string4, 3)
            ];
        } else {
            return [
                '',
                $string4
            ];
        }
        ///end here with return $arr
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    public function sendPayment($curlData) {

        $curlData['NotificationOption'] = 'Lnk';

        $json = $this->callAPI("$this->gatewayUrl/v2/SendPayment", $curlData, $this->orderId, 'Send Payment');

        return $json->Data->InvoiceURL;
//        return array('url' => $json->Data->InvoiceURL, 'InvoiceId' => $json->Data->InvoiceId);
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    public function executePayment($curlData, $gateway) {

        $curlData['PaymentMethodId'] = $this->getPaymentMethodId($curlData, $gateway);

        $json = $this->callAPI("$this->gatewayUrl/v2/ExecutePayment", $curlData, $this->orderId, 'Execute Payment');

        return $json->Data->PaymentURL;
//        return array('url' => $json->Data->PaymentURL, 'InvoiceId' => $json->Data->InvoiceId);
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    private function getPaymentMethodId($curlData, $gateway) {
        $postFields = [
            'InvoiceAmount' => $curlData['InvoiceValue'],
            'CurrencyIso'   => $curlData['DisplayCurrencyIso'],
        ];

// initiate payment
        $json = $this->callAPI("$this->gatewayUrl/v2/InitiatePayment", $postFields, $this->orderId, 'Initiate Payment');

// execute payment
///??????????? why PaymentMethodCode vs PaymentMethodEn
//    ???? null    The PaymentMethodId field is required.
//check for null ??????????????
        $PaymentMethodId = null;
        foreach ($json->Data->PaymentMethods as $value) {
            if ($value->PaymentMethodCode == $gateway) {
                $PaymentMethodId = $value->PaymentMethodId;
                break;
            }
        }

        return $PaymentMethodId;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    private function callAPI($url, $postFields, $orderId, $function) {

        $fields = json_encode($postFields);

        $msgLog = PHP_EOL . date('d.m.Y h:i:s') . ' | ' . "Order #$orderId ----- $function";

        //$this->log->info("$msgLog - Request: $fields");
        error_log("$msgLog - Request: $fields", 3, $this->pluginlog);

        //***************************************
        //call url
        //***************************************
        $curl = curl_init($url);

        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_HTTPHEADER     => array("Authorization: Bearer $this->apiKey", 'Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
        ));

        $res = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        //$this->log->info("$msgLog - Response: $res");
        error_log("$msgLog - Response: $res", 3, $this->pluginlog);

        //***************************************
        //check for errors
        //***************************************
        //example set a local ip to host apitest.myfatoorah.com
        if ($err) {
            //$this->log->info("$msgLog - cURL Error: $err");
            error_log("$msgLog - cURL Error: $err", 3, $this->pluginlog);
            throw new PaymentGatewayException($err);
        }

        $json = json_decode($res);
        if (!isset($json->IsSuccess) || $json->IsSuccess == null || $json->IsSuccess == false) {

            //check for the error insde the object Please tell the exact postion and dont use else
            if (isset($json->ValidationErrors)) {
                $err = implode(', ', array_column($json->ValidationErrors, 'Error'));
            } else if (isset($json->Data->ErrorMessage)) {
                $err = $json->Data->ErrorMessage;
            }

            //if not get the message. this is due that sometimes errors with ValidationErrors has Error value null so either get the "Name" key or get the "Message"
            //example {"IsSuccess":false,"Message":"Invalid data","ValidationErrors":[{"Name":"invoiceCreate.InvoiceItems","Error":""}],"Data":null}
            //example {"Message":"No HTTP resource was found that matches the request URI 'https://apitest.myfatoorah.com/v2/SendPayment222'.","MessageDetail":"No route providing a controller name was found to match request URI 'https://apitest.myfatoorah.com/v2/SendPayment222'"}
            if (empty($err)) {
                $err = (isset($json->Message)) ? $json->Message : __('Transaction failed with unknown error.');
            }

            //$this->log->info("$msgLog - Error: $err");
            error_log("$msgLog - Error: $err", 3, $this->pluginlog);
            throw new PaymentGatewayException($err);
        }


        //***************************************
        //Success 
        //***************************************
        return $json;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Information about all supported cards.
     * 
     * @return array
     *   Array with key as card ID and value as English name.
     */
    public static function getCards() {
        return[
            'myfatoorah' => 'MyFatoorah',
            'kn'         => 'Knet',
            'vm'         => 'Visa / Master Card',
            'md'         => 'Mada KSA',
            'b'          => 'Benefit',
            'np'         => 'Qatar Debit Card - NAPS',
            'uaecc'      => 'Debit Cards UAE - VISA UAE',
            's'          => 'Sadad',
            'ae'         => 'AMEX',
            'ap'         => 'Apple Pay',
            'kf'         => 'KFast',
            'af'         => 'AFS',
            'stc'        => 'STC Pay',
        ];
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
}
