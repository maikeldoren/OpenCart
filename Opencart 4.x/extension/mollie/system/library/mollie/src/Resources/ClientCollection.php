<?php

declare(strict_types=1);

namespace Mollie\Api\Resources;

class ClientCollection extends \Mollie\Api\Resources\CursorCollection
{
    /**
     * @return string
     */
    public function getCollectionResourceName()
    {
        return "clients";
    }
    
    /**
     * @return BaseResource
     */
    protected function createResourceObject()
    {
        return new \Mollie\Api\Resources\Client($this->client);
    }
}