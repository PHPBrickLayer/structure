<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Libs\LayCopyDir;
use BrickLayer\Lay\Libs\LayUnlinkDir;
use SplFileObject;


trait Domain
{
    public function domain(): void
    {
        $domain = $this->tags['make_domain'][0] ?? null;
        $pattern = $this->tags['make_domain'][1] ?? null;

        $talk = fn($msg) => $this->plug->write_talk($msg);

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

        if (empty($pattern) || trim($pattern) == "*")
            $this->plug->write_warn(
                "Pattern cannot be an empty quote or '*'\n"
                . "\n"
                . "See pattern examples below:\n"
                . "Example: 'blog-posts,blog'\n"
                . "Example: 'case-study'\n"
            );


        $domain = explode(" ", ucwords($domain));
        $domain_id = implode("-", $domain) . "-id";
        $domain = implode("", $domain);
        $domain_dir = $this->plug->server->domains . $domain;
        $exists = is_dir($domain_dir);

        if ($this->plug->force && $exists)
            $this->plug->write_fail(
                "Domain directory *$domain_dir* exists already!\n"
                . "If you wish to force this action, pass the tag --force with the command\n"
                . "Note, using --force will delete the existing directory and this process cannot be reversed!"
            );

        if ($exists) {
            $talk(
                "- Directory *$domain_dir* exists but --force tag detected\n"
                . "- Deleting existing *$domain_dir*"
            );

            new LayUnlinkDir($domain_dir);
        }

        $talk("- Creating new Domain directory in *$domain_dir*");
        new LayCopyDir($this->internal_dir . "Domain", $domain_dir);

        $talk("- Copying default files");
        $this->domain_default_files($domain, $domain_dir);

        $talk("- Linking .htaccess *{$this->plug->server->web}*");
        symlink($this->plug->server->web . ".htaccess", $domain_dir . $this->plug->s . ".htaccess");

        $talk("- Linking shared directory *{$this->plug->server->shared}*");
        symlink($this->plug->server->shared, $domain_dir . $this->plug->s . "shared");

        $talk("- Updating domains entry in *{$this->plug->server->web}index.php*");
        $this->update_general_domain_entry($domain, $domain_id, $pattern);
    }

    public function domain_default_files(string $domain_name, string $domain_dir): void
    {
        file_put_contents(
            $domain_dir . $this->plug->s .
            "index.php",
            <<<FILE
            <?php
            use BrickLayer\Lay\Core\View\Domain;
            
            const DOMAIN_SET = true;
            
            include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "index.php";
            
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

    public function update_general_domain_entry(string $domain, string $domain_id, string $patterns): void
    {
        $pattern = "";
        foreach (explode(",", $patterns) as $p) {
            $pattern .= '"' . $p . '",';
        }

        $pattern = rtrim($pattern, ",");

        $file = new SplFileObject($this->plug->server->web . "index.php", 'w+');
        $file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);

        $page = [];
        $domains = [];
        $key = 0;
        $storing_domain = false;

        while (!$file->eof()) {
            $entry = $file->fgets();

            if (str_starts_with($entry, "Domain::new()"))
                $storing_domain = true;

            if ($storing_domain) {
                if (empty($entry))
                    continue;

                $domains[$key][] = $entry;

                if (str_ends_with($entry, ";")) {
                    $storing_domain = false;
                    $key++;
                }

                continue;
            }

            $page[] = $entry;
        }

        $default_domain = end($domains);

        $new_domain = [
            'Domain::new()->create(',
            '    id: "' . $domain_id . '",',
            '    builder: new \web\domains\\' . $domain . '\\Plaster(),',
            '    patterns: [' . $pattern . '],',
            ');',
            '',
        ];

        array_pop($domains);
        array_push($page, $domains, $new_domain, $default_domain);

        $file->fwrite(implode("\n", $page));
    }
}