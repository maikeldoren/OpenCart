<?php

declare(strict_types=1);

namespace Mollie\Api\Resources;

class PaymentLinkCollection extends \Mollie\Api\Resources\CursorCollection
{
    /**
     * @return string
     */
    public function getCollectionResourceName()
    {
        return "payment_links";
    }
    
    /**
     * @return BaseResource
     */
    protected function createResourceObject()
    {
        return new \Mollie\Api\Resources\PaymentLink($this->client);
    }
}