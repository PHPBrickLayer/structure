<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\Libs\LayCache;
use BrickLayer\Lay\Libs\LayDir;


trait StaticProd
{
    public function static_prod(): void
    {
        if(!isset($this->tags['purge_static_prod']))
            return;

        foreach ($this->plug->server->domains as $domain) {
            if(
                    $domain == "Api"
                ||  $domain == "GitAutoDeploy"
                ||  $domain == "."
                ||  $domain == ".."
                || !is_dir($domain)
            ) continue;

            $static = $this->plug->server->domains . $domain . DIRECTORY_SEPARATOR . "static" . DIRECTORY_SEPARATOR . "prod";
            $shared = $this->plug->server->shared . "static" . DIRECTORY_SEPARATOR . "prod";

            $this->plug->write_talk("Domain *$domain*", ['silent' => true]);

            if(is_dir($static)) {
                rmdir($static);
                $this->plug->write_talk("Removed: $static", ['silent' => true]);
            }

            if(is_dir($shared)) {
                rmdir($shared);
                $this->plug->write_talk("Removed: $shared", ['silent' => true]);
            }

        }

        LayCache::new()
            ->cache_file("deploy_cache", invalidate: true)
            ->dump("");

        $this->plug->write_talk("Track Cache Invalidated", ['silent' => true]);
    }
}