<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#5 \\$clipboard of method libEfficientWE\\\\task\\\\ChunksChangeTask\\:\\:setBlocks\\(\\) expects libEfficientWE\\\\utils\\\\Clipboard, mixed given\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/src/libEfficientWE/task/ChunksChangeTask.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
