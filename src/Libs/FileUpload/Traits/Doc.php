<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\FileUpload\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Aws\Bucket;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\FileUpload\FileUpload;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;

/**
 * @phpstan-import-type FileUploadOptions from FileUpload
 * @phpstan-import-type FileUploadReturn from FileUpload
 */
trait Doc {

    /**
     * This function handles documents uploads like pdf,
     * @param FileUploadOptions $options
     * @return FileUploadReturn
     * @throws \Exception
     */
    public function doc_upload(array $options): array
    {
        extract($options);

        if(LayConfig::$ENV_IS_DEV && !@$upload_on_dev)
            $storage = FileUploadStorage::DISK;

        $this->storage = $storage;
        $this->upload_type = FileUploadType::DOC;

        if(
            $check = $this->check_all_requirements(
                $post_name ?? null,
                $post_index ?? 0,
                $file_limit ?? null,
                $extension ?? null,
                $custom_mime ?? null
            )
        ) return $check;

        if($this->dry_run)
            return $this->upload_response(
                false,
                [
                    'dev_error' => "Function is running dry run",
                    'error' => "Upload prevented by user action",
                    'error_type' => FileUploadErrors::DRY_RUN,
                ]
            );

        $file = $_FILES[$post_name];

        $file_size = is_array($file['size']) ? $file['size'][$post_index] : $file['size'];
        $file_name = is_array($file['name']) ? $file['name'][$post_index] : $file['name'];
        $tmp_file = is_array($file['tmp_name']) ? $file['tmp_name'][$post_index] : $file['tmp_name'];
        $add_mod_time ??= true;

        if(@$extension) {
            $file_ext = is_string($extension) ? $extension : $extension->name;
            $file_ext = "." . strtolower($file_ext);
        }
        else {
            $ext = explode(".", $file_name);
            $file_ext = "." . strtolower(end($ext));
        }

        $tmp_checksum = $this->checksum($tmp_file);

        $add_mod_time = $add_mod_time ? "-" .  filemtime($tmp_file) . $file_ext : $file_ext;
        $new_name = Escape::clean(LayFn::rtrim_word($new_name, $file_ext),EscapeType::P_URL) . $add_mod_time;

        if($storage == FileUploadStorage::BUCKET) {
            if(!$bucket_path)
                $this->exception("Bucket path is required when making use of the Bucket storage method");

            if((new Bucket())->upload($tmp_file, $bucket_path . $new_name)['statusCode'] == 200)
                return $this->upload_response(
                    true,
                    [
                        "url" => $bucket_path . $new_name,
                        "size" => $file_size,
                        "checksum" => [
                            "tmp" => $tmp_checksum,
                            "new" => $tmp_checksum
                        ]
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
            "checksum" => [
                "tmp" => $tmp_checksum,
                "new" => $tmp_checksum
            ]
        ]);
    }
}