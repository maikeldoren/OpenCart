<?php

declare(strict_types=1);

namespace Mollie\Api\HttpAdapter;

interface MollieHttpAdapterPickerInterface
{
    /**
     * @param \GuzzleHttp\ClientInterface|\Mollie\Api\HttpAdapter\MollieHttpAdapterInterface $httpClient
     *
     * @return \Mollie\Api\HttpAdapter\MollieHttpAdapterInterface
     */
    public function pickHttpAdapter($httpClient);
}