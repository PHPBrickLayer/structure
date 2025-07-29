<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\FileUpload;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\FileUpload\Traits\Doc;
use BrickLayer\Lay\Libs\FileUpload\Traits\Image;
use BrickLayer\Lay\Libs\LayCrypt\Enums\HashType;

/**
 * @phpstan-type FileUploadReturn array{
 *     uploaded: bool,
 *     dev_error?: string,
 *     error?: string,
 *     error_type?: FileUploadErrors,
 *     upload_type: FileUploadType,
 *     storage: FileUploadStorage,
 *     url?: string,
 *     path?: string,
 *     name?: string,
 *     size?: int,
 *     width?: int,
 *     height?: int,
 *     file_type?: FileUploadExtension,
 *     mime_type?: string,
 *     extension?: string,
 *     checksum?: array{
 *        tmp: array{
 *          checksum: string,
 *          algo: string,
 *        },
 *        new: array{
 *          checksum: string,
 *          algo: string,
 *       },
 *    },
 *  }
 *
 * @phpstan-type FileUploadOptions array{
 *  // Instruct the class to not upload or move php's temp files, but process every other thing before that step
 * dry_run?: bool,
 *
 * // A function to run before starting upload process of file
 * pre_upload?: callable(?string $tmp_file, ?array $file):(FileUploadReturn|true),
 *
 * // Name of file from the form
 * post_name?: string,
 *
 * // The link to the temporary file. If this is set, it is assumed dev wants to validate the file only, so no upload is done
 * tmp_file?: string,
 *
 * post_index?: int,
 *
 * // New name and file extension of file after upload
 * new_name?: string,
 *
 * upload_type?: FileUploadType,
 *
 * //<<START DISK KEY
 * directory?: string,
 * permission?: int,
 * //<<END DISK KEY
 *
 * // The path the bucket should use in storing your file. Example: files/user/1/report/
 * bucket_path?: string,
 *
 * // Use this to force bucket upload in development environment
 * upload_on_dev?: bool,
 *
 * // File limit in bytes
 * file_limit?: int,
 *
 * // Instructs the class to validate this specific file extension. If this is specified, `extension_list` is ignored
 * // If nothing is provided the system will not validate for the extension type
 * // If passing extension as a string, it should be just the extension, without period; so: jpg or zip, etc.
 * extension?: FileUploadExtension|string,
 *
 * // An array of BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension
 * extension_list?: array<int,FileUploadExtension>,
 *
 * // Use this to add a custom MIME that does not exist in the FileUploadExtension trait
 * // ['application/zip', 'application/x-zip-compressed']
 * custom_mime?: array<int,string>,
 *
 * // The type of storage the file should be uploaded to
 * storage?: FileUploadStorage,
 *
 * // Add last modified time to the returned url key, so that your browser can cache it.
 * // This is necessary if you are using the same 'new_name' for multiple versions of a file
 * // The new file will overwrite the old file, and the last_mod_time will force the browser to update its copy
 * add_mod_time?: bool,
 *
 * // The compression quality to produce after uploading an image: [10 - 100]
 * quality?: int,
 *
 * // The dimension an image should maintain: [max_width, max_height]
 * dimension?: array<int, int>,
 *
 * // If the php temporary file should be moved or copied. This is necessary if you want to generate a thumbnail
 * // and other versions of the image from one upload file
 * copy_tmp_file?: bool,
 * }
 */
final class FileUpload {

    /**
     * @var null|FileUploadReturn
     */
    public ?array $response = null;

    protected bool $dry_run = false;

    /**
     * @var FileUploadOptions|null
     */
    protected array|null $attr;

    protected ?FileUploadStorage $storage = null;
    protected ?FileUploadType $upload_type = null;
    protected ?FileUploadExtension $file_type = null;

    use Image;
    use Doc;

