<?php
declare(strict_types=1);

namespace libEfficientWE\task\read;

use libEfficientWE\utils\Clipboard;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;

/**
 * @internal
 */
final class ConeCopyTask extends ChunksCopyTask{

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, protected float $radius, protected float $height, protected int $facing, Clipboard $clipboard, \Closure $onCompletion) {
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $onCompletion);
	}

	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Clipboard $clipboard) : array{
		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		$coneTip = match ($this->facing) {
			Facing::UP => $worldPos->add(0, $this->height, 0),
			Facing::DOWN => $worldPos->add($this->radius, 0, $this->radius),
			Facing::SOUTH => $worldPos->add($this->radius, 0, 0),
			Facing::NORTH => $worldPos->add($this->radius, 0, $this->radius * 2),
			Facing::EAST => $worldPos->add(0, 0, $this->radius),
			Facing::WEST => $worldPos->add($this->radius * 2, 0, $this->radius),
			default => throw new AssumptionFailedError("Unhandled facing $this->facing")
		};
		$axisVector = $coneTip->subtractVector($worldPos)->normalize();

		// loop from 0 to max. if coordinate is in cone, save fullblockId
		for($x = 0; $x <= $maxVector->x; ++$x){
			$ax = (int) floor($worldPos->x + $x);
			for($z = 0; $z <= $maxVector->z; ++$z){
				$az = (int) floor($worldPos->z + $z);
				for($y = 0; $y <= $maxVector->y; ++$y){
					$ay = (int) floor($worldPos->y + $y);
					// check if coordinate is in cylinder depending on axis
					$relativePoint = new Vector3($x, $y, $z);
					$relativeVector = $relativePoint->subtractVector($coneTip);
					$projectionLength = $axisVector->dot($relativeVector);
					$projection = $axisVector->multiply($projectionLength);
					$orthogonalVector = $relativeVector->subtractVector($projection);
					$orthogonalDistance = $orthogonalVector->length();
					$maxRadiusAtHeight = $projectionLength / $this->height * $this->radius;
					$inCone = $orthogonalDistance <= $maxRadiusAtHeight;

					if($inCone && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		return $blocks;
	}
}