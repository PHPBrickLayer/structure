<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayDir;


trait Domain
{
    public function domain(): void
    {
        if(!isset($this->tags['purge_domain']))
            return;

        $domain = $this->tags['purge_domain'][0] ?? null;

        if (!$domain)
            $this->plug->write_fail("No domain specified");

        if(trim(strtolower($domain)) == "default")
            $this->plug->write_fail("You cannot remove the default domain");

        $domain = explode(" ", ucwords($domain));
        $domain_id = strtolower(implode("-", $domain) . "-id");
        $domain = implode("", $domain);
        $domain_dir = $this->plug->server->domains . $domain;
        $exists = is_dir($domain_dir);

        if (!$exists)
            $this->plug->write_fail(
                "Domain directory *$domain_dir* does not exist!\n"
                . "Domain may have been deleted already\n"
                . "If domain's link is still being referrenced by *bob*, please run the command *php bob link:prune*"
            );

        $this->talk("- Updating domains entry in *{$this->plug->server->web}index.php*");
        $this->update_general_domain_entry($domain_id);

        $this->talk("- Purging $domain directory: *$domain_dir*");
        LayDir::unlink($domain_dir);

        new BobExec("link:prune --silent");
    }

    public function update_general_domain_entry(string $domain_id): void
    {
        $main_file = $this->plug->server->web . "index.php";

        $data = preg_replace(
            ['/Domain::new\(\)->create\([^)]*'. $domain_id . '[^)]*\);/s', '/\n\s*\n/'],
            ['', "\n\n"],
            file_get_contents($main_file),
        );

        file_put_contents($main_file, $data);
    }
}