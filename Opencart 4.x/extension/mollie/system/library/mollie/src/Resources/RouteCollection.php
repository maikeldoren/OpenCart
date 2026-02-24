<?php

declare(strict_types=1);

namespace Mollie\Api\Resources;

class RouteCollection extends \Mollie\Api\Resources\CursorCollection
{
    /**
     * @return string
     */
    public function getCollectionResourceName()
    {
        return "route";
    }
    
    /**
     * @return BaseResource
     */
    protected function createResourceObject()
    {
        return new \Mollie\Api\Resources\Route($this->client);
    }
}