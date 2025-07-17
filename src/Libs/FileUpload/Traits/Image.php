<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\FileUpload\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Aws\Bucket;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\FileUpload\FileUpload;
use BrickLayer\Lay\Libs\ID\Gen;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Imagick;
use ImagickException;

/**
 * @phpstan-import-type FileUploadOptions from FileUpload
 * @phpstan-import-type FileUploadReturn from FileUpload
 */
trait Image
{
    /**
     * Check image width and height size
     * @param $image_file string file to be checked for size
     * @return array{
     *     width: int,
     *     height: int,
     * }
     */
    public function get_ratio(string $image_file): array
    {
        try {
            $image = new Imagick($image_file);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->clear();

            return ["width" => $width, "height" => $height];
        } catch (ImagickException $e) {
            LayException::throw_exception("An invalid image file was sent for upload: " . $image_file, exception: $e);
        }

        return ["width" => null, "height" => null];
    }

    /**
     * Create a new image and return it as a webp.
     * If Image is a GIF, a gif is returned
     *
     * @param string $tmp_img location to temporary file or file to be handled
     * @param string $new_img location to new image file
     * @param int $quality image result quality [max value = 100 && min value = 0]
     * @param bool $resize default: false
     * @param int|null $width resize image width
     * @param bool $add_mod_time
     * @return array{
     *    created: bool,
     *    ext?: string,
     *    url?: string,
     *    path?: string,
     *    size?: int,
     *    width?: int,
     *    height?: int,
     *    dev_error?: string,
     *    error?: string,
     *    error_type?: FileUploadErrors,
     *    checksum?: array,
     *    mime_type?: string,
     * }
     * @throws \Exception
     */
    public function create(string $tmp_img, string $new_img, int $quality = 80, bool $resize = false, ?int $width = null, bool $add_mod_time = true): array
    {
        try {
            $img = new Imagick($tmp_img);
            $format = strtolower($img->getImageFormat());
            $ext = $format === 'gif' ? 'gif' : 'webp';
            $mod_time = $add_mod_time ? "-" . filemtime($tmp_img) : "";
            $new_img .= $mod_time . ".$ext";
            $filename = pathinfo($new_img, PATHINFO_FILENAME) . ".$ext";

            if ($resize && $width !== null) {
                $img->resizeImage($width, $width, Imagick::FILTER_CATROM, 1, true);
            }

            $img->setImageCompressionQuality($quality);

            if ($ext === 'gif') {
                $created = $img->writeImage($new_img);
            } else {
                $img->setImageFormat('webp');
                $created = $img->writeImage($new_img);
            }

            $mime_type = $img->getImageMimeType();

            $img->clear();

            if (!$created) {
                return [
                    "created" => false,
                    "dev_error" => "Imagick failed to create a new image; Class: " . self::class,
                    "error" => "Could not complete image processing",
                    "error_type" => FileUploadErrors::IMG_CREATION,
                    "ext" => $ext,
                ];
            }

            $ratio = $this->get_ratio($new_img);

            return [
                "created" => true,
                "ext" => $ext,
                "url" => $filename,
                "path" => str_replace(LayConfig::server_data()->root, "", $new_img),
                "mime_type" => $mime_type,
                "size" => $this->file_size($new_img),
                "width" => $ratio['width'],
                "height" => $ratio['height'],
                "checksum" => $this->checksum($new_img),
            ];

        } catch (ImagickException $e) {
            LayException::throw_exception("Image creation failed", exception: $e);
        }

        return [
            "created" => false,
            "dev_error" => "Imagick failed to create a new image; No action was taken; Class: " . self::class,
            "error" => "Could not complete image processing",
            "error_type" => FileUploadErrors::IMG_CREATION,
            "ext" => null,
        ];
    }

    /**
     * @param FileUploadOptions $options
     * @return FileUploadReturn
     * @throws \Exception
     */
    public function image_upload(array $options) : array
    {
        extract($options);

        if(LayConfig::$ENV_IS_DEV && !@$upload_on_dev)
            $storage = FileUploadStorage::DISK;

        $this->storage = $storage;
        $this->upload_type = FileUploadType::IMG;

        if(
            $check = $this->check_all_requirements(
                $post_name ?? null,
                $post_index ?? 0,
                $file_limit ?? null,
            )
        ) return $check;

        if (!extension_loaded("imagick"))
            return $this->upload_response(
                false,
                [
                    'dev_error' => "For image upload to work, imagick Library needs to be installed. Use `sudo apt install php-gd` in linux, `pecl install gd` in mac, or enable the extension in your `php.ini` on windows",
                    'error' => "Could not complete upload process, an error occurred",
                    'error_type' => FileUploadErrors::LIB_IMAGICK_NOT_FOUND,
                ]
            );

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
        $add_mod_time ??= true;
        $copy_tmp_file ??= false;
        $quality = $quality ?? 80;

        $tmp_file = is_array($file['tmp_name']) ? $file['tmp_name'][$post_index] : $file['tmp_name'];
        $tmpImg = LayConfig::mk_tmp_dir() . "temp-file-" . Gen::uuid(32);

        if($copy_tmp_file && !copy($tmp_file, $tmpImg))
            return $this->upload_response(
                false,
                [
                    'dev_error' => "Failed to copy temporary image <b>FROM</b; $tmp_file <b>TO</b> $tmpImg <b>USING</b> (\$_FILES['$post_name']), ensure location exists, or you have permission; Class: " . self::class,
                    'error' => "Could not complete upload process, an error occurred",
                    'error_type' => FileUploadErrors::IMG_COPY_FAILED,
                ]
            );

        $directory = rtrim($directory,DIRECTORY_SEPARATOR . "/") . DIRECTORY_SEPARATOR;
        $new_name = Escape::clean($new_name,EscapeType::P_URL);

        if(!$copy_tmp_file)
            $tmpImg = $tmp_file;

        $tmp_checksum = $this->checksum($tmp_file);

        LayDir::make($directory, $permission ?? 0755, true);

        $created = $dimension ?
            $this->create($tmpImg, $directory . $new_name, $quality, true, $dimension[0] ?? $dimension['width'], $add_mod_time) :
            $this->create($tmpImg, $directory . $new_name, $quality, add_mod_time: $add_mod_time);

        if(!$created['created'])
            return $this->upload_response(
                false,
                [
                    'dev_error' => $created['dev_error'],
                    'error' => $created['error'],
                    'error_type' => $created['error_type'],
                ]
            );

        @unlink($tmpImg);

        $new_name = $created['url'];

        $out = [
            "url" => $new_name,
            "path" => $created['path'],
            "size" => $created['size'],
            "width" => $created['width'],
            "height" => $created['height'],
            "mime_type" => $created['mime_type'],
            "extension" => $created['ext'],
            "checksum" => [
                "tmp" => $tmp_checksum,
                "new" => $created['checksum']
            ]
        ];

        if($storage == FileUploadStorage::BUCKET) {
            if(!$bucket_path)
                $this->exception("Bucket path is required when making use of the Bucket storage method");

            if((new Bucket())->upload($directory . $new_name, $bucket_path . $new_name)['statusCode'] == 200) {
                @unlink($directory . $new_name);

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
        if($storage == FileUploadStorage::FTP) {
            return $this->upload_response(false, [
                "dev_error" => "Class: " . self::class,
                "error" => "The FTP upload function has not been implemented",
                "error_type" => FileUploadErrors::FTP_UPLOAD_FAILED
            ]);
        }

        return $this->upload_response(true, $out);

    }
}