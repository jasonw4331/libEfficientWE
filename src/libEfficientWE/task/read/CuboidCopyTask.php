<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

use libEfficientWE\utils\Clipboard;
use pocketmine\math\Vector3;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function floor;
use function igbinary_unserialize;
use function morton3d_encode;

/**
 * @internal
 */
final class CuboidCopyTask extends ChunksCopyTask{

	/**
	 * @inheritDoc
	 */
	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos) : array{
		/** @var Clipboard $clipboard */
		$clipboard = igbinary_unserialize($this->clipboard);

		$worldLowCorner = $worldPos;
		$worldHighCorner = $worldPos->addVector($clipboard->getWorldMax()->subtractVector($clipboard->getWorldMin()));

		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		for($x = 0; $x <= $worldHighCorner->x; ++$x){
			$ax = (int) floor($worldLowCorner->x + $x);
			for($z = 0; $z <= $worldHighCorner->z; ++$z){
				$az = (int) floor($worldLowCorner->z + $z);
				for($y = 0; $y <= $worldHighCorner->y; ++$y){
					$ay = (int) floor($worldLowCorner->y + $y);
					if($subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		return $blocks;
	}
}
