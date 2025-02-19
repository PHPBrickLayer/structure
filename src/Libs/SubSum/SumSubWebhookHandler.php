<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\SubSum;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\LayConfig;


class SumSubWebhookHandler
{
    public string $webhook_secret;
    public bool $is_successful = true;
    public array $response;
    private readonly SumSubClient $client;

    /**
     * @param callable<array> $callback a callback that accepts the payload of the webhook and returns an array which is
     * accessed from `$this->response` variable
     * @param string|null $webhook_secret
     */
    public function __construct(callable $callback, ?string $webhook_secret = null, ) {
        $this->webhook_secret = $webhook_secret ?? $_ENV['SUM_SUB_WEBHOOK_SECRET'];
        $this->client =  new SumSubClient();

        $res = $this->swallow_hook();

        if($res['code'] != ApiStatus::OK->value) {
            $this->is_successful = false;
            $this->response = $res;
            return;
        }

        $this->handle_response($res['data'], $callback);
    }

    private function swallow_hook() : array
    {
        $payload = file_get_contents("php://input");

        $sum_sub_header = LayConfig::get_header("X-ORIGIN-SRC");

        if (!$sum_sub_header && $sum_sub_header != "sumsub-callback")
            return $this->client::res_error("You are not authorized!", code: ApiStatus::UNAUTHORIZED);

        $signature = LayConfig::get_header('X-Payload-Digest');
        $expected_signature = hash_hmac('sha256', $payload, $this->webhook_secret);

        if(!hash_equals($expected_signature, $signature))
            return $this->client::res_error("Invalid signature",);

        $data = $this->client::request(as_array: true);

        if(empty($data))
            return $this->client::res_error(
                "Payload is invalid",
                code: ApiStatus::NOT_ACCEPTABLE
            );

        return $this->client::res_success(data: $data);
    }

    private function handle_response(array $data, callable $callback) : void
    {
        $this->response = $callback($data);
    }

}