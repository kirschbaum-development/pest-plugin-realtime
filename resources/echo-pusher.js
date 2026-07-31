(() => {
    if (window.__pestRealtime?.driver === 'echo-pusher') {
        return window.__pestRealtime.channels();
    }

    const candidates = [
        window.Echo?.connector?.pusher,
        ...(window.Pusher?.instances ?? []),
    ]
        .filter(Boolean)
        .sort(
            (left, right) =>
                Object.keys(right.channels?.channels ?? {}).length -
                Object.keys(left.channels?.channels ?? {}).length,
        );
    const pusher = candidates[0];

    if (!pusher) {
        return null;
    }

    if (!pusher.connection || typeof pusher.connection.emit !== 'function') {
        throw new Error('Pest Realtime found an incompatible Echo/Pusher connector.');
    }

    const channelId = (name, visibility) => {
        if (visibility === 'private-encrypted') {
            return `private-encrypted-${name}`;
        }

        if (visibility === 'private') {
            return `private-${name}`;
        }

        if (visibility === 'presence') {
            return `presence-${name}`;
        }

        return name;
    };

    const allowedStatuses = [
        'initialized',
        'connecting',
        'connected',
        'unavailable',
        'failed',
        'disconnected',
    ];
    const simulatedSocketId =
        pusher.connection.socket_id ??
        `pest-realtime.${Date.now()}.${Math.random().toString(16).slice(2)}`;

    // Echo whispers through channel.trigger(), which reaches the socket via
    // pusher.send_event(). The runtime stops the real client, so record the
    // attempts here instead of letting them disappear into a dead connection.
    const clientEvents = [];
    const sendEvent = typeof pusher.send_event === 'function'
        ? pusher.send_event.bind(pusher)
        : null;

    if (sendEvent) {
        pusher.send_event = (event, data, channel) => {
            if (typeof event === 'string' && event.startsWith('client-')) {
                clientEvents.push({
                    event: event.slice('client-'.length),
                    channel: channel ?? null,
                    payload: data ?? null,
                    connected: pusher.connection.state === 'connected',
                });
            }

            return sendEvent(event, data, channel);
        };
    }

    window.__pestRealtime = {
        driver: 'echo-pusher',
        clientEvents: () => clientEvents,
        channels: () => Object.keys(pusher.channels?.channels ?? {}),
        emit: (name, event, payload, visibility = 'public') => {
            const id = channelId(name, visibility);
            const channel = pusher.channels?.channels?.[id];

            if (!channel) {
                return 'not_subscribed';
            }

            if (pusher.connection.state !== 'connected') {
                return 'dropped';
            }

            // An encrypted channel decrypts through handleEvent and silently
            // discards a simulated plaintext payload, so dispatch to its bound
            // listeners directly instead.
            const encrypted = id.startsWith('private-encrypted-');

            if (!encrypted && typeof channel.handleEvent === 'function') {
                channel.handleEvent({
                    channel: id,
                    event,
                    data: payload,
                });
            } else if (typeof channel.emit === 'function') {
                channel.emit(event, payload, {});
            } else {
                throw new Error(`Pest Realtime found an incompatible channel ${id}.`);
            }

            return 'delivered';
        },
        members: (name) => {
            const channel = pusher.channels?.channels?.[`presence-${name}`];

            if (!channel) {
                return 'not_subscribed';
            }

            return channel.members?.members ?? {};
        },
        presence: (name, action, data) => {
            const id = `presence-${name}`;
            const channel = pusher.channels?.channels?.[id];

            if (!channel || typeof channel.handleEvent !== 'function') {
                return 'not_subscribed';
            }

            const events = {
                here: 'pusher_internal:subscription_succeeded',
                joined: 'pusher_internal:member_added',
                left: 'pusher_internal:member_removed',
            };

            channel.handleEvent({ channel: id, event: events[action], data });

            return 'ok';
        },
        subscriptionError: (name, visibility, data) => {
            const id = channelId(name, visibility);
            const channel = pusher.channels?.channels?.[id];

            if (!channel || typeof channel.emit !== 'function') {
                return 'not_subscribed';
            }

            // Pusher raises this on the channel itself when authorization is
            // refused, which is what Echo's error() callback binds to.
            channel.emit('pusher:subscription_error', data);

            return 'ok';
        },
        transitionTo: (nextStatus) => {
            if (!allowedStatuses.includes(nextStatus)) {
                throw new Error(`Unsupported realtime connection status: ${nextStatus}`);
            }

            const previousState = pusher.connection.state;

            if (previousState === nextStatus) {
                return nextStatus;
            }

            const data = nextStatus === 'connected'
                ? { socket_id: simulatedSocketId }
                : undefined;

            if (nextStatus === 'connected') {
                pusher.connection.socket_id = simulatedSocketId;
            }

            pusher.connection.state = nextStatus;
            pusher.connection.emit('state_change', {
                previous: previousState,
                current: nextStatus,
            });
            pusher.connection.emit(nextStatus, data);

            return nextStatus;
        },
        status: () => pusher.connection.state,
        socketId: () => simulatedSocketId,
    };

    pusher.disconnect();

    return window.__pestRealtime.channels();
})()
