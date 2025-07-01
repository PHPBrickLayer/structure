<?php

namespace BrickLayer\Lay\Libs\Aws;

use Aws\S3\S3Client;
use Error;
use Exception;
use GuzzleHttp\Promise\Utils;
use BrickLayer\Lay\Libs\Aws\Enums\AwsS3Client;

final class Bucket
{
    public static S3Client $client;
    
    private function validate_bucket() : void
    {
        if(!self::$client->doesBucketExist($this->bucket))
            LayAws::exception("BucketDoesNotExist", "Bucket [$this->bucket] does not exist. Operation halted");
    }

    public function __construct(
        public ?string $bucket = null,
        AwsS3Client    $type = AwsS3Client::R2,
    )
    {
        $this->bucket ??= $_ENV['BUCKET_NAME'] ?? null;
        self::$client = new S3Client(LayAws::init($type)::$credentials);
    }

    /**
     * @return array{
     *     statusCode?: int,
     *     message?: string,
     *     effectiveUri?: string,
     *     headers?: string,
     *     transferStats?: string,
     * }
     */
    public function upload(string $from, string $to): array
    {
        if (!file_exists($from))
            LayAws::exception("FileNotFound", "Source file: [$from] does not exist!");

        self::validate_bucket();

        try {
            $contents = self::$client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $to,
                'SourceFile' => $from,
            ]);

            return $contents->get("@metadata") ?? [];

        } catch (\Throwable $e) {
            LayAws::exception("LayBucketUploadError", $e->getMessage());
        }

        return ["statusCode" => 500];
    }

    /**
     * @return array{
     *     statusCode?: int,
     *     message?: string,
     *     effectiveUri?: string,
     *     headers?: string,
     *     transferStats?: string,
     * }
     */
    public function rm_file(string $file): array
    {
        self::validate_bucket();

        try {
            if( !self::$client->doesObjectExist($this->bucket, $file) ) return [
                "statusCode" => 404,
                "message" => "File not found"
            ];

            $contents = self::$client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $file,
            ]);

            $contents = $contents->get("@metadata") ?? [];
            $contents['message'] = "Operation complete";

            return $contents;
        }
        catch (Error|Exception $e) {
            LayAws::exception("LayBucketRmFile", $e->getMessage());
        }

        return [
            "statusCode" => 500,
            "message" => "An error occurred"
        ];
    }

    /**
     * @return array{
     *     statusCode: int,
     *     promise: object,
     * }
     */
    public function rm_dir(string $dir): array
    {
        self::validate_bucket();

        $dir = rtrim($dir, "/") . "/";

        $promise = self::$client->listObjectsAsync([
            'Bucket' => $this->bucket,
            'Prefix' => $dir,
        ])->then(function ($result) {
            $files = [];

            if(empty($result['Contents']))
                return [
                    "statusCode" => 404,
                    "message" => "File deleted successfully"
                ];

            foreach ($result['Contents'] as $v){
                $files[]['Key'] = $v['Key'];
            }

            return self::$client->deleteObjectsAsync([
                'Bucket' => $this->bucket,
                'Delete' => [
                    'Objects' => $files
                ]
            ]);
        })->then(function ($result) {
            if(is_array($result))
                return $result;

            return $result->get('@metadata');
        });

        Utils::queue()->run();

        return $promise->wait();
    }

    /**
     * @return array{0?: mixed,...}
     */
    public function list(string $directory, bool $src_only = true, ?callable $callback = null) : array
    {
        $files = [];

        try {
            $contents = self::$client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $directory,
            ]);

            foreach ($contents['Contents'] as $content) {
                if($src_only) {
                    if($callback)
                        $content['Key'] = $callback($content['Key']);

                    $files[] = $content['Key'];

                    continue;
                }

                if($callback)
                    $content = $callback($content);

                $files[] = $content;

            }

        } catch (\Exception $e){
            \BrickLayer\Lay\Core\Exception::throw_exception($e->getMessage(), exception: $e);
        }

        return $files;
    }

}