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

    if (!pusher?.connection || typeof pusher.connection.emit !== 'function') {
        throw new Error(
            'Pest Realtime could not find an Echo/Pusher connector. Ensure the page creates its Echo subscriptions before installing the simulator.',
        );
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

    const pusherStateFor = (status) => {
        if (status === 'reconnecting') {
            return 'connecting';
        }

        return status;
    };

    let status = 'disconnected';

    window.__pestRealtime = {
        driver: 'echo-pusher',
        channels: () => Object.keys(pusher.channels?.channels ?? {}),
        emit: (name, event, payload, visibility = 'public') => {
            if (status !== 'connected') {
                return false;
            }

            const id = channelId(name, visibility);
            const channel = pusher.channels?.channels?.[id];

            if (!channel || typeof channel.emit !== 'function') {
                throw new Error(
                    `Pest Realtime could not find subscribed ${visibility} channel ${id}. Active channels: ${window.__pestRealtime.channels().join(', ')}`,
                );
            }

            channel.emit(event, payload);

            return true;
        },
        transitionTo: (nextStatus) => {
            const allowed = [
                'connected',
                'disconnected',
                'failed',
                'reconnecting',
            ];

            if (!allowed.includes(nextStatus)) {
                throw new Error(`Unsupported realtime connection status: ${nextStatus}`);
            }

            const previousState = pusher.connection.state;
            const nextState = pusherStateFor(nextStatus);

            status = nextStatus;
            pusher.connection.state = nextState;
            pusher.connection.emit('state_change', {
                previous: previousState,
                current: nextState,
            });

            return status;
        },
        status: () => status,
    };

    pusher.disconnect();

    return window.__pestRealtime.channels();
})()
