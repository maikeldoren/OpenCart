<?php

declare(strict_types=1);

namespace Mollie\Api\Resources;

class MandateCollection extends \Mollie\Api\Resources\CursorCollection
{
    /**
     * @return string
     */
    public function getCollectionResourceName()
    {
        return "mandates";
    }
    
    /**
     * @return BaseResource
     */
    protected function createResourceObject()
    {
        return new \Mollie\Api\Resources\Mandate($this->client);
    }
    
    /**
     * @param string $status
     * @return array|\Mollie\Api\Resources\MandateCollection
     */
    public function whereStatus(string $status)
    {
        $collection = new self($this->client, 0, $this->_links);
        foreach ($this as $item) {
            if ($item->status === $status) {
                $collection[] = $item;
                $collection->count++;
            }
        }
        return $collection;
    }
}