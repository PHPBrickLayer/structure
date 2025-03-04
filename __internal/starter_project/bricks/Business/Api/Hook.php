<?php
declare(strict_types=1);

namespace Bricks\Business\Api;

use BrickLayer\Lay\Core\Api\ApiHooks;
use Bricks\Business\Controller\Newsletters;
use Bricks\Business\Controller\Prospects;

class Hook extends ApiHooks
{
    public function hooks(): void
    {
        $this->engine
            ->post("subscribe-newsletter")->bind(fn() => Newsletters::new()->add())
            ->get("list-subscribers")->bind(fn() => Newsletters::new()->list())
            ->post("contact")->bind(fn() => Prospects::new()->contact_us())
            ->post("get-started")->bind(fn() => Prospects::new()->add());
    }
}