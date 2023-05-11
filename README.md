# libEfficientWE

[![Poggit-CI](https://poggit.pmmp.io/ci.shield/jasonwynn10/libEfficientWE/libEfficientWE)](https://poggit.pmmp.io/ci/jasonwynn10/libEfficientWE/libEfficientWE)

A library for efficient world editing operations on PocketMine servers
## Usage
This viron was made for developers to efficiently edit large areas of blocks in a world without causing lag.
*NOTE*: This library does not handle undo/redo operations, that is up to the developer to implement.

### API
#### Creating a shape
The following methods are used to create a shape object:
```php
$cuboid = Cuboid::fromAABB(new AxisAlignedBB($x1, $y1, $z1, $x2, $y2, $z2));
$cuboid2 = Cuboid::fromVector($vector1, $vector2);
```

#### Executing a block operation
The following methods are used to execute a block operation object:
```php
/** @var Shape $shape */
$shape->copy($world, $x, $y, $z);
$shape->rotate($world, $x, $y, $z, $axis, $degrees);
```

## Limitations
This library operates using morton codes meaning that it is limited to 21 bit integers for the x, y, and z coordinates.
This means that the absolute maximum number of blocks can operate on for any one axis is 2^20 - 1 or 1048575 before indexing collisions begin to occur.
This should not be an issue for most world editing plugins due to the requirement of user input, but it is something to be aware of and to test for in your plugin.