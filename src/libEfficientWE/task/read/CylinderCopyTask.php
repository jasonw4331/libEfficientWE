<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

use Closure;
use libEfficientWE\utils\Clipboard;
use pocketmine\math\Axis;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function floor;
use function morton3d_encode;

class CylinderCopyTask extends ChunksCopyTask{

	/**
	 * @phpstan-param Axis::* $axis
	 */
	public function __construct(int $worldId, array $chunks, Clipboard $clipboard, protected float $radius, protected float $height, protected int $axis, Closure $onCompletion){
		parent::__construct($worldId, $chunks, $clipboard, $onCompletion);
	}

	protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos, Vector3 $worldMaxPos) : array{
		/** @var array<int, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($manager);

		$centerOfBase = match ($this->axis) {
			Axis::Y => $worldPos->add($this->radius, 0, $this->radius),
			Axis::X => $worldPos->add(0, $this->radius, $this->radius),
			Axis::Z => $worldPos->add($this->radius, $this->radius, 0)
		};

		// loop from min to max. if coordinate is in cylinder, save blockStateId
		for($x = 0; $x <= $worldMaxPos->x; ++$x){
			$ax = (int) floor($worldPos->x + $x);
			for($z = 0; $z <= $worldMaxPos->z; ++$z){
				$az = (int) floor($worldPos->z + $z);
				for($y = 0; $y <= $worldMaxPos->y; ++$y){
					$ay = (int) floor($worldPos->y + $y);
					// check if coordinate is in cylinder depending on axis
					$inCylinder = match ($this->axis) {
						Axis::Y => (new Vector2($centerOfBase->x, $centerOfBase->z))->distanceSquared(new Vector2($x, $z)) <= $this->radius ** 2 && $y <= $this->height,
						Axis::X => (new Vector2($centerOfBase->y, $centerOfBase->z))->distanceSquared(new Vector2($y, $z)) <= $this->radius ** 2 && $x <= $this->height,
						Axis::Z => (new Vector2($centerOfBase->x, $centerOfBase->y))->distanceSquared(new Vector2($x, $y)) <= $this->radius ** 2 && $z <= $this->height
					};
					if($inCylinder && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getBlockStateId($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		return $blocks;
	}
}