    /**
     * @param FileUploadOptions|null $opts
     * @throws \Exception
     * @return void
     */
    public function __construct( ?array $opts = null )
    {
        if(empty($opts))
            return;

        $this->attr = $opts;

        $post_index = $opts['post_index'] ?? 0;
        $upload_on_dev = $opts['upload_on_dev'] ?? false;
        $storage = $opts['storage'] ?? null;

        if(LayConfig::$ENV_IS_DEV && !$upload_on_dev)
            $storage = FileUploadStorage::DISK;

        $this->storage = $storage;

        if(
            $req = $this->check_all_requirements(
                post_name: $opts['post_name'] ?? null,
                post_index: $post_index,
                file_limit: $opts['file_limit'] ?? null,
                extension: $opts['extension'] ?? null,
                custom_mime:  $opts['custom_mime'] ?? null,
                extension_list:  $opts['extension_list'] ?? null,
                tmp_file: $opts['tmp_file'] ?? null
            )
        ) {
            if($req['error_type'] == FileUploadErrors::NO_POST_NAME) {
                $this->response = null;
                return;
            }

            $this->response = $req;
            return;
        }

        if(isset($opts['tmp_file'])) {
            $this->response = null;
            return;
        }

        if($opts['dry_run'] ?? $this->dry_run) {
            $this->response = $this->upload_response(
                false,
                [
                    'dev_error' => "Function is running dry run",
                    'error' => "Upload prevented by user action",
                    'error_type' => FileUploadErrors::DRY_RUN,
                ]
            );

            return;
        }

        $file = $_FILES[$opts['post_name']];
        $tmp_file = is_array($file['tmp_name']) ? $file['tmp_name'][$post_index] : $file['tmp_name'];

        $this->upload_type = $opts['upload_type'] ?? null;

        if(!$this->upload_type) {
            $mime = mime_content_type($tmp_file);

            if (str_starts_with($mime, "image/"))
                $this->upload_type = FileUploadType::IMG;

            elseif (str_starts_with($mime, "video/"))
                $this->upload_type = FileUploadType::VIDEO;

            else
                $this->upload_type = FileUploadType::DOC;
        }

        if(isset($opts['pre_upload'])) {
            $out = $opts['pre_upload']($tmp_file, $file);

            if(is_array($out)) {
                if(isset($out['update_attr'])){
                    $this->attr = array_merge($this->attr, $out['update_attr']);
                }
                else{
                    $this->response = $out;
                    return;
                }
            }
        }

        $file = [
            "tmp_file" => $tmp_file,
            "size" => is_array($file['size']) ? $file['size'][$post_index] : $file['size'],
            "name" => is_array($file['name']) ? $file['name'][$post_index] : $file['name'],
        ];

        $extension = $opts['extension'] ?? null;

        if($extension) {
            $file_ext = is_string($extension) ? $extension : $extension->name;
            $file_ext = "." . strtolower($file_ext);
        }
        else {
            $ext = explode(".", $file['name']);
            $file_ext = "." . strtolower(end($ext));
        }

        $file['extension'] = $file_ext;

        if($this->upload_type == FileUploadType::IMG)
            $this->response = $this->image_upload($file);

        if($this->upload_type == FileUploadType::DOC)
            $this->response = $this->doc_upload($file);
    }

    /**
     * @param string $file_name_or_post_name The path to the file or the name of the file from a post request
     * @param int $index Index for specific file entry in the case of multiple files upload
     *
     * @return false|int
     *
     * @throws \Exception
     */
    public function file_size(string $file_name_or_post_name, int $index = 0) : int|false
    {
        $files = $_FILES[$file_name_or_post_name] ?? null;

        if(empty($files['tmp_name'])) {
            $size = filesize($file_name_or_post_name);

            if(!$size)
                Exception::new()->use_exception(
                    "FileUpload_SizeError",
                    "Cannot get the file size for [$file_name_or_post_name]; file may not exist or an invalid post name received"
                );

            return $size;
        }

        return filesize(is_array($files['tmp_name']) ? $files['tmp_name'][$index] : $files['tmp_name'] );
    }

    /**
     * @param string $file_name
     * @param HashType $algo
     * @return array{
     *     checksum: string,
     *     algo: HashType,
     * }
     */
    public function checksum(string $file_name, HashType $algo = HashType::MD5) : array
    {
        return [
            "checksum" => hash_file($algo->name, $file_name),
            "algo" => $algo
        ];
    }

    private function exception(string $message) : void {
        Exception::throw_exception($message, "FileUpload");
    }

