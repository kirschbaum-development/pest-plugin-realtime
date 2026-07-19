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

    window.__pestRealtime = {
        driver: 'echo-pusher',
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

            if (typeof channel.handleEvent === 'function') {
                channel.handleEvent({
                    channel: id,
                    event,
                    data: payload,
                });
            } else if (typeof channel.emit === 'function') {
                channel.emit(event, payload);
            } else {
                throw new Error(`Pest Realtime found an incompatible channel ${id}.`);
            }

            return 'delivered';
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
