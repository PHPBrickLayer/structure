<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\LayObject;

trait Includes {
    public function inc_file(?string $file, string $type = "inc", bool $once = true, bool $as_string = false, ?array $local = []) : ?string
    {
        self::is_init();
        $slash = DIRECTORY_SEPARATOR;

        $domain = DomainResource::get()->domain;
        $inc_root = $domain->layout;
        $view_root = $domain->plaster;
        $type_loc = $inc_root;

        switch ($type) {
            default:
                $type = ".inc";
            break;
            case "view":
                $type_loc = $view_root;
                $type = ".view";
            break;
        }

        $file = $type_loc . $file . $type;
        $local = LayObject::new()->to_object($local);

        if(!file_exists($file))
            Exception::throw_exception("execution Failed trying to include file ($file)","FileNotFound");

        if($as_string) {
            ob_start();
            $once ? include_once $file : include $file;
            return ob_get_clean();
        }

        $once ? include_once $file : include $file;
        return null;
    }

}
