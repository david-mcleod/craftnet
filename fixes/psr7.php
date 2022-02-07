<?php

namespace GuzzleHttp\Psr7;

# Until Zendesk gets their act together. https://github.com/zendesk/zendesk_api_client_php/issues/470

/**
 *
 * @param string $resource
 * @param array  $options
 *
 * @return PumpStream|Stream|\Psr\Http\Message\StreamInterface
 */
function stream_for($resource = '', array $options = [])
{
    return Utils::streamFor($resource, $options);
}
