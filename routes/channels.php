<?php

declare(strict_types=1);

/*
 | Broadcast channels are declared by the modules that own them, not here.
 | Chat: Modules/Chat/routes/channels.php (private-conversation.{uuid}, private-agents.queue),
 | loaded by Modules\Chat\Providers\ChatServiceProvider. The auth route is registered once in
 | bootstrap/app.php (withBroadcasting): POST /api/broadcasting/auth, middleware api, auth:api,
 | throttle.sliding:broadcasting-auth.
 */
