<?php

namespace BrickLayer\Lay\Libs\Aws;

use Aws\Credentials\Credentials;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Libs\Aws\Enums\AwsS3Client;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;

class LayAws
{
    use IsSingleton;
    
    public static AwsS3Client $type;
    public static array $credentials;

    public static function exception(string $title, string $message) : void
    {
        Exception::throw_exception($message, "AWS_" . $title);
    }

    public static function init( AwsS3Client $type = AwsS3Client::R2 ) : self
    {
        $credentials = new Credentials($_ENV['AWS_ACCESS_KEY_ID'],$_ENV['AWS_ACCESS_KEY_SECRET']);
        $region = $_ENV['AWS_REGION'] ?? 'auto';

        if($type == AwsS3Client::S3)
            $region = $_ENV['AWS_REGION'] ?? 'us-east-1';

        $options = [
            'region' => $region,
            'version' => 'latest',
            'credentials' => $credentials,

            'use_path_style_endpoint' => $_ENV['R2_USE_PATH_STYLE_ENDPOINT'] ?? false,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',

        ];

        if($type == AwsS3Client::R2) {
            if (!isset($_ENV['CLOUDFLARE_ACCOUNT_ID']))
                self::exception("RequiredKeyNotSet", "`CLOUDFLARE_ACCOUNT_ID` env variable is not set. Please update your .env file and include it");

            $options['endpoint'] = "https://{$_ENV['CLOUDFLARE_ACCOUNT_ID']}.r2.cloudflarestorage.com";
        }

        self::$credentials = $options;

        return self::new();
    }
}