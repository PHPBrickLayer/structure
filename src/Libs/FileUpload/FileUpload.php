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
    #[ArrayShape([
        'uploaded' => 'bool',
        'dev_error' => 'string',
        'error' => 'string',
        'error_type' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadErrors",
        'upload_type' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadType",
        'storage' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadStorage",
        'url' => 'string',
        'size' => 'int',
        'width' => 'int',
        'height' => 'int',
    ])]
    public ?array $response = null;

    protected ?FileUploadStorage $storage = null;
    protected ?FileUploadType $upload_type = null;

    use Image;
    use Doc;

    /**
     * @throws \Exception
     */
    public function __construct(
        #[ArrayShape([
            // Name of file from the form
            'post_name' => 'string',

            // New name and file extension of file after upload
            'new_name' => 'string',

            'upload_type' => 'BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType',

            //<<START DISK KEY
            'directory' => 'string',
            'permission' => 'int',
            //<<END DISK KEY

            // The path the bucket should use in storing your file. Example: files/user/1/report/
            'bucket_path' => 'string',

            // Use this to force bucket upload in development environment
            'upload_on_dev' => 'bool',

            // File limit in bytes
            'file_limit' => 'int',

            // If nothing is provided the system will not validate for the extension type
            'extension' => 'BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension',

            // An array of BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension
            'extension_list' => 'array<BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension]>',

            // Use this to add a custom MIME that does not exist in the extension key above
            'custom_mime' => 'array', // ['application/zip', 'application/x-zip-compressed']

            // The type of storage the file should be uploaded to
            'storage' => 'BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage',

            // Add last modified time to the returned url key, so that your browser can cache it.
            // This is necessary if you are using the same 'new_name' for multiple versions of a file
            // The new file will overwrite the old file, and the last_mod_time will force the browser to update its copy
            'add_mod_time' => 'bool',

            // The compression quality to produce after uploading an image: [10 - 100]
            'quality' => 'int',

            // The dimension an image should maintain: [max_width, max_height]
            'dimension' => 'array',

            // If the php temporary file should be moved or copied. This is necessary if you want to generate a thumbnail
            // and other versions of the image from one upload file
            'copy_tmp_file' => 'bool',
        ])]
        array $opts = []
    )
    {
        $req = $this->check_all_requirements(
            post_name: $opts['post_name'] ?? null,
            custom_mime:  $opts['custom_mime'] ?? null,
            extension_list:  $opts['extension_list'] ?? null,
        );

        if($req) {
            if($req['error_type'] == FileUploadErrors::NO_POST_NAME)
                return $this->response = null;

            return $this->response = $req;
        }


        if(@$opts['upload_type']) {
            if($opts['upload_type'] instanceof FileUploadType)
                $this->upload_type = $opts['upload_type'];
            else
                $this->exception("upload_type received is not of type: " . FileUploadType::class . "; Rather received: " . gettype($opts['upload_type']));
        } else {
            $mime = mime_content_type($_FILES[$opts['post_name']]['tmp_name']);

            if (str_starts_with($mime, "image/"))
                $this->upload_type = FileUploadType::IMG;

            elseif (str_starts_with($mime, "video/"))
                $this->upload_type = FileUploadType::VIDEO;

            else
                $this->upload_type = FileUploadType::DOC;
        }

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
        'width' => "int?",
        'height' => "int?",
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
            "upload_type" => $this->upload_type,
            'width' => $opt['width'] ?? null,
            'height' => $opt['height'] ?? null,
        ];
    }

    /**
     * @throws \Exception
     */
    private function check_all_requirements(
        ?string                         $post_name,
        ?int                            $file_limit = null,
        FileUploadExtension|null|string $extension = null,
        ?array                          $custom_mime = null,
        ?array                          $extension_list = null,
    ) : ?array
    {
        if(!$post_name)
            return $this->upload_response (
                false,
                [
                    "error" => "No post_name received",
                    "error_type" => FileUploadErrors::NO_POST_NAME,
                ]
            );

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

        if($extension_list) {
            $mime = mime_content_type($file);
            $found = false;

            foreach ($extension_list as $list) {
                if(!($list instanceof FileUploadExtension)) {
                    $extension_list = implode(",", $extension_list);
                    $this->exception("extension_list must be of type " . FileUploadExtension::class . "; extension_list: [$extension_list]. File Mime: [$mime]");
                }

                if($list->value == $mime) {
                    $found = true;
                    break;
                }
            }

            if(!$found) {
                $extension_list = implode(",", $extension_list);

                return $this->upload_response(
                    false,
                    [
                        "dev_error" => "Uploaded file had: [$mime], but required mime types are: [$extension_list]; Class: " . self::class,
                        "error" => "File type is invalid",
                        "error_type" => FileUploadErrors::WRONG_FILE_TYPE
                    ]
                );
            }
        }

        if($extension || $custom_mime) {
            $mime = mime_content_type($file);

            if(!$custom_mime) {
                $pass = false;
                $test_multiple = function (FileUploadExtension ...$ext) use ($extension, $mime) : bool {
                    if(!in_array($extension, $ext, true))
                        return false;

                    $pass = false;

                    foreach ($ext as $e) {
                        if($pass)
                            break;

                        $pass = $mime == $e->value;
                    }

                    return $pass;
                };

                foreach (FileUploadExtension::cases() as $case) {
                    if($pass)
                        break;

                    if($pass = $test_multiple(FileUploadExtension::ZIP, FileUploadExtension::ZIP_OLD))
                        break;

                    if($pass = $test_multiple(FileUploadExtension::EXCEL, FileUploadExtension::EXCEL_OLD))
                        break;

                    $pass = $mime == $case->value;
                }

                if(!$pass) {
                    $extension = is_string($extension) ? $extension : $extension->name;

                    return $this->upload_response(
                        false,
                        [
                            "dev_error" => "Uploaded file had: [$mime], but required mime type is: [$extension]; Class: " . self::class,
                            "error" => "Uploaded file does not match the required file type: [$extension]",
                            "error_type" => FileUploadErrors::WRONG_FILE_TYPE
                        ]
                    );
                }
            }
            else {
                if (!in_array($mime, $custom_mime, true)) {
                    $custom_mime = implode(",", $custom_mime);

                    return $this->upload_response(
                        false,
                        [
                            "dev_error" => "Uploaded file had: [$mime], but required mime types are: [$custom_mime]; Class: " . self::class,
                            "error" => "Uploaded file is not accepted",
                            "error_type" => FileUploadErrors::WRONG_FILE_TYPE
                        ]
                    );
                }
            }
        }

        return null;
    }
}