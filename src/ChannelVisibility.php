<?php

declare(strict_types=1);

namespace Pest\Realtime;

enum ChannelVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Presence = 'presence';
    case PrivateEncrypted = 'private-encrypted';
}
