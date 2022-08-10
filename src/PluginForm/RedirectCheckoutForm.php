<?php

namespace MyFatoorah\DrupalCommerce\PluginForm;

use MyFatoorah\Library\PaymentMyfatoorahApiV2;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

//use Psr\Log\LoggerInterface;

class RedirectCheckoutForm extends BasePaymentOffsiteForm {
//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * The current system version.
     */
    const MF_VERSION = '2.1.0';

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form1, FormStateInterface $form_state) {
        $form = parent::buildConfigurationForm($form1, $form_state);

        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entity;

        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $payment->getOrder();

        // Payment gateway configuration data.
        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
        $configuration          = $payment_gateway_plugin->getConfiguration();

        $pluginlog = drupal_get_path('module', 'myfatoorah_drupal_commerce') . '/myfatoorah.log';

        $curlData = $this->getPayload($order, $configuration);

        $mfObj = new PaymentMyfatoorahApiV2($configuration['apiKey'], $configuration['countryCode'], $configuration['mode'], $pluginlog);

        try {
            $invoice = $mfObj->getInvoiceURL($curlData, 0, $order->id());

            $comment    = 'MyFatoorah invoice id is ' . $invoice['invoiceId'] . ' and its URL is ' . $invoice['invoiceURL'];
            $logStorage = \Drupal::entityTypeManager()->getStorage('commerce_log');
            $logStorage->generate($order, 'commerce_order_admin_comment', ['comment' => $comment])->save();

            return $this->buildRedirectForm(
                            $form,
                            $form_state,
                            $invoice['invoiceURL'],
                            [],
                            self::REDIRECT_POST
            );
        } catch (\Exception $ex) {
            $error = 'Exception: ' . $ex->getMessage();

            $logStorage = \Drupal::entityTypeManager()->getStorage('commerce_log');
            $logStorage->generate($order, 'commerce_order_admin_comment', ['comment' => 'Invoice ' . $error])->save();

            $payment_gateway_plugin->messenger()->addError($this->t($error, [
                        '@gateway' => $payment_gateway_plugin->getDisplayLabel(),
            ]));
        }
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * 
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     * @param array $configuration
     * 
     * @return array
     */
    public function getPayload($order, $configuration) {

        $orderId = $order->id();

        $email = $order->getEmail();

        $priceObj = $order->getTotalPrice();
        $amount   = $priceObj->getNumber();
        $currency = $priceObj->getCurrencyCode();

        //$form['#return_url']; //$form['#cancel_url'];
        $callBackUrlObj = Url::fromUri('route:myfatoorah_drupal_commerce.callback', ['query' => ['oid' => base64_encode($orderId)]]);
        $callBackUrlObj->setAbsolute();
        $callBackUrl    = $callBackUrlObj->toString();

        //UserDefinedField
        $userId           = \Drupal::currentUser()->id();
        $userDefinedField = ($userId && $configuration['saveCard']) ? 'CK-' . $userId : null;

        //billing address
        $billingAddress = $order->getBillingProfile()->get('address')->first();
        $country_list   = \Drupal::service('address.country_repository')->getList();
        $country        = $country_list[$billingAddress->getCountryCode()];

        $customerAddress = [
            'Block'               => null,
            'Street'              => null,
            'HouseBuildingNo'     => null,
            'Address'             => implode(', ', array_filter([$billingAddress->getLocality(), $billingAddress->getAdministrativeArea(), $billingAddress->getPostalCode(), $country])),
            'AddressInstructions' => implode(', ', array_filter([$billingAddress->getAddressLine1(), $billingAddress->getAddressLine2()]))
        ];

        return [
            'CustomerName'       => $billingAddress->getGivenName() . ' ' . $billingAddress->getFamilyName(),
            'InvoiceValue'       => "$amount",
            'DisplayCurrencyIso' => $currency,
            'CustomerEmail'      => empty($email) ? null : $email,
            'CallBackUrl'        => $callBackUrl,
            'ErrorUrl'           => $callBackUrl,
            'MobileCountryCode'  => '',
            'CustomerMobile'     => '',
            'Language'           => \Drupal::languageManager()->getCurrentLanguage()->getId(),
            'CustomerReference'  => $orderId,
            'CustomerCivilId'    => null,
            'UserDefinedField'   => $userDefinedField,
            'ExpiryDate'         => null,
            'SourceInfo'         => 'Drupal ' . \Drupal::VERSION . ' - Commerce 2.x - MyFatoorah ' . self::MF_VERSION,
            'CustomerAddress'    => $customerAddress,
            'InvoiceItems'       => [array('ItemName' => 'Total Amount Order #' . $orderId, 'Quantity' => 1, 'UnitPrice' => "$amount")]
                //'InvoiceItems'       => $this->getInvoiceItems($order)
        ];
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
}
