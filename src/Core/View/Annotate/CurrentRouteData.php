<?php

namespace BrickLayer\Lay\Core\View\Annotate;

final class CurrentRouteData
{
    const ANNOTATE = [
        'route' => 'string',
        'route_as_array' => 'string',
        'route_has_end_slash' => 'string',
        'domain_name' => 'string',
        'domain_type' => 'string',
        'domain_id' => 'string',
        'domain_root' => 'string',
        'domain_referrer' => 'string',
        'domain_uri' => 'string',
        'domain_base' => 'string',
        'pattern' => 'string',
        'plaster' => 'string',
        'layout' => 'string',
        '*' => 'array',
    ];
}
