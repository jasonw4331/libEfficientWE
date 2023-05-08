<?php
declare(strict_types=1);

namespace libEfficientWE\task\read;

use libEfficientWE\utils\Clipboard;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;

/**
 * @internal
 */
final class ConeCopyTask extends ChunksCopyTask{

	public function __construct(int $worldId, array $chunks, Clipboard $clipboard, protected float $radius, protected float $height, protected int $facing, \Closure $onCompletion) {
		parent::__construct($worldId, $chunks, $clipboard, $onCompletion);
	}

	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Clipboard $clipboard) : array{
		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		$maxVector = $worldPos->addVector($clipboard->getWorldMax()->subtractVector($clipboard->getWorldMin()));
		$coneTip = match ($this->facing) {
			Facing::UP => $worldPos->add($this->radius, $this->height, $this->radius),
			Facing::DOWN => $worldPos->add($this->radius, 0, $this->radius),
			Facing::SOUTH => $worldPos->add($this->radius, $this->radius, $this->height),
			Facing::NORTH => $worldPos->add($this->radius, $this->radius, 0),
			Facing::EAST => $worldPos->add($this->height, $this->radius, $this->radius),
			Facing::WEST => $worldPos->add(0, $this->radius, $this->radius),
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