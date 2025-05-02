<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayObject;
use JetBrains\PhpStorm\ArrayShape;

/**
 * @deprecated
 */
trait Includes {

    /**
     * @deprecated use `DomainResource::include_file()`
     *
     * @param string|null $file path to file
     * @param string $type use predefined file path [plaster, layout, project, etc.]. Check code for more info
     * @param bool $once use `include_once` or just `include`
     * @param bool $as_string instructs the function to return the included file as a string
     * @param array|null $local pass local variables to the file being included
     * @param bool $use_referring_domain
     * @param bool $use_get_content [Relevant when `$as_string` is true]. It instructs the function to use `file_get_contents` instead of `include`
     * @param bool $error_file_not_found
     * @param bool $get_last_mod [Relevant when `$as_string` is true]. It instructs the function to return an array with last_mod as a key
     *
     * @return (int|string)[]|null|string
     *
     * @throws \Exception
     *
     * @psalm-return array{last_mod: int, content: string}|null|string
     */
    #[ArrayShape([
        "last_mod" => "int",
        "content" => "string"
    ])]
    public function inc_file(?string $file, string $type = "inc", bool $once = true, bool $as_string = false, ?array $local = [], bool $use_referring_domain = true, bool $use_get_content = false, bool $error_file_not_found = true, bool $get_last_mod = false) : array|string|null|null|array
    {
        return DomainResource::include_file(
            $file, $type,
            [
                'once' => $once,
                'as_string' => $as_string,
                'local' => $local,
                'use_referring_domain' => $use_referring_domain,
                'use_get_content' => $use_get_content,
                'error_file_not_found' => $error_file_not_found,
                'get_last_mod' => $get_last_mod,
            ],
        );
    }

}
