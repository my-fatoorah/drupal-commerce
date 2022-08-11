<?php

namespace Drupal\commerce_myfatoorah_v2\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_myfatoorah_v2\MyfatoorahHelper;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Provides the MyFatoorah offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "myfatoorah_v2",
 *   label = @Translation("MyFatoorah (Redirect to MyFatoorah)"),
 *   display_label = @Translation("MyFatoorah"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_myfatoorah_v2\PluginForm\RedirectCheckoutForm",
 *     "refund-payment" = "Drupal\commerce_myfatoorah_v2\PluginForm\RefundForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa", "Knet", 
 *   },
 * )
 */
//     "MyFatoorah", "Knet", "Visa / Master Card", "Mada KSA", "Benefit", "Qatar Debit Card - NAPS", "Debit Cards UAE - VISA UAE", "Sadad", "AMEX", "Apple Pay", "KFast", "AFS", "STC Pay",
//     "myfatoorah", "kn", "vm", "md", "b", "np", "uaecc", "s", "ae", "ap", "kf", "af", "stc",

class RedirectCheckout extends OffsitePaymentGatewayBase {
//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration() {
        return [
            'api_key' => '',
//            'enabled_cards' => '',
//            'order_status'  => '',
                ] +
                parent::defaultConfiguration();
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
        $form1 = parent::buildConfigurationForm($form, $form_state);

        $form1['api_key'] = [
            '#id'            => 'myfatoorah-api_key',
            '#type'          => 'textarea',
            '#title'         => $this->t('API Token Key'),
            '#description'   => $this->t('The Generated API Token Key.'),
            '#default_value' => $this->configuration['api_key'],
            '#required'      => TRUE,
            '#attributes'    => array('style' => 'width:600px'),
        ];

//        $cards = [];
//        // Add image to the cards where defined.
//        foreach (MyfatoorahHelper::getCards() as $key => $name) {
//            $cards[$key] = "<img src='https://portal.myfatoorah.com/imgs/payment-methods/$key.png'  width='40px' alt='".$this->t($name)."' />";
//        }
//
//        $form1['enabled_cards'] = [
//            '#id'            => 'myfatoorah-enabled_cards',
//            '#type'          => 'checkboxes',
//            '#title'         => $this->t('Payment Gateways'),
//            '#description'   => $this->t('Select Enabled Payment Gateways.'),
//            '#default_value' => $this->configuration['enabled_cards'],
//            '#options'       => $cards,
//        ];


        /* $form1['enabled_cards'] = [
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
//        $form1['order_status'] = [
//            '#id'            => 'myfatoorah-order_status',
//            '#type'          => 'select',
//            '#title'         => $this->t('Successful Order Status'),
//            '#description'   => $this->t('How to mark the successful payment in the Admin Orders Page.'),
//            '#default_value' => $this->configuration['order_status'],
//            '#options'       => ['processing', 'completed'],
//        ];
        return $form1;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values                         = $form_state->getValue($form['#parents']);
            $this->configuration['api_key'] = $values['api_key'];
//            $this->configuration['enabled_cards'] = $values['enabled_cards'];
//            $this->configuration['order_status']  = $values['order_status'];
        }
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
//---------------------------------------------------------------------------------------------------------------------------------------------------
//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request) {

        $paymentId = $request->query->get('paymentId');

        $mf = new MyfatoorahHelper($order, $this->configuration);
        $mf->getPaymentStatus($paymentId);

        /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
        $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

        /** @var PaymentInterface $payment */
        $payment = $paymentStorage->create([
            'state'           => 'completed', //completed //authorization
            'amount'          => $order->getTotalPrice(),
            'payment_gateway' => $this->entityId,
            'order_id'        => $order->id(),
            'test'            => $this->getMode() == 'test',
            'remote_id'       => $request->query->get('paymentId'),
            'remote_state'    => 'Paid',
        ]);
        $payment->save();
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function refundPayment(PaymentInterface $payment, Price $inAmount = NULL) {

        $this->assertPaymentState($payment, ['completed', 'partially_refunded']);

        // If not specified, refund the entire amount.
        $amount = $inAmount ?: $payment->getAmount();
        $this->assertRefundAmount($payment, $amount);

        // Perform the refund request here, throw an exception if it fails.
        $remoteId      = $payment->getRemoteId();
        $decimalAmount = $amount->getNumber();

        // Payment gateway configuration data.
        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
        $configuration          = $payment_gateway_plugin->getConfiguration();

        $mf = new MyfatoorahHelper($payment->getOrder(), $configuration);
        $mf->refund($remoteId, $decimalAmount);

        // Determine whether payment has been fully or partially refunded.
        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
            $payment->state = 'partially_refunded';
            //$payment->setState('partially_refunded');
        } else {
            $payment->state = 'refunded';
            //$payment->setState('refunded');
        }

        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function buildPaymentOperations(PaymentInterface $payment) {
        $payment_state        = $payment->getState()->getId();
        $operations           = [];
        $operations['refund'] = [
            'title'       => $this->t('Refund'),
            'page_title'  => $this->t('Refund payment'),
            'plugin_form' => 'refund-payment',
            'access'      => in_array($payment_state, ['completed', 'partially_refunded']),
        ];

        return $operations;
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
}
