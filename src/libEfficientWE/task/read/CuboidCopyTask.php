<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

use pocketmine\math\Vector3;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function floor;
use function morton3d_encode;

/**
 * @internal
 */
final class CuboidCopyTask extends ChunksCopyTask{

	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Vector3 $worldMaxPos) : array{
		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		for($x = 0; $x <= $worldMaxPos->x; ++$x){
			$ax = (int) floor($worldPos->x + $x);
			for($z = 0; $z <= $worldMaxPos->z; ++$z){
				$az = (int) floor($worldPos->z + $z);
				for($y = 0; $y <= $worldMaxPos->y; ++$y){
					$ay = (int) floor($worldPos->y + $y);
					if($subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		return $blocks;
	}
}
