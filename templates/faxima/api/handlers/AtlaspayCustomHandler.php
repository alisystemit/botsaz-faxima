<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

final class AtlaspayCustomHandler extends BaseHandler
{
    public string $mode = 'view';

    public function handle(): void
    {
        if (!function_exists('atlaspayCustomFetchReport')) {
            FaoximaResponse::fail(503, 'درگاه اطلس‌پی روی این سرور در دسترس نیست.');
        }

        if ($this->mode === 'view') {
            $this->requireMethod('GET');
        } else {
            $this->requireMethod('POST');
        }

        $orderId = FaoximaInput::string($this->data, 'order_id');
        if ($orderId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $orderId)) {
            FaoximaResponse::badRequest('order_id is required');
        }
        $userId = (string)$this->user['id'];

        switch ($this->mode) {
            case 'view':
                $report = atlaspayCustomPollThrottled($orderId, $userId);
                if ($report === null) {
                    FaoximaResponse::notFound('Payment not found');
                }
                FaoximaResponse::ok(['view' => atlaspayCustomPublicView($report)]);
                return;

            case 'cancel':
                $result = atlaspayCustomCancel($orderId, $userId);
                if (empty($result['ok'])) {
                    FaoximaResponse::fail(409, (string)($result['message'] ?? 'لغو فاکتور انجام نشد.'));
                }
                FaoximaResponse::ok(['kind' => 'cancelled', 'order_id' => $orderId]);
                return;
        }

        FaoximaResponse::badRequest('Unknown mode');
    }
}
