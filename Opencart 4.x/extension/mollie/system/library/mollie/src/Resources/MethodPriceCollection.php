<?php

declare(strict_types=1);

namespace Mollie\Api\Resources;

class MethodPriceCollection extends \Mollie\Api\Resources\BaseCollection
{
    /**
     * @return string|null
     */
    public function getCollectionResourceName()
    {
        return null;
    }
}