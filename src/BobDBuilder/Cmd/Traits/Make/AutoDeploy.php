<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Libs\ID\Gen;
use BrickLayer\Lay\Libs\LayDir;


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
            
            // Replace [PRIMARY_DOMAIN] with your actual primary domain. 
            // Create a subdomain entry on your dns. 
            // Finally, paste the link below to github or your CI platform
            // https://$pattern.[PRIMARY_DOMAIN]/$uuid
            // As you can see, we recommend using a subdomain as your webhook url. 
            
            // Alternatively, you can link:dir this domain directory to a particular domain and access through there
            // https://[PRIMARY_DOMAIN]/$pattern/$uuid
            
            // Verify webhook from GitHub
            if(\$_SERVER['REQUEST_METHOD'] !== 'POST' or @\$_GET['brick'] !== "$uuid") {
                Exception::throw_exception("Invalid endpoint met! please check your uuid and try again", "GitADMismatched");
                die;
            }

            \$main_branch = "main";

            print "GitAD Responds With: \\n";

            \$post = json_decode(\$_POST['payload']);

            if(!isset(\$post->pull_request)) {
                echo \$post?->action?->zen;
                die;
            }

            if(\$post->pull_request->state != "closed") {
                echo "Pull Request: " . \$post->pull_request->state;
                die;
            }

            shell_exec("git submodule init 2>&1 &");
            shell_exec("git checkout \$main_branch 2>&1 &");
            shell_exec('git fetch --all 2>&1 &');
            shell_exec("git reset --hard origin/\$main_branch 2>&1 &");
            
            print "Symlinks are being refreshed \\n";
            \$bob = LayConfig::server_data()->root . "bob";
            shell_exec("php \$bob link:refresh 2>&1 &");

            // push composer deployment for later execution to avoid 504 (timeout error)
            echo LayCron::new()
                ->job_id("update-composer-pkgs")
                ->every_minute()
                ->just_once()
                ->new_job("bob up_composer")['msg'];
            
            echo "<br> Done";
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