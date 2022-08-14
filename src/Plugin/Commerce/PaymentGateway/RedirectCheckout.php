<?php

namespace Drupal\myfatoorah_drupal_commerce\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_payment\Entity\PaymentInterface;
use MyFatoorah\Library\MyfatoorahApiV2;
use MyFatoorah\Library\PaymentMyfatoorahApiV2;

/**
 * Provides the MyFatoorah offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "myfatoorah_drupal_commerce",
 *   label = @Translation("MyFatoorah (Redirect to MyFatoorah)"),
 *   display_label = @Translation("MyFatoorah"),
 *   forms = {
 *     "offsite-payment" = "Drupal\myfatoorah_drupal_commerce\PluginForm\RedirectCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa", "Knet", 
 *   },
 * )
 */
//     "MyFatoorah", "Knet", "Visa / Master Card", "Mada KSA", "Benefit", "Qatar Debit Card - NAPS", "Debit Cards UAE - VISA UAE", "Sadad", "AMEX", "Apple Pay", "KFast", "AFS", "STC Pay",
//     "myfatoorah", "kn", "vm", "md", "b", "np", "uaecc", "s", "ae", "ap", "kf", "af", "stc",
//"refund-payment" = "Drupal\myfatoorah_drupal_commerce\PluginForm\RefundForm",
class RedirectCheckout extends OffsitePaymentGatewayBase {
//---------------------------------------------------------------------------------------------------------------------------------------------------
//    public $entityId;

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration() {

        return [
            'countryCode'      => 'KWT',
            'apiKey'           => '',
            'webhookSecretKey' => '',
            'saveCard'         => 0,
//            'enabled_cards' => '',
//            'order_status'  => '',
                ] +
                parent::defaultConfiguration();
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form1, FormStateInterface $form_state) {
        $form = parent::buildConfigurationForm($form1, $form_state);

        //lookup
        $codes     = [];
        $countries = MyfatoorahApiV2::getMyFatoorahCountries();
        if (is_array($countries)) {
            $lang      = \Drupal::languageManager()->getCurrentLanguage()->getId();
            $nameIndex = 'countryName' . ucfirst($lang);
            foreach ($countries as $key => $obj) {
                $codes[$key] = $obj[$nameIndex];
            }
        }

        $form['countryCode'] = [
            '#id'            => 'myfatoorah-countryCode',
            '#type'          => 'select',
            '#title'         => $this->t("Vendor's Country"),
            '#description'   => $this->t("Select your MyFatoorah vendor's country. After that, use the API token key that belongs to this country."),
            '#default_value' => $this->configuration['countryCode'],
            '#required'      => true,
            '#options'       => $codes,
        ];

        $form['apiKey'] = [
            '#id'            => 'myfatoorah-apiKey',
            '#type'          => 'textarea',
            '#title'         => $this->t('API Token Key'),
            '#description'   => $this->t('The Generated API Token Key.'),
            '#default_value' => $this->configuration['apiKey'],
            '#required'      => true,
            '#attributes'    => array('style' => 'width:700px; height:200px'),
        ];

        $form['webhookSecretKey'] = [
            '#id'            => 'myfatoorah-webhookSecretKey',
            '#type'          => 'textfield',
            '#title'         => $this->t('Webhook Secret Key'),
            '#description'   => $this->t('Get your Webhook Secret Key from MyFatoorah Vendor Account.'),
            '#default_value' => $this->configuration['webhookSecretKey'],
            '#required'      => true,
            '#attributes'    => array('style' => 'width:700px;'),
        ];

        $form['saveCard'] = [
            '#id'            => 'myfatoorah-saveCard',
            '#type'          => 'checkbox',
            '#title'         => $this->t('Save Card Information'),
            '#description'   => $this->t('This feature allows the customers to save their card details for the future payments.'),
            '#default_value' => $this->configuration['saveCard'],
            '#required'      => false,
        ];

//        $form['webhook_instruction'] = [
//            '#markup' => t(
//                    'Configure MyFatoorah to use the following url for webhooks: @url', ['@url' => $this->entityId]
//            )
//        ];
//        $cards = [];
//        // Add image to the cards where defined.
//        foreach (MyfatoorahHelper::getCards() as $key => $name) {
//            $cards[$key] = "<img src='https://portal.myfatoorah.com/imgs/payment-methods/$key.png'  width='40px' alt='".$this->t($name)."' />";
//        }
//
//        $form['enabled_cards'] = [
//            '#id'            => 'myfatoorah-enabled_cards',
//            '#type'          => 'checkboxes',
//            '#title'         => $this->t('Payment Gateways'),
//            '#description'   => $this->t('Select Enabled Payment Gateways.'),
//            '#default_value' => $this->configuration['enabled_cards'],
//            '#options'       => $cards,
//        ];


        /* $form['enabled_cards'] = [
          '#id'            => 'myfatoorah-enabled_cards',
          '#type'          => 'select',
          '#title'         => $this->t('Payment Gateways'),
          '#description'   => $this->t('Select Enabled Payment Gateways.'),
          '#default_value' => $this->configuration['enabled_cards'],
          '#options'       => MyfatoorahHelper::getCards(),
          '#multiple'      => TRUE,
          '#attributes'    => array('style' => 'background: none; width:300px'),
          ]; */

//        $orderStatuses         = module_invoke_all('commerce_order_status_info');
//        $form['order_status'] = [
//            '#id'            => 'myfatoorah-order_status',
//            '#type'          => 'select',
//            '#title'         => $this->t('Successful Order Status'),
//            '#description'   => $this->t('How to mark the successful payment in the Admin Orders Page.'),
//            '#default_value' => $this->configuration['order_status'],
//            '#options'       => ['processing', 'completed'],
//        ];
        return $form;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

        $values = $form_state->getValue($form['#parents']);

        $mfObj = new PaymentMyfatoorahApiV2($values['apiKey'], $values['countryCode'], ($values['mode'] == 'test'));
        try {
            $paymentMethods = $mfObj->getVendorGateways();
        } catch (\Exception $ex) {
            return $form_state->setError($form['apiKey'], $this->t($ex->getMessage()));
        }

        if (empty($paymentMethods)) {
            $error = 'MyFatoorah: please, contact your account manager to activate at least one of the available payment methods in your account to enable the payment model.';
            return $form_state->setError($form['apiKey'], $this->t($error));
        }
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

        parent::submitConfigurationForm($form, $form_state);

        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);

