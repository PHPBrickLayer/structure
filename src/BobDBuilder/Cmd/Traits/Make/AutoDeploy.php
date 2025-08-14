<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\ID\Gen;


trait AutoDeploy
{
    public function auto_deploy(): void
    {
        if(!isset($this->tags['make_auto_deploy']))
            return;

        $domain = $this->tags['make_auto_deploy'][0] ?? "GitAutoDeploy";
        $pattern = $this->tags['make_auto_deploy'][1] ?? "gitad";

        $domain_dir = $this->plug->server->domains . $domain;
        $exists = is_dir($domain_dir);

        if (!$this->plug->force && $exists)
            $this->plug->write_fail(
                "Git auto deploy domain directory *$domain_dir* exists already!\n"
                . "If you wish to force this action, pass the tag --force with the command\n"
                . "Note, using --force will delete the existing directory and this process cannot be reversed!"
            );

        if ($exists) {
            $this->talk(
                "- Directory *$domain_dir* exists but --force tag detected\n"
                . "- Deleting existing *$domain_dir*"
            );

            LayDir::unlink($domain_dir);
        }

        $this->talk("- Creating new Domain directory in *$domain_dir*");
        umask(0);
        mkdir($domain_dir, 0755, true);

        $this->talk("- Making default files");
        $this->default_files($pattern, $domain_dir);
    }

    public function default_files(string $pattern, string $domain_dir): void
    {
        $uuid = Gen::uuid(32);

        // root index.php
        file_put_contents(
            $domain_dir . $this->plug->s . "index.php",
            <<<FILE
            <?php
            use BrickLayer\Lay\Libs\Deploy\Action;
            
            const SAFE_TO_INIT_LAY = true;
            include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "foundation.php";
            
            // Replace [PRIMARY_DOMAIN] with your actual primary domain. 
            // Create a subdomain entry on your dns. 
            // Finally, paste the link below to github or your CI/CD platform
            // https://$pattern.[PRIMARY_DOMAIN]/$uuid 
            // You can decide to create an environment variable since leaving the uuid like this in the project is risky.
            // Then you can use LayFn::env("GIT_ACTION_ID")
            
            Action::branch("main")
            ->id("$uuid")
            ->run();
            FILE
        );

        // robots.txt
        file_put_contents(
            $domain_dir . $this->plug->s . "robots.txt",
            <<<FILE
            User-agent: *
            Disallow: /
            FILE
        );

        // .htaccess
        file_put_contents(
            $domain_dir . $this->plug->s .
            ".htaccess",
            <<<FILE
            ServerSignature Off

            # Disable directory browsing
            Options -Indexes
            
            RewriteEngine On
            
            <Files .htaccess>
            RewriteRule ^(.*)$ index.php?brick=$1 [L,QSA]
            Order allow,deny
            Deny from all
            </Files>
            
            RewriteRule ^(.*)$ index.php?brick=$1 [L,QSA]
            
            FILE
        );
    }
}