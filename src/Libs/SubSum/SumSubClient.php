<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\SubSum;

use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Core\Traits\ControllerHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class SumSubClient
{
    use ControllerHelper;

    private Client $guzzle;

    public const BASE_API = "https://api.sumsub.com";

    private function set_endpoint(string $endpoint): ?string
    {
        return $this->base_api . "/" . ltrim($endpoint, "/");
    }

    private function headers(int $time, string $signature, ?string $content_type = null) : array
    {
        return [
            'X-App-Token' => $this->token,
            'X-App-Access-Ts' => $time,
            'X-App-Access-Sig' => $signature,
            'Content-Type' => $content_type ?? "application/json"
        ];
    }

    private function get_response(ResponseInterface $response) : array
    {
        return json_decode($response->getBody()->getContents(), true, JSON_THROW_ON_ERROR) ?? [];
    }

    private function send_request(string $method, string $endpoint, ?array $body = null): array
    {
        $now = time();
        $method = strtoupper($method);
        $body = $body ? json_encode($body) : "";

        $opts = [
            'headers' => $this->headers(
                $now,
                $this->get_signature($now, $method, $endpoint, $body)
            )
        ];

        if(!empty($body))
            $opts['body'] = $body;

        try {
            $response = $this->guzzle->request($method, $this->set_endpoint($endpoint), $opts);

            return self::res_success(
                "Request successful",
                $this->get_response($response)
            );
        } catch (GuzzleException $exception) {
            LayException::throw_exception("", exception: $exception);
        }

        return self::res_error();
    }

    public function __construct(
        public ?string $token = null,
        public ?string $secret = null,
        public readonly ?string $base_api = self::BASE_API
    )
    {
        $this->token ??= $_ENV['SUM_SUB_TOKEN'];
        $this->secret ??= $_ENV['SUM_SUB_SECRET_KEY'];
        $this->guzzle = new Client([ 'base_uri' => $this->base_api ]);
    }

    public function get_signature(
        int $time,
        string $method,
        string $endpoint,
        string $body = "",
    ) : string
    {
        return hash_hmac(
            "sha256",
            $time . strtoupper($method) . $endpoint . $body,
            $this->secret
        );
    }

    public function send_phone_otp(string $phone_number, string $user_id) : array
    {
        return $this->send_request(
            "POST",
            "/resources/applicants?levelName=QES-IDV'",
            [
                'phone' => $phone_number,
                'externalUserId' => $user_id,
            ]
        );
    }

    public function verify_phone_otp(string $applicant_id, string $confirmation_id, string $code) : array
    {
        return $this->send_request(
            "POST",
            "/resources/applicants/$applicant_id/ekyc/confirm/$confirmation_id",
            [
                'code' => $code,
            ]
        );
    }

    public function phone_otp(string $level_name, string $user_id) : array
    {
        return $this->send_request(
            "POST",
            "/resources/accessTokens/sdk",
            [
                'ttlInSecs' => 600,
                'levelName' => $level_name,
                'userId' => $user_id,
            ]
        );
    }

    public function get_verified_phone_number(string $applicant_id) : array
    {
        return $this->send_request(
            "GET",
            "/resources/checks/latest?type=PHONE_CONFIRMATION&applicantId=$applicant_id"
        );
    }

}