<?php declare(strict_types=1);

namespace Lhsazevedo\PHPStan;

use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

class Sh4ObjTestReadWritePropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        $classname = $property->getDeclaringClass()->getName();

        return $classname === 'Lhsazevedo\Sh4ObjTest\TestCase'
            && in_array($propertyName, [
                'initializations',
                'testRelocations',
                'expectations',
                'entry',
                'randomizeMemory',
                'forceStop',
            ]);
    }

	public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

	public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }
}