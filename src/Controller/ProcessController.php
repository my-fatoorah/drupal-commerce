<?php

namespace Drupal\myfatoorah_drupal_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use MyFatoorah\Library\MyfatoorahApiV2;

class ProcessController extends ControllerBase {

//-----------------------------------------------------------------------------------------------------------------------------
    public function callback(Request $request) {

        $paymentId = $request->get('paymentId');
        $orderId   = $request->get('oid');

        if (!$paymentId || !$orderId) {
            throw new NotFoundHttpException();
        }

        $url = Url::fromUri('route:commerce_payment.checkout.return');
        $url->setRouteParameter('commerce_order', base64_decode($orderId));
        $url->setRouteParameter('step', 'payment');

        print_r('
<!DOCTYPE html>
<html lang="en-US">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                font-family: sans-serif;
            }
            .loader {
              border: 13px solid #f3f3f3;
              border-radius: 50%;
              border-top: 13px solid #3498db;
              width: 50px;
              height: 50px;
              -webkit-animation: spin 2s linear infinite; /* Safari */
              animation: spin 2s linear infinite;
            }

            /* Safari */
            @-webkit-keyframes spin {
              0% { -webkit-transform: rotate(0deg); }
              100% { -webkit-transform: rotate(360deg); }
            }

            @keyframes spin {
              0% { transform: rotate(0deg); }
              100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <center style="margin:10%">
            Please wait while your transaction <b>' . $paymentId . '</b> is processing...
            <br/><br/>Please do not refresh or close the window
            <br/><br/>
            <div class="loader"></div>
        </center>
        <script>window.location = "' . $url->toString() . '?paymentId=' . $paymentId . '";</script>
    </body>
</html>');
        die;
    }

    public function webhook(Request $request) {
        $MyFatoorah_Signature3 = 'XdNvAIV8ZN6CmB2zzapnSemO6lDUpwKk2g/a11GxI8U=';
        $body3                 = '{"EventType":1,"Event":"TransactionsStatusChanged","DateTime":"13092021114623","CountryIsoCode":"KWT","Data":{"InvoiceId":994285,"InvoiceReference":"2021001240","CreatedDate":"13092021114006","CustomerReference":"139","CustomerName":"رشا سعيد","CustomerMobile":"123456789","CustomerEmail":"rsaeed@myfatoorah.com","TransactionStatus":"FAILED","PaymentMethod":"KNET","UserDefinedField":"139","ReferenceId":"060699428581329564","TrackId":"13-09-2021_813295","PaymentId":"100202125611400734","AuthorizationId":"060699428581329564","InvoiceValueInBaseCurrency":"10.942","BaseCurrency":"KWD","InvoiceValueInDisplayCurreny":"36","DisplayCurrency":"USD","InvoiceValueInPayCurrency":"10.95","PayCurrency":"KWD"}}';

        $apache      = apache_request_headers();
        $headers     = array_change_key_case($apache);
        $mfSignature = $MyFatoorah_Signature3; //empty($headers['myfatoorah-signature']) ? die : $headers['myfatoorah-signature'];


        $body    = $body3; //file_get_contents("php://input");
        $webhook = json_decode($body, true);

        $eventType = (isset($webhook['EventType']) && $webhook['EventType'] == 1) ? $webhook['EventType'] : die;
        $data      = (empty($webhook['Data'])) ? die : $webhook['Data'];

        $webhook['Data']['CustomerReference'] = 3;
        $order                                = \Drupal\commerce_order\Entity\Order::load($webhook['Data']['CustomerReference']);
        if (!$order) {
            die;
        }

        $payment_gateway = $order->get('payment_gateway')->entity;

        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment_gateway->getPlugin();
        if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
            die;
        }


        $configuration = $payment_gateway_plugin->getConfiguration();
        $secretKey     = empty($configuration['webhookSecretKey']) ? die : $configuration['webhookSecretKey'];

        MyfatoorahApiV2::isSignatureValid($data, $secretKey, $mfSignature, $eventType) ? $this->{$webhook['Event']}($data, $order, $payment_gateway_plugin) : die;
    }

//-----------------------------------------------------------------------------------------------------------------------------

    function TransactionsStatusChanged($data, $order, $gateway) {
        //to allow the callback code run 1st
        sleep(15);

            //we should use remote_id
//        $orderPaymentId = get_post_meta($orderId, 'PaymentId', true);
//        if ($orderPaymentId == $data['PaymentId']) {
//            die;
//        }
//        
        $paymentMethod = $gateway->getBaseId();
        if ($paymentMethod != 'myfatoorah_drupal_commerce') {
            die;
        }

        //draft, completed, canceled
        $status = $order->getState()->getString();

        //go back if NOT draft, canceled
        if ($status != 'draft' && $status != 'canceled') {
            die;
        }



        try {
            $gateway->checkStatus($data['InvoiceId'], 'InvoiceId', $order, ' - WebHook');
        } catch (\Exception $ex) {
            $msg       = 'Order #' . $data['CustomerReference'] . ' ----- WebHook TransactionsStatusChanged - Error: ' . $ex->getMessage();
            $pluginlog = drupal_get_path('module', 'myfatoorah_drupal_commerce') . '/myfatoorah.log';
            error_log(PHP_EOL . date('d.m.Y h:i:s') . ' - ' . $msg, 3, $pluginlog);
        }

        //it is a must
        die;
    }

//-----------------------------------------------------------------------------------------------------------------------------
}
