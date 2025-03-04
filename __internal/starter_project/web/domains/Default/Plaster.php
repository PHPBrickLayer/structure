<?php
namespace Web\Default;

use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Core\View\ViewBuilder;
use BrickLayer\Lay\Core\View\ViewCast;

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
        $this->route("index")->bind(function (ViewBuilder $builder) {
            $builder->page("title", "Homepage")
                ->page("desc", "This is the default homepage description")
                ->assets(
                    "@css/another.css",
                )
                ->body("homepage");
        });

        $this->route("another-page")->bind(function (ViewBuilder $builder) {
            $builder->page("title", "Another Page")
                ->page("desc", "This is another page's description")
                ->assets(
                    "@css/another.css",
                )
                ->body("another");
        });
    }
}
