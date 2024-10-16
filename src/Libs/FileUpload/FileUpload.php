<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\FileUpload;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\FileUpload\Traits\Doc;
use BrickLayer\Lay\Libs\FileUpload\Traits\Image;
use JetBrains\PhpStorm\ArrayShape;

final class FileUpload {
    public array $response;

    protected FileUploadStorage $storage;

    use Image;
    use Doc;

    /**
     * @throws \Exception
     */
    public function __construct(
        protected FileUploadType $upload_type,
        array $opts
    )
    {
        if($this->upload_type == FileUploadType::IMG)
            $this->response = $this->image_upload($opts);

        if($this->upload_type == FileUploadType::DOC)
            $this->response = $this->doc_upload($opts);

        return $this->response;
    }

    /**
     * @param string $file_name_or_post_name The path to the file or the name of the file from a post request
     * @return int
     * @throws \Exception
     */
    public function file_size(string $file_name_or_post_name) : int
    {
        $files = @$_FILES[$file_name_or_post_name];

        if(empty($files['tmp_name'])) {
            $size = @filesize($file_name_or_post_name);

            if(!$size)
                Exception::new()->use_exception(
                    "FileUpload_SizeError",
                    "Cannot get the file size for [$file_name_or_post_name]; file may not exist or an invalid post name received"
                );

            return $size;
        }

        return filesize($files['tmp_name']);
    }

    private function exception(string $message) : void {
        Exception::throw_exception($message, "FileUpload");
    }

    #[ArrayShape([
        'uploaded' => 'bool',
        'dev_error' => 'string',
        'error' => 'string',
        'error_type' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadErrors",
        'upload_type' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadType",
        'storage' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadStorage",
        'url' => 'string',
        'size' => 'int',
    ])]
    private function upload_response(
        bool $uploaded,
        #[ArrayShape([
            'uploaded' => 'bool',
            'dev_error' => 'string',
            'error' => 'string',
            'error_type' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadErrors",
            'url' => 'string',
            'size' => 'int',
            'width' => 'int',
            'height' => 'int',
        ])] array $opt
    ) : array
    {
        if(!$uploaded)
            return [
                "uploaded" => false,
                "dev_error" => $opt['dev_error'] ?? $opt['error'],
                "error" => $opt['error'],
                "error_type" => $opt['error_type'],
                "storage" => $this->storage,
                "upload_type" => $this->upload_type
            ];

        return [
            "uploaded" => true,
            "url" => $opt['url'],
            "size" => $opt['size'],
            "storage" => $this->storage,
            "upload_type" => $this->upload_type
        ];
    }

    /**
     * @throws \Exception
     */
    private function check_all_requirements(
        string                          $post_name,
        ?int                            $file_limit = null,
        FileUploadExtension|null|string $extension = null,
        ?array                          $custom_mime = null,
    ) : ?array
    {
        if(!isset($_FILES[$post_name]))
            return $this->upload_response (
                false,
                [
                    "error" => "$post_name is not set",
                    "error_type" => FileUploadErrors::FILE_NOT_SET,
                ]
            );

        $file = $_FILES[$post_name];

        if(empty($file['tmp_name']))
            return $this->upload_response (
                false,
                [
                    "error" => "File was not received. Ensure the file is not above the set max file size. Try a file with a lower file size",
                    "error_type" => FileUploadErrors::TMP_FILE_EMPTY,
                ]
            );

        $file = $file['tmp_name'];

        if($file_limit && $this->file_size($file) > $file_limit) {
            $file_limit = $file_limit/1000000;

            if($file_limit  > 1)
                $file_limit = number_format($file_limit, 2) . "mb";
            else
                $file_limit = number_format($file_limit, 2) . "bytes";

            return $this->upload_response (
                false,
                [
                    "error" => "File is above the set limit: $file_limit",
                    "error_type" => FileUploadErrors::EXCEEDS_FILE_LIMIT,
                ]
            );
        }

        if($extension || $custom_mime) {
            $mime = mime_content_type($file);

            if(!$custom_mime)
                $pass = match ($extension) {
                    FileUploadExtension::PDF => $mime == FileUploadExtension::PDF,
                    FileUploadExtension::CSV => $mime == FileUploadExtension::CSV,

                    FileUploadExtension::ZIP,
                    FileUploadExtension::ZIP_OLD =>
                        $mime == FileUploadExtension::ZIP || $mime == FileUploadExtension::ZIP_OLD,

                    FileUploadExtension::EXCEL,
                    FileUploadExtension::EXCEL_OLD =>
                        $mime == FileUploadExtension::EXCEL_OLD || $mime == FileUploadExtension::EXCEL
                };
            else
                $pass = in_array($mime, $custom_mime, true);

            if(!$pass) {
                $extension = is_string($extension) ? $extension : $extension->name;

                return $this->upload_response(
                    false,
                    [
                        "error" => "Uploaded file does not match the required file type: [$extension]",
                        "error_type" => FileUploadErrors::WRONG_FILE_TYPE
                    ]
                );
            }
        }

        return null;
    }
}