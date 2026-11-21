<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One chunk of WebPush::queue(): sends the message to each notifiable's devices.
 * Eloquent notifiables travel as their ids and are loaded again when the job runs.
 *
 * @internal
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  iterable<object>  $notifiables
     */
    public function __construct(
        public iterable $notifiables,
        public WebPushMessage $message,
    ) {}

    public function handle(WebPush $push): void
    {
        foreach ($this->notifiables as $notifiable) {
            $push->send($notifiable, $this->message);
        }
    }
}
