<?php

declare(strict_types=1);

namespace Pest\Realtime;

enum ConnectionStatus: string
{
    case Initialized = 'initialized';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Unavailable = 'unavailable';
    case Disconnected = 'disconnected';
    case Failed = 'failed';
}
