<?php

/**
 * This file contains Psalm type definitions that can be reused across the codebase.
 * @psalm-immutable
 */

namespace BrickLayer\Lay\Types;

use BrickLayer\Lay\Core\View\Enums\DomainType;

/**
 * @psalm-type CurrentRouteResponse = string|DomainType|array{
 *    route: string,
 *    route_as_array: array<int, string>,
 *    route_has_end_slash: bool,
 *    domain_name: string,
 *    domain_type: DomainType,
 *    domain_id: string,
 *    domain_root: string,
 *    domain_referrer: string,
 *    domain_uri: string,
 *    domain_base: string,
 *    pattern: string,
 *    plaster: string,
 *    layout: string,
 * }
 */

/**
 * @psalm-type GetRouteKeyType="route"|"route_as_array"|"route_has_end_slash"|"domain_name"|"domain_type"|"domain_id"|"domain_root"|"domain_referrer"|"domain_uri"|"domain_base"|"pattern"|"plaster"|"layout"|"*"
 */

/**
 * @psalm-type CoreKey="use_lay_script"|"skeleton"|"append_site_name"|"allow_page_embed"|"page_embed_whitelist"
 */

class PsalmTypes {}