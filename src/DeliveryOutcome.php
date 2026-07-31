<?php

declare(strict_types=1);

namespace Pest\Realtime;

enum DeliveryOutcome: string
{
    /** The page registered the channel and its simulated connection was connected. */
    case Delivered = 'delivered';

    /** The page registered the channel but its simulated connection was not connected. */
    case Dropped = 'dropped';

    /** This page did not register the channel. */
    case NotSubscribed = 'not_subscribed';

    /** The broadcast excluded this page's socket through `toOthers()`. */
    case Excluded = 'excluded';
}
