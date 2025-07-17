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
        $this->ad_default_files($pattern, $domain_dir);
    }

    public function ad_default_files(string $pattern, string $domain_dir): void
    {
        $uuid = Gen::uuid(32);

        // domain foundation file
        file_put_contents(
            $domain_dir . $this->plug->s . "foundation.php",
            <<<FILE
            <?php

            use BrickLayer\Lay\Core\LayConfig;
            
            LayConfig::set_cors(
                [
                    "https://github.com",
                ],
                fun: function () {
                    header("Access-Control-Allow-Credentials: true");
                    header("Access-Control-Allow-Headers: *");
                    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
                }
            );
            
            \$hook_file = LayConfig::server_data()->temp . "git_webhook.txt";
            file_put_contents(\$hook_file, "[" . date("Y-m-d H:i:s e") . "]\n");
            
            function x_hook_logger(?string \$action) : void
            {
                global \$hook_file;
            
                file_put_contents(\$hook_file, "\$action \n", FILE_APPEND);
            }
            
            function pre_invalidate_hooks(string &\$log) : void
            {
            
            }
            
            function post_invalidate_hooks(string &\$log) : void
            {
            
            }
            FILE
        );

        // root index.php
        file_put_contents(
            $domain_dir . $this->plug->s . "index.php",
            <<<FILE
            <?php
            use BrickLayer\Lay\Libs\Cron\LayCron;
            use BrickLayer\Lay\Core\Exception;
            use BrickLayer\Lay\Core\LayConfig;
            
            const SAFE_TO_INIT_LAY = true;

            include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "foundation.php";

            include_once "foundation.php";
            
            \$main_branch = "main";
            
            // Replace [PRIMARY_DOMAIN] with your actual primary domain. 
            // Create a subdomain entry on your dns. 
            // Finally, paste the link below to github or your CI/CD platform
            // https://$pattern.[PRIMARY_DOMAIN]/$uuid 
            // You can decide to create an environment variable since leaving the uuid like this in the project is risky.
            // Generate a new UUID from your DB or echo this function Gen::uuid(); and user the value in your .env
            // Then you can use LayFn::env("MY_ENV")
            
            // Verify webhook from GitHub
            if(!isset(\$_SERVER['REQUEST_METHOD']))
                Exception::throw_exception("Wrong mode of contact", "GitADMismatched");

            if(\$_SERVER['REQUEST_METHOD'] !== 'POST' or @\$_GET['brick'] !== "$uuid")
                Exception::throw_exception("Invalid endpoint met! please check your uuid and try again", "GitADMismatched");

            \$post = json_decode(\$_POST['payload'] ?? null);

            if(!isset(\$post->pull_request)) {
                x_hook_logger(\$post?->action?->zen);
                return;
            }

            if(\$post->pull_request->state != "closed") {
                x_hook_logger("Pull Request: " . \$post->pull_request->state);
                return;
            }

            \$log = "-- Stash Old Changes: " . shell_exec("git stash 2>&1 &") . " \\n";
            \$log .= "-- Git Checkout: " . shell_exec("git checkout \$main_branch 2>&1 &") . "\\n";
            \$log .= "-- Submodule Init: " . shell_exec("git submodule init 2>&1 &") . " \\n";
            \$log .= "-- Submodule Reset: " . shell_exec('git submodule foreach --recursive  "git fetch origin && git reset --hard" 2>&1 &') . " \\n";
            \$log .= "-- Submodule Pull: " . shell_exec('git pull --recurse-submodules 2>&1 &') . "\\n";
            \$log .= "-- Git Fetch: " . shell_exec('git fetch --all 2>&1 &') . "\\n";
            \$log .= "-- Git Reset: " . shell_exec("git reset --hard origin/\$main_branch 2>&1 &") . "\\n";
            
            \$log .= "\\n";
            \$log .= "--++ Pre Invalidate Hooks Actions\\n";
            
            pre_invalidate_hooks(\$log);

            \$log .= "\\n";
            \$log .= "-- Invalidating Hooks\\n";
            (new \Web\Api\Plaster())->invalidate_hooks();
            
            \$log .= "\\n";
            \$log .= "--++ Post Invalidate Hooks Actions\\n";
            post_invalidate_hooks(\$log);

            \$log .= "\\n";
            \$log .= "-- Symlinks are being refreshed\\n";
            
            \$bob = LayConfig::server_data()->root . "bob";
            \$log .= "-- Link Refresh: " . shell_exec("php \$bob link:refresh 2>&1 &") . "\\n";
            
            // push composer deployment for later execution to avoid 504 (timeout error)
            \$log .= "-- Cronjob: " . LayCron::new()
                ->job_id("update-composer-pkgs")
                ->every_minute()
                ->just_once()
                ->new_job("bob up_composer")['msg']
            ;
            
            x_hook_logger(\$log);
            print "Done!";
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