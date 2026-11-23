<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Controller\EmployeeController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EmployeeControllerAvailabilityTest extends TestCase
{
    public function testUnavailableStoreReturns501WithSpanishExplanation(): void
    {
        $controller = new EmployeeController();
        self::assertNull((new ReflectionProperty(EmployeeController::class, 'employees'))->getValue($controller));
        $method = new ReflectionMethod(EmployeeController::class, 'store');

        try {
            $method->invoke($controller);
            self::fail('Sin persistencia autorizada no debe exponerse el registro sintético M01.');
        } catch (HttpException $exception) {
            self::assertSame(Response::HTTP_NOT_IMPLEMENTED, $exception->getStatusCode());
            self::assertStringContainsString('no está implementado', $exception->getMessage());
            self::assertStringContainsString('persistencia autorizada', $exception->getMessage());
        }
    }

    public function testAttendanceAdapterIsOptionalAndNotWiredByDefault(): void
    {
        $controller = new EmployeeController();
        self::assertNull((new ReflectionProperty(EmployeeController::class, 'attendanceEvents'))->getValue($controller));
    }
}