    /**
     * @param bool $uploaded
     * @param FileUploadReturn $opt
     * @return FileUploadReturn
     */
    private function upload_response( bool $uploaded, array $opt ) : array
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
            "name" => $opt['name'],
            "path" => $opt['path'],
            "url" => $opt['url'],
            "size" => $opt['size'],
            "storage" => $this->storage,
            "upload_type" => $this->upload_type,
            'width' => $opt['width'] ?? null,
            'height' => $opt['height'] ?? null,
            'file_type' => $this->file_type,
            'checksum' => $opt['checksum'] ?? null,
            'mime_type' => $opt['mime_type'] ?? null,
            'extension' => $opt['extension'] ?? null,
        ];
    }

    /**
     * @throws \Exception
     */
    private function check_all_requirements(
        ?string                         $post_name,
        int                             $post_index = 0,
        ?int                            $file_limit = null,
        FileUploadExtension|null|string $extension = null,
        ?array                          $custom_mime = null,
        ?array                          $extension_list = null,
        ?string                         $tmp_file = null,
    ) : ?array
    {
        if(!$post_name && !$tmp_file)
            return $this->upload_response (
                false,
                [
                    "error" => "No post_name received",
                    "error_type" => FileUploadErrors::NO_POST_NAME,
                ]
            );

        if(!$tmp_file && !isset($_FILES[$post_name]))
            return $this->upload_response (
                false,
                [
                    "error" => "$post_name is not set",
                    "error_type" => FileUploadErrors::FILE_NOT_SET,
                ]
            );

        $file = $_FILES[$post_name] ?? null;

        if(!$tmp_file && !$file)
            return $this->upload_response (
                false,
                [
                    "error" => "File was not received. Ensure the file is not above the set max file size. Try a file with a lower file size",
                    "error_type" => FileUploadErrors::FILE_NOT_SET,
                ]
            );


        if((is_array($file['tmp_name']))) {
            $name = $file['name'][$post_index];
            $size = $file['size'][$post_index];
            $file = $file['tmp_name'][$post_index];
        }
        else {
            $name = $file['name'] ?? null;
            $size = $file['size'] ?? null;
            $file = $file['tmp_name'] ?? null;
        }

        $file = $tmp_file ?? $file;

        if($size == 0 && !$name)
            return $this->upload_response (
                false,
                [
                    "error" => "File was not received. Ensure the file is not above the set max file size. Try a file with a lower file size",
                    "error_type" => FileUploadErrors::FILE_NOT_SET,
                ]
            );

        if($size == 0)
            return $this->upload_response(
                false,
                [
                    "error" => "File was not received. Ensure the file is not above the set max file size. Try a file with a lower file size",
                    "error_type" => FileUploadErrors::TMP_FILE_EMPTY,
                ]
            );

        if($file_limit && $size > $file_limit) {
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
            $joined_lists = "";

            // This first loop is to check if the extension list contains ::PICTURE
            // then the extension list should be populated with all pictures format.
            foreach ($extension_list as $i => $list) {
                if($list == FileUploadExtension::PICTURE) {
                    unset($extension_list[$i]);

                    array_push($extension_list,
                        FileUploadExtension::PNG,
                        FileUploadExtension::JPEG,
                        FileUploadExtension::HEIC,
                        FileUploadExtension::WEBP,
                    );
                }
            }

            // This second loop matches the mime type with the newly populated extension list
            foreach ($extension_list as $list) {
                if(!($list instanceof FileUploadExtension)) {
                    $this->exception("extension_list must be of type " . FileUploadExtension::class . "; File Mime: [$mime]");
                }

                if($list->value == $mime) {
                    $this->file_type = $list;
                    $found = true;
                    break;
                }

                $joined_lists .= $list->value . ", ";
            }

            if(!$found) {
                $joined_lists = rtrim($joined_lists, ", ");

                return $this->upload_response(
                    false,
                    [
                        "dev_error" => "Uploaded file had: [$mime], but required mime types are: [$joined_lists]; Class: " . self::class,
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
                        $pass = $mime == $e->value;

                        if($pass) {
                            $this->file_type = $e;
                            break;
                        }
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