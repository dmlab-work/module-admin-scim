<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Exception;

use DmLab\AdminScim\Exception\ScimException;
use PHPUnit\Framework\TestCase;

class ScimExceptionTest extends TestCase
{
    public function testCarriesStatusDetailAndScimType(): void
    {
        $e = new ScimException(400, 'Bad value.', 'invalidValue');

        self::assertSame(400, $e->getHttpStatus());
        self::assertSame('Bad value.', $e->getMessage());
        self::assertSame('invalidValue', $e->getScimType());
    }

    public function testScimTypeDefaultsToNull(): void
    {
        self::assertNull((new ScimException(500, 'Boom.'))->getScimType());
    }

    public function testNamedConstructorsSetStatusAndScimType(): void
    {
        self::assertSame([400, null], $this->pair(ScimException::badRequest('x')));
        self::assertSame([400, 'invalidValue'], $this->pair(ScimException::badRequest('x', 'invalidValue')));
        self::assertSame([401, null], $this->pair(ScimException::unauthorized()));
        self::assertSame([404, null], $this->pair(ScimException::notFound()));
        self::assertSame([409, 'uniqueness'], $this->pair(ScimException::conflict('dup')));
        self::assertSame([500, null], $this->pair(ScimException::internal()));
        self::assertSame([501, null], $this->pair(ScimException::notImplemented()));
    }

    public function testPreservesPreviousThrowable(): void
    {
        $previous = new \RuntimeException('root cause');
        $e = new ScimException(500, 'wrap', null, $previous);

        self::assertSame($previous, $e->getPrevious());
    }

    /**
     * @return array{0:int,1:?string}
     */
    private function pair(ScimException $e): array
    {
        return [$e->getHttpStatus(), $e->getScimType()];
    }
}
