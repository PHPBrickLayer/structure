<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\FileUpload\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Aws\Bucket;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\ID\Gen;
use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use JetBrains\PhpStorm\ArrayShape;

trait Doc {


    /**
     * This function handles documents uploads like pdf,
     * @param array $options
     * @return array
     * @throws \Exception
     */
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
    public function doc_upload(
        #[ArrayShape([
            // Name of file from the form
            'post_name' => 'string',

            // New name and file extension of file after upload
            'new_name' => 'string',

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

            // If nothing is provided the
            'extension' => 'BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension',

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
        array $options
    ): array
    {
        extract($options);

        if(LayConfig::$ENV_IS_DEV && !@$upload_on_dev)
            $storage = FileUploadStorage::DISK;

        $this->storage = $storage;
        $this->upload_type = FileUploadType::DOC;

        if(
            $check = $this->check_all_requirements(
                $post_name ?? null,
                $file_limit ?? null,
                $extension ?? null,
                $custom_mime ?? null
            )
        ) return $check;

        $file = $_FILES[$post_name];

        $file_size = $file['size'];
        $tmp_file = $file['tmp_name'];
        $add_mod_time ??= true;

        if(@$extension) {
            $file_ext = is_string($extension) ? $extension : $extension->name;
            $file_ext = "." . strtolower($file_ext);
        }
        else {
            $ext = explode(".", $file['name']);
            $file_ext = "." . strtolower(end($ext));
        }

        $add_mod_time = $add_mod_time ? "-" .  filemtime($file['tmp_name']) . $file_ext : $file_ext;
        $new_name = Escape::clean(LayFn::rtrim_word($new_name, $file_ext),EscapeType::P_URL) . $add_mod_time;

        if($storage == FileUploadStorage::BUCKET) {
            if(!$bucket_path)
                $this->exception("Bucket path is required when making use of the Bucket storage method");

            if((new Bucket())->upload($tmp_file, $bucket_path . $new_name)['statusCode'] == 200)
                return $this->upload_response(
                    true,
                    [
                        "url" => $bucket_path . $new_name,
                        "size" => $file_size
                    ]
                );

            return $this->upload_response(
                false,
                [
                    "dev_error" => "Class: " . self::class,
                    "error" => "Could not upload to Bucket",
                    "error_type" => FileUploadErrors::BUCKET_UPLOAD_FAILED
                ]
            );
        }

        //TODO: Implement FTP Upload. I'll probably not implement it
        if($storage == FileUploadStorage::FTP) {
            return $this->upload_response(false, [
                "dev_error" => "Class: " . self::class,
                "error" => "The FTP upload function has not been implemented",
                "error_type" => FileUploadErrors::FTP_UPLOAD_FAILED
            ]);
        }

        $directory = rtrim($directory,DIRECTORY_SEPARATOR . "/") . DIRECTORY_SEPARATOR;

        LayDir::make($directory, $permission ?? 0755, true);

        $new_location = $directory . $new_name;

        if(!@move_uploaded_file($tmp_file, $new_location))
            return $this->upload_response(false, [
                "dev_error" => "Failed to move file <b>FROM</b; $tmp_file <b>TO</b> $new_location <b>USING</b> (\$_FILES['$post_name']), ensure location exists, or you have permission; Class: " . self::class,
                "error" => "Failed to upload file, you might not have sufficient permission for that",
                "error_type" => FileUploadErrors::DISK_UPLOAD_FAILED,
            ]);

        return $this->upload_response(true, [
            "url" => $new_name,
            "size" => $file_size,
        ]);
    }
}