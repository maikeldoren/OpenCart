<?php

declare(strict_types=1);

namespace Mollie\Api\Idempotency;

interface IdempotencyKeyGeneratorContract
{
    public function generate();
}