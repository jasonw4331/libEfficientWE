<?php

declare(strict_types=1);

namespace libEfficientWE\task;

use libEfficientWE\shapes\Cone;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use function floor;
use function igbinary_serialize;
use function igbinary_unserialize;
use function morton3d_decode;

/**
 * @internal
 */
final class ConeTask extends ChunksChangeTask{

	private string $worldPos;
	private string $centerOfBase;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $worldPos, protected float $radius, protected float $height, Vector3 $centerOfBase, protected int $facing, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->worldPos = igbinary_serialize($worldPos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
		$this->centerOfBase = igbinary_serialize($centerOfBase) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Cone::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, Clipboard $clipboard) : int{
		$changedBlocks = 0;
		/** @var Vector3 $worldPos */
		$worldPos = igbinary_unserialize($this->worldPos);
		/** @var Vector3 $centerOfBase */
		$centerOfBase = igbinary_unserialize($this->centerOfBase);

		$minVector = match ($this->facing) {
			Facing::UP => $centerOfBase->subtract($this->radius, 0, $this->radius),
			Facing::DOWN => $centerOfBase->subtract($this->radius, $this->height, $this->radius),
			Facing::SOUTH => $centerOfBase->subtract($this->radius, $this->radius, 0),
			Facing::NORTH => $centerOfBase->subtract($this->radius, $this->radius, $this->height),
			Facing::EAST => $centerOfBase->subtract(0, $this->radius, $this->radius),
			Facing::WEST => $centerOfBase->subtract($this->height, $this->radius, $this->radius),
			default => throw new AssumptionFailedError("Invalid facing: $this->facing")
		};

		$worldPos = $minVector->addVector($worldPos);
		$minX = $worldPos->x;
		$minY = $worldPos->y;
		$minZ = $worldPos->z;

		$iterator = new SubChunkExplorer($manager);

		$coneTip = match ($this->facing) {
			Facing::UP => $centerOfBase->add(0, $this->height, 0),
			Facing::DOWN => $centerOfBase->subtract(0, $this->height, 0),
			Facing::SOUTH => $centerOfBase->add(0, 0, $this->height),
			Facing::NORTH => $centerOfBase->subtract(0, 0, $this->height),
			Facing::EAST => $centerOfBase->add($this->height, 0, 0),
			Facing::WEST => $centerOfBase->subtract($this->height, 0, 0),
			default => throw new AssumptionFailedError("Unhandled facing $this->facing")
		};
		$axisVector = (new Vector3(0, -$this->height, 0))->normalize();

		foreach($clipboard->getFullBlocks() as $mortonCode => $fullBlockId){
			[$x, $y, $z] = morton3d_decode($mortonCode);
			$ax = (int) floor($minX + $x);
			$ay = (int) floor($minY + $y);
			$az = (int) floor($minZ + $z);
			if($fullBlockId !== null){
				// make sure the chunk/block exists on this thread
				if($iterator->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
					// if replaceAir is false, do not set blocks where the clipboard has air
					if($this->replaceAir || $fullBlockId !== VanillaBlocks::AIR()->getFullId()){
						// if fill is false, ignore interior blocks on the clipboard in spherical pattern
						$relativePoint = match ($this->facing) {
							Facing::UP => new Vector3($x, $y, $z),
							Facing::DOWN => new Vector3($x, $this->height - $y, $z),
							Facing::SOUTH => new Vector3($x, $z, $y),
							Facing::NORTH => new Vector3($x, $z, $this->height - $y),
							Facing::EAST => new Vector3($y, $z, $x),
							Facing::WEST => new Vector3($this->height - $y, $z, $x),
							default => throw new AssumptionFailedError("Unhandled facing $this->facing")
						};

						$relativeVector = $relativePoint->subtractVector($coneTip);
						$projectionLength = $axisVector->dot($relativeVector);
						$projection = $axisVector->multiply($projectionLength);
						$orthogonalVector = $relativeVector->subtractVector($projection);
						$orthogonalDistance = $orthogonalVector->length();
						$maxRadiusAtHeight = $projectionLength / $this->height * $this->radius;
						$isEdgeOfCone = $orthogonalDistance >= $maxRadiusAtHeight;

						if($this->fill || $isEdgeOfCone){
							$iterator->currentSubChunk?->setFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK, $fullBlockId);
							++$changedBlocks;
						}
					}
				}
			}
		}

		return $changedBlocks;
	}
}
