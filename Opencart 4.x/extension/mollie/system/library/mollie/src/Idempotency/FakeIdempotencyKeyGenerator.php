<?php

declare(strict_types=1);

namespace Mollie\Api\Idempotency;

class FakeIdempotencyKeyGenerator implements \Mollie\Api\Idempotency\IdempotencyKeyGeneratorContract
{
    /** @var string */
    private $fakeKey;
    
    public function setFakeKey(string $fakeKey)
    {
        $this->fakeKey = $fakeKey;
    }
    
    public function generate()
    {
        return $this->fakeKey;
    }
}