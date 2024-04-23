<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayObject;

trait Includes {
    public function inc_file(?string $file, string $type = "inc", bool $once = true, bool $as_string = false, ?array $local = []) : ?string
    {
        self::is_init();

        switch ($type) {
            default:
                $type = "";
                $type_root = DomainResource::get()->domain->domain_root;
                break;
            case "inc":
                $type = ".inc";
                $type_root = DomainResource::get()->domain->layout;
                break;
            case "view":
                $type = ".view";
                $type_root = DomainResource::get()->domain->plaster;
                break;
        }

        $file = str_replace($type, "", $file);
        $file = $type_root . $file . $type;

        DomainResource::make_plaster_local(
            LayArray::merge(
                DomainResource::plaster()->local ?? [],
                $local, true
            )
        );

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
