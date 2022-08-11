<?php

namespace Drupal\commerce_myfatoorah_v2\PluginForm;

use Drupal\commerce_myfatoorah_v2\MyfatoorahHelper;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

//use Psr\Log\LoggerInterface;

class RedirectCheckoutForm extends BasePaymentOffsiteForm {
//---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form1, FormStateInterface $form_state) {
        $form = parent::buildConfigurationForm($form1, $form_state);

        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entity;

        // Payment gateway configuration data.
        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
        $configuration          = $payment_gateway_plugin->getConfiguration();

//        $mf  = new MyfatoorahHelper($payment);
        $mf  = new MyfatoorahHelper($payment->getOrder(), $configuration);
        $url = $mf->getInvoiceUrl($form);

        return $this->buildRedirectForm(
                        $form,
                        $form_state,
                        $url,
                        [],
                        self::REDIRECT_POST
        );
    }

//---------------------------------------------------------------------------------------------------------------------------------------------------
}
