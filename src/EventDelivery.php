<?php

declare(strict_types=1);

namespace Pest\Realtime;

enum EventDelivery: string
{
    case Delivered = 'delivered';
    case Dropped = 'dropped';
    case NotSubscribed = 'not_subscribed';
}
