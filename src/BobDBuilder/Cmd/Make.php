<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayUnlinkDir;
use BrickLayer\Lay\Libs\LayCopyDir;

class Make implements CmdLayout
{
    use IsSingleton;

    private readonly EnginePlug $plug;
    private readonly array $tags;
    private readonly string $internal_dir;

    public function _init(EnginePlug $plug) : void
    {
        $this->plug = $plug;
        $this->internal_dir = $this->plug->server->lay . "__internal" . $this->plug->s;

        $plug->add_arg($this, ["make:domain"], 'make_domain', 0);
    }

    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        $this->make();
    }

    public function make() : void
    {
        $domain = $this->tags['make_domain'][0] ?? null;

        if(!$domain)
            $this->plug->write_fail("No domain specified");

        $domain = explode(" ", ucwords($domain));
        $domain = implode("", $domain);
        $domain_dir = $this->plug->server->domains . $domain;
        $exists = is_dir($domain_dir);

        if($this->plug->force && $exists)
            $this->plug->write_fail(
                "Domain directory *$domain_dir* exists already!\n"
                . "If you wish to force this action, pass the tag --force with the command\n"
            );

        if($exists)
            new LayUnlinkDir($domain_dir);

        // Copy the default Domain directory to the web
        new LayCopyDir($this->internal_dir . "Domain", $domain_dir);

        // Copy default files to the newly created domain directory
        $this->domain_default_files($domain, $domain_dir);

        // link htaccess from the root web folder
        symlink($this->plug->server->web . ".htaccess", $domain_dir . $this->plug->s . ".htaccess");

        // link shared folder to domain
        symlink($this->plug->server->shared, $domain_dir . $this->plug->s . "shared");
    }

    public function domain_default_files(string $domain_name, string $domain_dir) : void
    {
        file_put_contents(
            $domain_dir . $this->plug->s .
            "index.php",
            <<<FILE
            <?php
            use BrickLayer\Lay\Core\View\Domain;
            
            const DOMAIN_SET = true;
            
            include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "index.php";
            
            Domain::new()->create(
                id: "default",
                builder: new \web\domains\{$domain_name}\Plaster(),
            );
            FILE
        );

        file_put_contents(
            $domain_dir . $this->plug->s .
            "Plaster.php",
            <<<FILE
            <?php
            namespace web\domains\{$domain_name};
            
            use BrickLayer\Lay\core\view\DomainResource;
            use BrickLayer\Lay\core\view\ViewBuilder;
            use BrickLayer\Lay\core\view\ViewCast;
            
            class Plaster extends ViewCast
            {
                public function init_pages(): void
                {
                    \$this->builder->init_start()
                        ->body_attr("dark", 'id="body-id"')
                        ->local("logo", DomainResource::get()->shared->img_default->logo)
                        ->local("section", "app")
                    ->init_end();
                }
            
                public function pages(): void
                {
                    \$this->builder->route("index")->bind(function (ViewBuilder \$builder) {
                        \$builder->page("title", "Homepage")
                            ->page("desc", "This is the default homepage description")
                            ->assets(
                                "@css/another.css",
                            )
                            ->body("homepage");
                    });
            
                    \$this->builder->route("another-page")->bind(function (ViewBuilder \$builder) {
                        \$builder->page("title", "Another Page")
                            ->page("desc", "This is another page's description")
                            ->assets(
                                "@css/another.css",
                            )
                            ->body("another");
                    });
                }
            }
            FILE
        );
    }

}