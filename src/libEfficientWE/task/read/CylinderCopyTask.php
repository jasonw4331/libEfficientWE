<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

use libEfficientWE\utils\Clipboard;
use pocketmine\math\Axis;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function floor;
use function morton3d_encode;

class CylinderCopyTask extends ChunksCopyTask{

	public function __construct(int $worldId, array $chunks, Clipboard $clipboard, protected float $radius, protected float $height, protected int $axis, \Closure $onCompletion){
		parent::__construct($worldId, $chunks, $clipboard, $onCompletion);
	}

	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Clipboard $clipboard) : array{
		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		$maxVector = $worldPos->addVector($clipboard->getWorldMax()->subtractVector($clipboard->getWorldMin()));
		$centerOfBase = match ($this->axis) {
			Axis::Y => $worldPos->add($this->radius, 0, $this->radius),
			Axis::X => $worldPos->add(0, $this->radius, $this->radius),
			Axis::Z => $worldPos->add($this->radius, $this->radius, 0),
			default => throw new AssumptionFailedError("Invalid axis $this->axis")
		};

		// loop from min to max if coordinate is in cylinder, save fullblockId
		for($x = 0; $x <= $maxVector->x; ++$x){
			$ax = (int) floor($worldPos->x + $x);
			for($z = 0; $z <= $maxVector->z; ++$z){
				$az = (int) floor($worldPos->z + $z);
				for($y = 0; $y <= $maxVector->y; ++$y){
					$ay = (int) floor($worldPos->y + $y);
					// check if coordinate is in cylinder depending on axis
					$inCylinder = match ($this->axis) {
						Axis::Y => (new Vector2($centerOfBase->x, $centerOfBase->z))->distanceSquared(new Vector2($x, $z)) <= $this->radius ** 2 && $y <= $this->height,
						Axis::X => (new Vector2($centerOfBase->y, $centerOfBase->z))->distanceSquared(new Vector2($y, $z)) <= $this->radius ** 2 && $x <= $this->height,
						Axis::Z => (new Vector2($centerOfBase->x, $centerOfBase->y))->distanceSquared(new Vector2($x, $y)) <= $this->radius ** 2 && $z <= $this->height,
						default => throw new AssumptionFailedError("Invalid axis $this->axis")
					};
					if($inCylinder && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		return $blocks;
	}
}
