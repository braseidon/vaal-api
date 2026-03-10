<?php

namespace Braseidon\VaalApi\Enums;

/**
 * Game realms supported by the GGG API.
 *
 * Most endpoints accept an optional realm as a path segment.
 * When omitted, the API defaults to PC.
 */
enum Realm: string
{
    case Pc   = 'pc';
    case Xbox = 'xbox';
    case Sony = 'sony';
    case Poe2 = 'poe2';
}
