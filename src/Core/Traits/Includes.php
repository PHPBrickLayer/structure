<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayObject;
use JetBrains\PhpStorm\ArrayShape;

trait Includes {

    /**
     * @param string|null $file path to file
     * @param string $type use predefined file path [plaster, layout, project, etc.]. Check code for more info
     * @param bool $once use `include_once` or just `include`
     * @param bool $as_string instructs the function to return the included file as a string
     * @param array|null $local pass local variables to the file being included
     * @param bool $use_referring_domain
     * @param bool $use_get_content [Relevant when `$as_string` is true]. It instructs the function to use `file_get_contents` instead of `include`
     * @param bool $error_file_not_found
     * @param bool $get_last_mod [Relevant when `$as_string` is true]. It instructs the function to return an array with last_mod as a key
     * @return string|array|null
     * @throws \Exception
     */
    #[ArrayShape([
        "last_mod" => "int",
        "content" => "string"
    ])]
    public function inc_file(?string $file, string $type = "inc", bool $once = true, bool $as_string = false, ?array $local = [], bool $use_referring_domain = true, bool $use_get_content = false, bool $error_file_not_found = true, bool $get_last_mod = false) : string|null|array
    {
        self::is_init();

        $domain = DomainResource::get()->domain;
        $going_online = false;

        $replace = fn($src) => !$use_referring_domain ? $src : str_replace(
            DIRECTORY_SEPARATOR . $domain->domain_name . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . $domain->domain_referrer . DIRECTORY_SEPARATOR,
            $src
        );

        switch ($type) {
            default:
                $type = "";
                $type_root = $replace($domain->domain_root);
                break;
            case "online":
                $type = "";
                $type_root = "";
                $going_online = true;
                $as_string = true;
                $use_get_content = true;
                break;
            case "inc":
                $type = ".inc";
                $type_root = $replace($domain->layout);
                break;
            case "view":
                $type = ".view";
                $type_root = $replace($domain->plaster);
                break;
            case "project":
                $type = "";
                $type_root = LayConfig::server_data()->root;
                break;
            case "layout":
                $type = "";
                $type_root = $replace($domain->layout);
                break;
            case "plaster":
                $type = "";
                $type_root = $replace($domain->plaster);
                break;
        }

        $file = str_replace($type, "", $file);
        $file = $type_root . $file . $type;

        // Ordinarily, `DomainResource::plaster()->local` is empty, except when used after
        // DomainResource has been initialized by the `Plaster` class or any `View` related class
        DomainResource::make_plaster_local(
            LayArray::merge(
                DomainResource::plaster()->local ?? [],
                $local, true
            )
        );

        if(!$going_online && !file_exists($file)) {
            if($error_file_not_found)
                Exception::throw_exception("execution Failed trying to include file ($file)", "FileNotFound");

            return null;
        }

        if($as_string) {
            ob_start();

            if($use_get_content)
                echo file_get_contents($file);
            else
                $once ? include_once $file : include $file;

            $x = ob_get_clean();

            if($get_last_mod)
                return [
                    "last_mod" => filemtime($file),
                    "content" => $x
                ];

            return $x;
        }

        $once ? include_once $file : include $file;
        return null;
    }

}
