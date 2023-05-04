<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

use libEfficientWE\utils\Clipboard;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function floor;
use function morton3d_encode;

/**
 * @internal
 */
final class SphereCopyTask extends ChunksCopyTask{

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, protected float $radius, Clipboard $clipboard, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $onCompletion);
	}

	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Clipboard $clipboard) : array{
		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		$maxVector = $worldPos->addVector($clipboard->getWorldMax()->subtractVector($clipboard->getWorldMin()));
		$center = new Vector3($this->radius, $this->radius, $this->radius);

		// loop from 0 to max. if coordinate is in sphere, save fullblockId
		for($x = 0; $x <= $maxVector->x; ++$x){
			$ax = (int) floor($worldPos->x + $x);
			for($z = 0; $z <= $maxVector->z; ++$z){
				$az = (int) floor($worldPos->z + $z);
				for($y = 0; $y <= $maxVector->y; ++$y){
					$ay = (int) floor($worldPos->y + $y);
					if($center->distanceSquared(new Vector3($x, $y, $z)) <= $this->radius ** 2 && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		return $blocks;
	}
}
