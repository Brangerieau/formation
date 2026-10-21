<?php

namespace App\Tests\Log;

use App\Log\TraceId;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class TraceIdTest extends TestCase
{
    public function testKeepsIncomingIdAndSharesItWithSubRequests(): void
    {
        $requestStack = new RequestStack([new Request(server: ['HTTP_X_TRACE_ID' => 'a1b2c3d4']), new Request()]);

        self::assertSame('a1b2c3d4', (new TraceId($requestStack))->get());
    }

    public function testGeneratesOneIdPerRequest(): void
    {
        $traceId = new TraceId(new RequestStack([new Request()]));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $traceId->get());
        self::assertSame($traceId->get(), $traceId->get());
    }

    public function testIgnoresInvalidIncomingId(): void
    {
        $traceId = new TraceId(new RequestStack([new Request(server: ['HTTP_X_TRACE_ID' => "x\ninjected"])]));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $traceId->get());
    }

    public function testAddsTraceToLogRecords(): void
    {
        $traceId = new TraceId(new RequestStack([new Request(server: ['HTTP_X_TRACE_ID' => 'abc'])]));
        $record = new LogRecord(new \DateTimeImmutable(), 'chat', Level::Info, 'Message reçu');

        self::assertSame(['trace' => 'abc'], $traceId($record)->extra);
        self::assertSame([], (new TraceId(new RequestStack()))($record)->extra);
    }
}
