<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge;

use BrickLayer\Lay\Core\Enums\LayLoop;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\LayCache;


trait StaticProd
{
    public function static_prod(): void
    {
        if(!isset($this->tags['purge_static_prod']))
            return;

        if(!is_dir($this->plug->server->domains))
            $this->plug->write_fail("Domain directory: *{$this->plug->server->domains}* does not exists!");

        $worked = false;
        $shared = $this->plug->server->shared . "static" . DIRECTORY_SEPARATOR . "prod";

        if(is_dir($shared)) {
            $this->plug->write_talk("Directory *shared*", ['silent' => true]);
            LayDir::unlink($shared);
            $worked = true;
            $this->plug->write_talk(" - Removed $shared", ['silent' => true]);
            print "\n";
        }

        LayDir::read($this->plug->server->domains, function($domain, $directory) use (&$worked) {
            if(
                $domain == "Api" ||  $domain == "GitAutoDeploy" || !is_dir($directory . $domain)
            ) return LayLoop::CONTINUE;

            $static = $directory . $domain . DIRECTORY_SEPARATOR . "static" . DIRECTORY_SEPARATOR . "prod";

            $this->plug->write_talk("Domain *$domain*", ['silent' => true]);

            if(is_dir($static)) {
                LayDir::unlink($static);
                $worked = true;
                $this->plug->write_talk(" - Removed $static", ['silent' => true]);
            }

            print "\n";
        });

        if(!$worked) {
            $this->plug->write_talk("No operations carried out. Directories may have been deleted already", ['silent' => true]);
            return;
        }

        LayCache::new()
            ->cache_file("deploy_cache", invalidate: true)
            ->dump(null);

        $this->plug->write_talk("Track Cache Invalidated", ['silent' => true]);
    }
}