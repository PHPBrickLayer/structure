<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Libs\LayCopyDir;
use BrickLayer\Lay\Libs\LayUnlinkDir;
use BrickLayer\Lay\Orm\SQL;
use SplFileObject;


trait Domain
{
    public function domain(): void
    {
        if(!isset($this->tags['make_domain']))
            return;

        $domain = $this->tags['make_domain'][0] ?? null;
        $pattern = $this->tags['make_domain'][1] ?? null;

        if (!$domain)
            $this->plug->write_fail("No domain specified");

        if (!$pattern)
            $this->plug->write_fail(
                "No domain pattern specified!\n"
                . "Pattern has to be in quotes ('').\n"
                . "\n"
                . "See pattern examples below:\n"
                . "Example: 'blog-posts,blog'\n"
                . "Example: 'case-study'\n"
            );

        if (empty($pattern) || (!$this->plug->is_internal && trim($pattern) == "*"))
            $this->plug->write_warn(
                "Pattern cannot be an empty quote or '*'\n"
                . "\n"
                . "See pattern examples below:\n"
                . "Example: 'blog-posts,blog'\n"
                . "Example: 'case-study'\n"
            );


        $domain = explode(" ", ucwords($domain));
        $domain_id = strtolower(implode("-", $domain) . "-id");
        $domain = implode("", $domain);
        $domain_dir = $this->plug->server->domains . $domain;
        $exists = is_dir($domain_dir);

        if($domain == "Default" && !$this->plug->is_internal)
            $this->plug->write_warn(
                "Unfortunately you cannot create a *Default* domain automatically\n"
                . "If for whatever reasons you need to do that, navigate to the Lay Repository and copy it from there\n"
                . "Github: *https://github.com/PHPBrickLayer/lay*"
            );

        if($domain == "Default" && $this->plug->is_internal) {
            $domain_id = "default";
            $pattern = "*";
        }

        if (!$this->plug->force && $exists)
            $this->plug->write_fail(
                "Domain directory *$domain_dir* exists already!\n"
                . "If you wish to force this action, pass the tag --force with the command\n"
                . "Note, using --force will delete the existing directory and this process cannot be reversed!"
            );

        if ($exists) {
            $this->talk(
                "- Directory *$domain_dir* exists but --force tag detected\n"
                . "- Deleting existing *$domain_dir*"
            );

            new LayUnlinkDir($domain_dir);
        }

        $this->talk("- Creating new Domain directory in *$domain_dir*");
        new LayCopyDir($this->internal_dir . "Domain", $domain_dir);

        $this->talk("- Copying default files");
        $this->domain_default_files($domain, $domain_id, $domain_dir);

        $this->talk("- Linking .htaccess *{$this->plug->server->web}*");
        new BobExec("link:htaccess $domain --silent");

        $this->talk("- Linking shared directory *{$this->plug->server->shared}*");
        new BobExec("link:dir web{$this->plug->s}shared web{$this->plug->s}domains{$this->plug->s}$domain{$this->plug->s}shared --silent");

        $this->talk("- Linking Api domain to new domain *{$this->plug->server->domains}Api*");
        new BobExec("link:dir web{$this->plug->s}domains{$this->plug->s}Api web{$this->plug->s}domains{$this->plug->s}$domain{$this->plug->s}api --silent");

        $this->talk("- Updating domains entry in *{$this->plug->server->web}index.php*");
        $this->update_general_domain_entry($domain, $domain_id, $pattern);
    }

    public function domain_default_files(string $domain_name, string $domain_id, string $domain_dir): void
    {
        // root index.php
        file_put_contents(
            $domain_dir . $this->plug->s . "index.php",
            <<<FILE
            <?php
            use BrickLayer\Lay\Core\View\Domain;
            
            const SAFE_TO_INIT_LAY = true;
            include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "foundation.php";
            
            Domain::new()->index("$domain_id");
            
            include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "index.php";
            
            FILE
        );

        // Plaster.php for handling routes
        file_put_contents(
            $domain_dir . $this->plug->s .
            "Plaster.php",
            <<<FILE
            <?php
            namespace web\domains\\$domain_name;
            
            use BrickLayer\Lay\Core\View\DomainResource;
            use BrickLayer\Lay\Core\View\ViewBuilder;
            use BrickLayer\Lay\Core\View\ViewCast;
            
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

        // favicon.ico
        if(file_exists($this->plug->server->web . "favicon.ico")) {
            copy(
                $this->plug->server->web . "favicon.ico",
                $domain_dir . $this->plug->s . "favicon.ico"
            );

            return;
        }
        copy(
            $this->plug->server->lay_static . "img" . $this->plug->s . "favicon.ico",
            $domain_dir . $this->plug->s . "favicon.ico"
        );
    }

    public function update_general_domain_entry(string $domain, string $domain_id, string $patterns): void
    {
        $pattern = "";
        foreach (explode(",", $patterns) as $p) {
            $pattern .= '"' . trim($p) . '",';
        }

        $pattern = rtrim($pattern, ",");

        $main_file = $this->plug->server->web . "index.php";
        $lock_file = $this->plug->server->web . ".index.php.lock";

        copy($main_file, $lock_file);

        $file = new SplFileObject($lock_file, 'r+');
        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        $page = [];
        $domains = [];
        $key = 0;
        $storing_domain = false;
        $existing_domain_key = null;
        $page_index = 0;

        while (!$file->eof()) {
            $entry = $file->fgets();

            if($page_index > 6 && empty($entry))
                continue;

            if (str_starts_with($entry, "Domain::new()"))
                $storing_domain = true;

            if ($storing_domain) {

                $domains[$key][] = $entry;

                if(
                    $existing_domain_key === null &&
                    $this->plug->force
                ) {
                    if(
                        str_starts_with(ltrim($entry), "id:")
                    )
                        $existing_domain_key = trim(
                            rtrim(
                                explode("id:", $entry)[1],
                                ","
                            ), "'\""
                        ) == $domain_id ? $key : null;

                    if(
                        !$existing_domain_key &&
                        str_starts_with(ltrim($entry), "builder:")
                    )
                        $existing_domain_key = @explode("\\", $entry)[3] == $domain ? $key : null;
                }


                if (str_ends_with($entry, ";")) {
                    $storing_domain = false;
                    $key++;
                }

                continue;
            }

            $page[] = $entry;
            $page_index++;
        }

        $default_domain = end($domains);
        array_pop($domains);

        if($existing_domain_key)
            unset($domains[$existing_domain_key]);

        if($this->plug->is_internal && $domain == "Default") {
            $default_domain = [''];
        }

        $domains = SQL::new()->array_flatten($domains);

        $new_domain = [
            'Domain::new()->create(',
            '    id: "' . $domain_id . '",',
            '    builder: \web\domains\\' . $domain . '\\Plaster::class,',
            '    patterns: [' . $pattern . '],',
            ');',
        ];

        try{
            array_push($page, "", ...$domains, ...[""], ...$new_domain, ...[""], ...$default_domain, ...[""]);
            $file->rewind();
            $file->fwrite(implode("\n", $page));
        } catch (\Exception $e) {
            Exception::throw_exception($e->getMessage(), "MakeDomain", exception: $e);

            new LayUnlinkDir($domain_dir);
            unlink($lock_file);
        }

        copy($lock_file, $main_file);
        unlink($lock_file);
    }
}