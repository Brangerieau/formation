<?php

namespace App\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One id per chat message, shared by the /chat request and the /mcp requests it makes,
 * added to every log record as extra.trace.
 */
#[AsMonologProcessor]
class TraceId
{
    public const string HEADER = 'X-Trace-Id';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function get(): ?string
    {
        // Main request: API sub-requests run by the MCP tools keep the same id.
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        if (!$request->attributes->has('_trace_id')) {
            $incoming = $request->headers->get(self::HEADER, '');
            $request->attributes->set('_trace_id', preg_match('/^[\w-]{1,64}$/', $incoming) ? $incoming : bin2hex(random_bytes(4)));
        }

        return $request->attributes->get('_trace_id');
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $trace = $this->get();

        return null === $trace ? $record : $record->with(extra: ['trace' => $trace] + $record->extra);
    }
}
