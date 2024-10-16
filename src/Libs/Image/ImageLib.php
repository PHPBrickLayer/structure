<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Image;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\FileUpload;
use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use JetBrains\PhpStorm\ArrayShape;

final class ImageLib {
    private int $img_file_size;
    private array $img_file_ratio;

    use IsSingleton;

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
                    "LayImage_SizeError",
                    "Cannot get the file size for [$file_name_or_post_name]; file may not exist or an invalid post name received"
                );

            return $size;
        }

        return filesize($files['tmp_name']);
    }

    /**
     * Check image width and height size
     * @param $image_file string file to be checked for size
     * @return array
     */
    #[ArrayShape(['width' => 'int', 'height' => 'int'])]
    public function get_ratio(string $image_file) : array
    {
        list($w_orig,$h_orig) = getimagesize($image_file);

        if(!$w_orig || !$h_orig)
            $this->exception("An invalid image file was sent for upload: " . $image_file);

        return ["width" => $w_orig,"height" => $h_orig];
    }

    /**
     * @param string $tmp_img location to temporary file or file to be handled
     * @param string $new_img location to new image file
     * @param int $quality image result quality [max value = 100 && min value = 0]
     * @param bool $resize default: false
     * @param int|null $width resize image width
     * @param bool $add_mod_time
     * @return string
     */
    public function create(string $tmp_img, string $new_img, int $quality = 80, bool $resize = false, ?int $width = null, bool $add_mod_time = true) : string {
        $ext = image_type_to_extension(exif_imagetype($tmp_img),false);
        $mod_time = $add_mod_time ? "-" .  filemtime($tmp_img) : "";
        $new_img .= $mod_time . ($ext == "gif" ? ".$ext" : ".webp");
        $filename = pathinfo($new_img, PATHINFO_FILENAME) . ($ext == "gif" ? ".$ext" : ".webp");

        if($ext == "gif" && !$resize) {
            copy($tmp_img, $new_img);
            $this->img_file_size = filesize($new_img);
            $this->img_file_ratio = $this->get_ratio($new_img);
            return $filename;
        }

        $img = call_user_func("imagecreatefrom$ext", $tmp_img);

        if($resize)
            $img = imagescale($img, $width);

        imagealphablending($img, TRUE);
        imagesavealpha($img, true);

        if($ext == "gif")
            imagegif($img, $new_img);
        else
            imagewebp($img, $new_img, $quality);

        imagedestroy($img);
        $this->img_file_size = filesize($new_img);
        $this->img_file_ratio = $this->get_ratio($new_img);

        return $filename;
    }

    /**
     * ### @$options
     * - **post_name (string):** $_FILES[post_name] *(REQUIRED)*
     * - **new_name (string):** The name you wish to call this newly uploaded file (REQUIRED)*
     * - **directory (string):** The directory where the file should be uploaded to (REQUIRED)*
     * - **permission (int):** The permission to apply to the directory and file *(default: 0755)*
     * - **quality (int):** The result quality, on a scale if 1 - 100; *(default: 80)*
     * - **dimension (array[int,int]):** [Max Width, Max Height] *(default: [800,800])*
     * - **copy_tmp_file (bool):** On true, function copies the upload temp file instead of moving it in case the developer wants to further process it *(default: false)*
     *
     * This function moves your uploaded image, creates the directory,
     * resizes the image and returns the image name and extension (image.webp)
     * @param array $options
     * @return array
     * @throws \Exception
     */
    #[ArrayShape([
        'uploaded' => 'bool',
        'error' => 'string',
        'error_type' => "BrickLayer\\Lay\\Libs\\FileUpload\\Enums\\FileUploadErrors",
        'url' => 'string',
        'size' => 'int',
        'width' => 'int',
        'height' => 'int',
    ])]
    public function move(
        #[ArrayShape([
            'post_name' => 'string',
            'new_name' => 'string',
            'directory' => 'string',
            'permission' => 'int',
            'quality' => 'int',
            'dimension' => 'array',
            'copy_tmp_file' => 'bool',
            'add_mod_time' => 'bool',
            'file_limit' => 'int',
        ])]
        array $options
    ): array
    {
        trigger_error("This class has been depreciated, use " . FileUpload::class);

        extract($options);
        $copy_tmp_file = $copy_tmp_file ?? false;
        $permission = $permission ?? 0755;
        $dimension = $dimension ?? null;
        $quality = $quality ?? 80;
        $add_mod_time = $add_mod_time ?? true;
        $file_limit = $file_limit ?? null;

        if(!isset($_FILES[$post_name]))
            return [
                "uploaded" => false,
                "error" => "$post_name is not set",
                "error_type" => FileUploadErrors::FILE_NOT_SET
            ];

        $directory = rtrim($directory,DIRECTORY_SEPARATOR);

        $operation = function ($imgName, $tmp_name) use (
            $directory,
            $post_name,
            $new_name,
            $dimension,
            $copy_tmp_file,
            $quality,
            $add_mod_time,
        ){
            $lay = LayConfig::new();

            $tmpFolder = $lay::mk_tmp_dir();

            $tmpImg = $tmpFolder . "temp-file";
            $directory = $directory . DIRECTORY_SEPARATOR . Escape::clean($new_name,EscapeType::P_URL);

            if (!extension_loaded("gd"))
                $this->exception("GD Library not installed, please install php-gd extension and try again");

            if($copy_tmp_file && !copy($tmp_name,$tmpImg))
                $this->exception("Failed to copy temporary image <b>FROM</b; $tmp_name <b>TO</b> $tmpImg <b>USING</b> (\$_FILES['$post_name']), ensure location exists, or you have permission");

            if(!$copy_tmp_file)
                $tmpImg = $tmp_name;

            $file_name = $dimension ?
                $this->create($tmpImg, $directory, $quality, true, $dimension[0], $add_mod_time) :
                $this->create($tmpImg, $directory, $quality, add_mod_time: $add_mod_time);

            @unlink($tmpImg);

            return [
                "uploaded" => true,
                "url" => $file_name,
                "size" => $this->img_file_size,
                "width" => $this->img_file_ratio['width'],
                "height" => $this->img_file_ratio['height'],
            ];
        };

        LayDir::make($directory, $permission, true);

        $file = $_FILES[$post_name];

        if(empty($file['tmp_name']))
            return [
                "uploaded" => false,
                "error" => "File was not received. Ensure the file is not above the set max file size. Try a file with a lower file size",
                "error_type" => FileUploadErrors::TMP_FILE_EMPTY
            ];

        if($file_limit && $this->file_size($file['tmp_name']) > $file_limit) {
            $file_limit = $file_limit/1000000;

            if($file_limit  > 1)
                $file_limit = number_format($file_limit, 2) . "mb";
            else
                $file_limit = number_format($file_limit, 2) . "bytes";

            return [
                "uploaded" => false,
                "error_type" => FileUploadErrors::EXCEEDS_FILE_LIMIT,
                "error" => "File is above the set limit: $file_limit",
            ];
        }

        return $operation($file["name"], $file["tmp_name"]);
    }

    private function exception(string $message) : void {
        Exception::throw_exception($message, "ImgLib");
    }
}