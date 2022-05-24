<?php

namespace craftnet\controllers\id;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftnet\Module;
use Throwable;
use yii\web\Response;

/**
 * Class InvoicesController
 */
class InvoicesController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Get invoices.
     *
     * @return Response
     */
    public function actionGetInvoices(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();

        $filter = $this->request->getParam('filter');
        $limit = $this->request->getParam('limit', 10);
        $page = (int)$this->request->getParam('page', 1);
        $orderBy = $this->request->getParam('orderBy');
        $ascending = $this->request->getParam('ascending');

        try {
            $customer = Commerce::getInstance()->getCustomers()->getCustomerByUserId($user->id);

            $invoices = [];

            if ($customer) {
                $invoices = Module::getInstance()->getInvoiceManager()->getInvoices($customer, $filter, $limit, $page, $orderBy, $ascending);
            }

            $total = Module::getInstance()->getInvoiceManager()->getTotalInvoices($customer, $filter);

            $last_page = ceil($total / $limit);
            $next_page_url = '?next';
            $prev_page_url = '?prev';
            $from = ($page - 1) * $limit;
            $to = ($page * $limit) - 1;

            return $this->asJson([
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
                'last_page' => $last_page,
                'next_page_url' => $next_page_url,
                'prev_page_url' => $prev_page_url,
                'from' => $from,
                'to' => $to,
                'data' => $invoices,
            ]);
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }

    /**
     * Get invoice by its number.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionGetInvoiceByNumber(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $number = $this->request->getRequiredParam('number');

        try {
            $customer = Commerce::getInstance()->getCustomers()->getCustomerByUserId($user->id);

            $invoice = Module::getInstance()->getInvoiceManager()->getInvoiceByNumber($customer, $number);

            return $this->asJson($invoice);
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }

    /**
     * Get invoices.
     *
     * @return Response
     */
    public function actionGetSubscriptionInvoices(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $invoices = StripePlugin::getInstance()->getInvoices()->getUserInvoices($user->id);

        $data = [
            'invoices' => [],
        ];

        foreach ($invoices as $invoice) {
            $invoiceData = $invoice->invoiceData;

            $latestStart = 0;

            // Find the latest subscription start time and make it the invoice date.
            foreach ($invoiceData['lines']['data'] as $lineItem) {
                $latestStart = max($latestStart, $lineItem['period']['start']);
            }

            $data['invoices'][] = [
                'date' => DateTimeHelper::toDateTime($latestStart)->format('Y-m-d'),
                'amount' => $invoiceData['total'] / 100,
                'url' => UrlHelper::actionUrl('craftnet/id/invoices/download-subscription-invoice', ['id' => $invoiceData['id']]),
            ];
        }

        // Sort invoices in descending order by date
        usort($data['invoices'], function($a, $b) {
            return strtotime($b["date"]) - strtotime($a["date"]);
        });

        return $this->asJson($data);
    }

    /**
     * Downloads a subscription invoice from Stripe.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionDownloadSubscriptionInvoice(): Response
    {
        $id = $this->request->getRequiredParam('id');

        try {
            $headers = ['headers' =>  [
                'Authorization' => 'Bearer ' . App::env('STRIPE_API_KEY'),
                'Accept'        => 'application/json',
            ]];

            $client = Craft::createGuzzleClient();

            $response = $client->get('https://api.stripe.com/v1/invoices/'.$id, $headers);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Could not connect to dev server.');
            }

            if (!$body = $response->getBody()) {
                throw new \Exception('Response has no body.');
            }

            $contents = $body->getContents();
            $json = Json::decode($contents);

            if (isset($json['invoice_pdf']) && $json['invoice_pdf']) {
                return $this->redirect($json['invoice_pdf']);
            }

            throw new \Exception('Could not find an invoice.');
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }
}
