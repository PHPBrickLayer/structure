<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayObject;

trait Includes {
    public function inc_file(?string $file, string $type = "inc", bool $once = true, bool $as_string = false, ?array $local = [], bool $use_refering_domain = true) : ?string
    {
        self::is_init();

        $domain = DomainResource::get()->domain;

        $replace = fn($src) => !$use_refering_domain ? $src : str_replace(
            DIRECTORY_SEPARATOR . $domain->domain_name . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . $domain->domain_referrer . DIRECTORY_SEPARATOR,
            $src
        );

        switch ($type) {
            default:
                $type = "";
                $type_root = $replace($domain->domain_root);
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
