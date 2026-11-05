<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

/** The gateway answered but did not approve a refund: retried by the job, then escalated to a human. */
final class RefundNotApproved extends RuntimeException {}
