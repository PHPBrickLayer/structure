<?php

namespace BrickLayer\Lay\Core\View\Annotate;

use BrickLayer\Lay\Core\View\Enums\DomainType;

/**
 * @psalm-type CurrentRoteDataMeta =  DomainType|string|array<int>|array{
 *       route: string,
 *       route_as_array: array<int>,
 *       route_has_end_slash: bool,
 *       domain_type: DomainType,
 *       domain_id: string,
 *       domain_uri: string,
 *       pattern: string
 *   }
 */
final class CurrentRouteData
{
    const ANNOTATE = [
        'route',
        'route_as_array',
        'route_has_end_slash',
        'domain_name',
        'domain_type',
        'domain_id',
        'domain_root',
        'domain_referrer',
        'domain_uri',
        'domain_base',
        'pattern',
        'plaster',
        'layout',
        '*',
    ];
}
