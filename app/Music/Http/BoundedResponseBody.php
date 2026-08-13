<?php

namespace App\Music\Http;

use Illuminate\Http\Client\Response;
use RuntimeException;

class BoundedResponseBody
{
    public static function read(Response $response, int $maximumBytes, string $exceptionMessage, int $timeoutSeconds = 30): string
    {
        $contentLength = trim($response->header('Content-Length', ''));
        $stream = $response->toPsrResponse()->getBody();
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maximumBytes) {
            $stream->close();

            throw new RuntimeException($exceptionMessage);
        }

        $body = '';
        $deadline = hrtime(true) + max(1, $timeoutSeconds) * 1_000_000_000;
        try {
            while (! $stream->eof()) {
                if (hrtime(true) > $deadline) {
                    throw new RuntimeException('Provider response body timed out.');
                }
                $body .= $stream->read(min(64 * 1024, $maximumBytes - strlen($body) + 1));
                if (strlen($body) > $maximumBytes) {
                    throw new RuntimeException($exceptionMessage);
                }
                if (hrtime(true) > $deadline) {
                    throw new RuntimeException('Provider response body timed out.');
                }
            }
        } finally {
            $stream->close();
        }

        return $body;
    }
}
