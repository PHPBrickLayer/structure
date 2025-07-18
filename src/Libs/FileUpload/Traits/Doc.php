<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\FileUpload\Traits;

use BrickLayer\Lay\Libs\Aws\Bucket;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\FileUpload;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;

/**
 * @phpstan-import-type FileUploadReturn from FileUpload
 */
trait Doc {

    /**
     * This function handles documents uploads like pdf,
     * @return FileUploadReturn
     * @throws \Exception
     */
    protected function doc_upload(array $file): array
    {
        $file_ext = $file['extension'];
        $tmp_file = $file['tmp_file'];

        $add_mod_time = $this->attr['add_mod_time'] ?? true;
        $new_name = $this->attr['new_name'];
        $bucket_path = $this->attr['bucket_path'] ?? null;
        $directory = $this->attr['directory'] ?? '';
        $permission = $this->attr['permission'] ?? 0755;

        $tmp_checksum = $this->checksum($tmp_file);

        $add_mod_time = $add_mod_time ? "-mt" .  filemtime($tmp_file) . $file_ext : $file_ext;
        $new_name = Escape::clean(LayFn::rtrim_word($new_name, $file_ext),EscapeType::P_URL) . $add_mod_time;

        $name = explode(DIRECTORY_SEPARATOR, $new_name);
        $name = LayFn::rtrim_word(end($name), $file_ext);

        $out = [
            "url" => $new_name,
            "path" => $new_name,
            "size" => $file['size'],
            "name" => $name,
            "mime_type" => mime_content_type($tmp_file),
            "extension" => $file_ext,
            "checksum" => [
                "tmp" => $tmp_checksum,
                "new" => $tmp_checksum
            ]
        ];

        if($this->storage == FileUploadStorage::BUCKET) {
            if(!$bucket_path)
                $this->exception("Bucket path is required when making use of the Bucket storage method");

            if((new Bucket())->upload($tmp_file, $bucket_path . $new_name)['statusCode'] == 200) {
                $out['url'] = $bucket_path . $new_name;

                return $this->upload_response(true, $out);
            }

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
        if($this->storage == FileUploadStorage::FTP) {
            return $this->upload_response(false, [
                "dev_error" => "Class: " . self::class,
                "error" => "The FTP upload function has not been implemented",
                "error_type" => FileUploadErrors::FTP_UPLOAD_FAILED
            ]);
        }

        $directory = rtrim($directory,DIRECTORY_SEPARATOR . "/") . DIRECTORY_SEPARATOR;

        LayDir::make($directory, $permission, true);

        $new_location = $directory . $new_name;

        if(!@move_uploaded_file($tmp_file, $new_location))
            return $this->upload_response(false, [
                "dev_error" => "Failed to move file <b>FROM</b; $tmp_file <b>TO</b> $new_location; ensure location exists, or you have permission; Class: " . self::class,
                "error" => "Failed to upload file, you might not have sufficient permission for that",
                "error_type" => FileUploadErrors::DISK_UPLOAD_FAILED,
            ]);

        return $this->upload_response(true, $out);
    }
}