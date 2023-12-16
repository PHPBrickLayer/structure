<?php

namespace web\domains\Default;

use BrickLayer\Lay\core\view\DomainResource;
use BrickLayer\Lay\core\view\ViewBuilder;
use BrickLayer\Lay\core\view\ViewCast;

class Plaster extends ViewCast
{
    public function init_pages(): void
    {
        $this->builder->init_start()
            ->body_attr("dark", 'id="body-id"')
            ->local("logo", DomainResource::get()->shared->img_default->logo)
            ->local("section", "app")
            ->init_end();
    }

    public function pages(): void
    {
        $this->builder->route("index")->bind(function (ViewBuilder $builder) {
            $builder
                ->page("title", "Homepage")
                ->page("desc", "This is the default homepage description")
                ->assets(
                    "@css/another.css",
                )
                ->body("homepage");
        });

        $this->builder->route("another-page")->bind(function (ViewBuilder $builder) {
            $builder->connect_db()
                ->page("title", "Another Page")
                ->page("desc", "This is another page's description")
                ->assets(
                    "@css/another.css",
                )
                ->body("another");
        });
    }

    public function default(): void
    {
        $this->builder->route($this->builder::DEFAULT_ROUTE)->bind(function (ViewBuilder $builder){
            $builder->page('title', $this->builder->request('route') . " - Page not Found")
                ->body_attr("default-home")
                ->local("section", "error")
                ->body('error');
        });
    }
}