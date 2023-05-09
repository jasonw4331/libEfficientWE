<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$value of method pocketmine\\\\promise\\\\PromiseResolver\\<array\\<string, array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>\\|float\\|int\\>\\>\\:\\:resolve\\(\\) expects array\\{chunks\\: array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>, time\\: float, blockCount\\: int\\}, array\\{chunks\\: array\\<int, pocketmine\\\\world\\\\format\\\\Chunk\\|null\\>, time\\: float, blockCount\\: int\\<0, max\\>\\} given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/libEfficientWE/shapes/Cone.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$value of method pocketmine\\\\promise\\\\PromiseResolver\\<array\\<string, array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>\\|float\\|int\\>\\>\\:\\:resolve\\(\\) expects array\\{chunks\\: array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>, time\\: float, blockCount\\: int\\}, array\\{chunks\\: array\\<int, pocketmine\\\\world\\\\format\\\\Chunk\\|null\\>, time\\: float, blockCount\\: int\\<0, max\\>\\} given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/libEfficientWE/shapes/Cuboid.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$value of method pocketmine\\\\promise\\\\PromiseResolver\\<array\\<string, array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>\\|float\\|int\\>\\>\\:\\:resolve\\(\\) expects array\\{chunks\\: array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>, time\\: float, blockCount\\: int\\}, array\\{chunks\\: array\\<int, pocketmine\\\\world\\\\format\\\\Chunk\\|null\\>, time\\: float, blockCount\\: int\\<0, max\\>\\} given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/libEfficientWE/shapes/Cylinder.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$value of method pocketmine\\\\promise\\\\PromiseResolver\\<array\\<string, array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>\\|float\\|int\\>\\>\\:\\:resolve\\(\\) expects array\\{chunks\\: array\\<pocketmine\\\\world\\\\format\\\\Chunk\\>, time\\: float, blockCount\\: int\\}, array\\{chunks\\: array\\<int, pocketmine\\\\world\\\\format\\\\Chunk\\|null\\>, time\\: float, blockCount\\: int\\<0, max\\>\\} given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/libEfficientWE/shapes/Sphere.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
