<?php
declare(strict_types=1);

namespace res\server\view;

use BrickLayer\Lay\core\LayConfig;
use BrickLayer\Lay\core\view\ViewBuilder;
use BrickLayer\Lay\core\view\ViewCast;

class DefaultViews extends ViewCast
{
    private object $site_data;
    private object $client;

    public function init_pages(): void
    {
        $layConfig = LayConfig::new();
        $this->site_data = $layConfig::site_data();
        $this->client = $layConfig::res_client();

        $this->builder->init_start()
            ->page('type', 'front')
            ->body_attr("dark")
            ->local("section", "app")
            ->local("others", $this->site_data->others)
            ->local("img", $this->client->front->img)
            ->local("img_custom", $this->client->custom->img)
            ->local("logo", $this->site_data->img->logo)
        ->init_end();
    }



    public function pages(): void
    {
        $this->builder->route("index")->bind(function (ViewBuilder $builder) {
            $builder->connect_db()
                ->page("title", "Homepage")
                ->page("desc", "This is the default homepage description")
                ->body("homepage");
        });

        $this->builder->route("another-page")->bind(function (ViewBuilder $builder) {
            $builder->connect_db()
                ->page("title", "Another Page")
                ->page("desc", "This is another page's description")
                ->body("another");
        });
    }

    public function default(): void
    {
        $this->builder->route($this->builder::DEFAULT_ROUTE)->bind(function (ViewBuilder $builder){
            $builder->page('title', $this->builder->request('route') . " - Page not Found")
                ->body_attr("defult-home")
                ->local("current_page", "error")
                ->local("section", "error")
                ->body('error');
        });
    }
}
