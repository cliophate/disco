<?php

namespace Tests\Feature;

use App\Music\Http\BoundedResponseBody;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class BoundedResponseBodyTest extends TestCase
{
    public function test_it_rejects_a_declared_oversized_body_before_reading_it(): void
    {
        $stream = Utils::streamFor('small');
        $response = new Response(new PsrResponse(200, ['Content-Length' => '11'], $stream));

        try {
            BoundedResponseBody::read($response, 10, 'Response was too large.');
            $this->fail('An oversized declared body must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Response was too large.', $exception->getMessage());
        }

        $this->assertFalse($stream->isReadable());
    }

    public function test_it_stops_an_undeclared_oversized_stream_at_the_limit(): void
    {
        $stream = Utils::streamFor('eleven-byte');
        $response = new Response(new PsrResponse(200, [], $stream));

        try {
            BoundedResponseBody::read($response, 10, 'Response was too large.');
            $this->fail('An oversized streamed body must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Response was too large.', $exception->getMessage());
        }

        $this->assertFalse($stream->isReadable());
    }

    public function test_it_accepts_and_closes_a_body_at_the_exact_limit(): void
    {
        $stream = Utils::streamFor('ten-bytes!');
        $response = new Response(new PsrResponse(200, [], $stream));

        $this->assertSame('ten-bytes!', BoundedResponseBody::read($response, 10, 'Response was too large.'));
        $this->assertFalse($stream->isReadable());
    }
}
