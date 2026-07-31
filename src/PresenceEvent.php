<?php

declare(strict_types=1);

namespace Pest\Realtime;

/**
 * The membership changes a presence channel can be driven through.
 *
 * Echo surfaces these as `here()`, `joining()`, and `leaving()`.
 */
enum PresenceEvent: string
{
    case Here = 'here';
    case Joined = 'joined';
    case Left = 'left';
}
