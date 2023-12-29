<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayCopyDir;
use BrickLayer\Lay\Libs\LayUnlinkDir;


trait AutoDeploy
{
    public function auto_deploy(): void
    {
        if(!isset($this->tags['make_auto_deploy']))
            return;

        $domain = "GitAutoDeploy";
        $pattern = "gitad";

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

            new LayUnlinkDir($domain_dir);
        }

        $this->talk("- Creating new Domain directory in *$domain_dir*");
        new LayCopyDir($this->internal_dir . "Domain", $domain_dir);

        $this->talk("- Making default files");
        $this->ad_default_files($pattern, $domain_dir);
    }

    public function ad_default_files(string $pattern, string $domain_dir): void
    {
        $uuid = LayConfig::connect()->uuid();

        // root index.php
        file_put_contents(
            $domain_dir . $this->plug->s . "index.php",
            <<<FILE
            <?php
            use BrickLayer\Lay\Libs\LayCron;
            use BrickLayer\Lay\Core\Exception;
            
            // https://$pattern.[PRIMARY_DOMAIN]/$uuid
            // Verify webhook from GitHub

            if(\$_SERVER['REQUEST_METHOD'] !== 'POST' or @\$_GET['brick'] !== "$uuid") {
                Exception::throw_exception("Invalid endpoint met! please check your uuid and try again", "GitADMismatched");
                die;
            }

            \$main_branch = "main";

            print "GitAD Responds With: \n";

            \$post = json_decode(\$_POST['payload']);

            if(!isset(\$post->pull_request)) {
                echo \$post?->action?->zen;
                die;
            }

            if(\$post->pull_request->state != "closed") {
                echo "Pull Request: " . \$post->pull_request->state;
                die;
            }

            echo shell_exec("git checkout \$main_branch 2>&1");
            echo shell_exec('git pull 2>&1');
            echo shell_exec("git reset --hard origin/\$main_branch 2>&1");

            // push composer deployment for later execution to avoid 504 (timeout error)
            echo LayCron::new()
                ->job_id("update-composer-pkgs")
                ->every_minute()
                ->new_job("bob up_composer")['msg'];
            
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
            RewriteRule ^(.*)$ index.php [L,QSA]
            Order allow,deny
            Deny from all
            </Files>
            
            RewriteRule ^(.*)$ index.php?brick=$1 [L,QSA]
            
            FILE
        );
    }
}