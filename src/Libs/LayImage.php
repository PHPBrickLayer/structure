<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use JetBrains\PhpStorm\ArrayShape;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;

final class LayImage{
    use IsSingleton;

    /**
     * Check image width and height size
     * @param $imageFile string file to be checked for size
     * @return array [width,height]
     */
    public function get_size(string $imageFile) : array {
        list($w_orig,$h_orig) = getimagesize($imageFile);

        if(!$w_orig || !$h_orig)
            $this->exception("An invalid image file was sent for upload");

        return ["width" => $w_orig,"height" => $h_orig];
    }

    /**
     * @param string $tmpImage location to temporary file or file to be handled
     * @param string $newImage location to new image file
     * @param int $quality image result quality [max value = 100 && min value = 0]
     * @param bool $resize default: false
     * @param int|null $width resize image width
     * @param int|null $height resize image height
     * @return LayImage
     */
    public function create(string $tmpImage, string $newImage, int $quality = 80, bool $resize = false, ?int $width = null, ?int $height = null,) : self {
        $ext = image_type_to_extension(exif_imagetype($tmpImage),false);
        $img = call_user_func("imagecreatefrom$ext", $tmpImage);

        if($resize)
            $img = imagescale($img, $width);

        imagealphablending($img, TRUE);
        imagesavealpha($img, true);

        imagewebp($img, $newImage, $quality);
        imagedestroy($img);

        return $this;
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
     * @return string|bool filename and extension on success or false on fail
     */
    public function move(
        #[ArrayShape([
            'post_name' => 'string',
            'new_name' => 'string',
            'directory' => 'string',
            'permission' => 'int',
            'quality' => 'int',
            'dimension' => 'array',
            'copy_tmp_file' => 'bool',
        ])]
        array $options
    ): bool|string
    {
        extract($options);
        $copy_tmp_file = $copy_tmp_file ?? false;
        $permission = $permission ?? 0755;
        $dimension = $dimension ?? null;
        $quality = $quality ?? 80;

        if(!isset($_FILES[$post_name]))
            return false;

        $directory = rtrim($directory,DIRECTORY_SEPARATOR);

        $operation = function ($imgName, $tmp_name) use ($directory, $post_name, $new_name, $dimension, $copy_tmp_file, $quality){
            $lay = LayConfig::instance();

            $tmpFolder = $lay::mk_tmp_dir();
            $file_name = $lay::get_orm()->clean($new_name,6) . ".webp";

            $tmpImg = $tmpFolder . DIRECTORY_SEPARATOR . "temp.tmp";
            $directory = $directory . DIRECTORY_SEPARATOR . $file_name;

            if (!extension_loaded("gd"))
                $this->exception("GD Library not installed, please install php-gd extension and try again");

            if($copy_tmp_file && !copy($tmp_name,$tmpImg))
                $this->exception("Failed to copy temporary image <b>FROM</b; $tmp_name <b>TO</b> $tmpImg <b>USING</b> (\$_FILES['$post_name']), ensure location exists, or you have permission");

            if(!$copy_tmp_file && @!move_uploaded_file($tmp_name, $tmpImg))
                $this->exception("Could not create temporary image from; (\$_FILES['$post_name']) in location: ($tmpFolder), ensure location exists or check permission");

            if($dimension)
                $this->create($tmpImg, $directory, $quality, true, $dimension[0], $dimension[1]);
            else
                $this->create($tmpImg, $directory, $quality);

            unlink($tmpImg);
            return $file_name;
        };

        if(!is_dir($directory)) {
            umask(0);
            if(!@mkdir($directory, $permission, true))
                $this->exception("Failed to create directory on location: ($directory); access denied; modify permissions and try again");
        }

        $files = $_FILES[$post_name];

        if(empty($files['tmp_name']))
            return false;

        return $operation($files["name"], $files["tmp_name"]);
    }

    private function exception(string $message) : void {
        Exception::throw_exception($message, "IMG-SERVICE");
    }
}