<?php

declare(strict_types=1);

namespace Modules\Identity\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;

/** AuthManager may outlive a request under Octane; explicitly discard its cached user. */
final class JwtGuard extends RequestGuard
{
    public function setRequest(Request $request): static
    {
        $this->forgetUser();
        parent::setRequest($request);

        return $this;
    }
}