            $this->configuration['countryCode']      = $values['countryCode'];
            $this->configuration['apiKey']           = $values['apiKey'];
            $this->configuration['saveCard']         = $values['saveCard'];
            $this->configuration['webhookSecretKey'] = $values['webhookSecretKey'];
//            $this->configuration['enabled_cards'] = $values['enabled_cards'];
//            $this->configuration['order_status']  = $values['order_status'];
        }
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    public function onCancel(OrderInterface $order, Request $request) {
        $this->onReturn($order, $request);
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    /*
      //https://www.drupal.org/project/commerce/issues/3017551
      //https://docs.drupalcommerce.org/commerce2/developer-guide/payments/create-payment-gateway/off-site-gateways/handling-ipn
      //http://drupal9.com/payment/notify/{machine_name}
      //webhook
      function onNotify(Request $request) {
      //$callBackUrl = $this->getNotifyUrl()->toString();
      } */
//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * 
     * @param OrderInterface $order
     * @param Request $request
     */
    public function onReturn(OrderInterface $order, Request $request) {

        //draft, completed, canceled, authorization
        $status = $order->getState()->getString();

        //go back if NOT draft, canceled
        if ($status != 'draft' && $status != 'canceled') {
            return;
        }

        try {
            $paymentId = $request->query->get('paymentId');
            $error     = $this->checkStatus($paymentId, 'PaymentId', $order);

            if (!$error) {
                return;
            }
        } catch (\Exception $ex) {
            $error = 'Exception: ' . $ex->getMessage();

            $logStorage = \Drupal::entityTypeManager()->getStorage('commerce_log');
            $logStorage->generate($order, 'commerce_order_admin_comment', ['comment' => 'Callback ' . $error])->save();
        }

        $this->messenger()->addError($this->t($error, [
                    '@gateway' => $this->getDisplayLabel(),
        ]));
        throw new PaymentGatewayException($error);
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    public function checkStatus($keyId, $KeyType, OrderInterface $order, $source = '') {

        $pluginlog = drupal_get_path('module', 'myfatoorah_drupal_commerce') . '/myfatoorah.log';
        $priceObj  = $order->getTotalPrice();

        $mfObj = new PaymentMyfatoorahApiV2($this->configuration['apiKey'], $this->configuration['countryCode'], ($this->getMode() == 'test'), $pluginlog);

        $data = $mfObj->getPaymentStatus($keyId, $KeyType, $order->id(), $priceObj->getNumber(), $priceObj->getCurrencyCode());

        if ($data->InvoiceStatus == 'Paid') {

            $this->createPayment($order, $data->focusTransaction->PaymentId, $data->InvoiceStatus, 'completed');
            $this->addOrderNote($order, $data, $source);
        } else if ($data->InvoiceStatus == 'Failed') {

            $this->createPayment($order, $data->focusTransaction->PaymentId, $data->InvoiceStatus, 'draft');
            $this->addOrderNote($order, $data, $source);
        } else if ($data->InvoiceStatus == 'Expired') {
            $logStorage = \Drupal::entityTypeManager()->getStorage('commerce_log');
            $logStorage->generate($order, 'commerce_order_admin_comment', ['comment' => $this->t('MyFatoorah: ' . $data->InvoiceError)])->save();

            $order_state = $order->getState();
            $order_state->applyTransitionById('cancel');
            $order->save();
        }

        return $data->InvoiceError;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    function createPayment(OrderInterface $order, $paymentId, $mfStatus, $state) {

        /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
        $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

        /** @var PaymentInterface $payment */
        $payment = $paymentStorage->create([
            'state'           => $state, //completed //authorization
            'amount'          => $order->getTotalPrice(),
            'payment_gateway' => $this->entityId,
            'order_id'        => $order->id(),
            'test'            => $this->getMode() == 'test',
            'remote_id'       => $paymentId,
            'remote_state'    => $mfStatus,
        ]);
        $payment->save();
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
    function addOrderNote(OrderInterface $order, $data, $source = '') {

        $note = "MyFatoorah$source Payment Details:\n<br />";

        $note .= 'InvoiceStatus: ' . $data->InvoiceStatus . "\n<br />";
        if ($data->InvoiceStatus == 'Failed') {
            $note .= 'InvoiceError: ' . $data->InvoiceError . "\n<br />";
        }

        $note .= 'InvoiceId: ' . $data->InvoiceId . "\n<br />";
        $note .= 'InvoiceReference: ' . $data->InvoiceReference . "\n<br />";
        $note .= 'InvoiceDisplayValue: ' . $data->InvoiceDisplayValue . "\n<br />";

        //focusTransaction
        $note .= 'PaymentGateway: ' . $data->focusTransaction->PaymentGateway . "\n<br />";
        $note .= 'PaymentId: ' . $data->focusTransaction->PaymentId . "\n<br />";
        $note .= 'ReferenceId: ' . $data->focusTransaction->ReferenceId . "\n<br />";
        $note .= 'TransactionId: ' . $data->focusTransaction->TransactionId . "\n<br />";

        $logStorage = \Drupal::entityTypeManager()->getStorage('commerce_log');
        $logStorage->generate($order, 'commerce_order_admin_comment', ['comment' => $note])->save();
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
//    public function refundPayment(PaymentInterface $payment, \Drupal\commerce_price\Price;Price $inAmount = NULL) {
//
//        $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
//
//        // If not specified, refund the entire amount.
//        $amount = $inAmount ?: $payment->getAmount();
//        $this->assertRefundAmount($payment, $amount);
//
//        // Perform the refund request here, throw an exception if it fails.
//        $remoteId      = $payment->getRemoteId();
//        $decimalAmount = $amount->getNumber();
//
//        // Payment gateway configuration data.
//        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
//        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
//        $configuration          = $payment_gateway_plugin->getConfiguration();
//
//        $mf = new MyfatoorahHelper($payment->getOrder(), $configuration);
//        $mf->refund($remoteId, $decimalAmount);
//
//        // Determine whether payment has been fully or partially refunded.
//        $old_refunded_amount = $payment->getRefundedAmount();
//        $new_refunded_amount = $old_refunded_amount->add($amount);
//        if ($new_refunded_amount->lessThan($payment->getAmount())) {
//            $payment->state = 'partially_refunded';
//            //$payment->setState('partially_refunded');
//        } else {
//            $payment->state = 'refunded';
//            //$payment->setState('refunded');
//        }
//
//        $payment->setRefundedAmount($new_refunded_amount);
//        $payment->save();
//    }
//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
//    public function buildPaymentOperations(PaymentInterface $payment) {
//        $payment_state        = $payment->getState()->getId();
//        $operations           = [];
//        $operations['refund'] = [
//            'title'       => $this->t('Refund'),
//            'page_title'  => $this->t('Refund payment'),
//            'plugin_form' => 'refund-payment',
//            'access'      => in_array($payment_state, ['completed', 'partially_refunded']),
//        ];
//
//        return $operations;
//    }
//---------------------------------------------------------------------------------------------------------------------------------------------------
}
