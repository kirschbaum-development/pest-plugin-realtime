<?php

declare(strict_types=1);

namespace Pest\Realtime;

enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Failed = 'failed';
    case Reconnecting = 'reconnecting';
}
