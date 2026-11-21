<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Contracts;

use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\PushSubscription;

/**
 * Encrypts and delivers messages to push services. The default is MinishlinkTransport
 * (minishlink/web-push); bind your own to use another library or a relay.
 */
interface PushTransport
{
    /**
     * @param  list<array{subscription: PushSubscription, payload: string, options: array<string, int|string>}>  $messages
     * @return list<PushReport> one report per message
     */
    public function send(array $messages): array;
}
