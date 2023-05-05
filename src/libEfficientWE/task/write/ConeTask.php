<?php

declare(strict_types=1);

namespace libEfficientWE\task\write;

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
use function morton3d_decode;

/**
 * @internal
 */
final class ConeTask extends ChunksChangeTask{

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Vector3 $worldPos, Clipboard $clipboard, protected float $radius, protected float $height, protected int $facing, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $worldPos, $clipboard, $fill, $replaceAir, $onCompletion);
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Cone::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, array $fullBlocks, Vector3 $minVector, Vector3 $maxVector) : int{
		$changedBlocks = 0;
		$iterator = new SubChunkExplorer($manager);

		$coneTip = match($this->facing) {
			Facing::UP => $minVector->add($this->radius, $this->height, $this->radius),
			Facing::DOWN => $minVector->add($this->radius, 0, $this->radius),
			Facing::SOUTH => $minVector->add($this->radius, $this->radius, $this->height),
			Facing::NORTH => $minVector->add($this->radius, $this->radius, 0),
			Facing::EAST => $minVector->add($this->height, $this->radius, $this->radius),
			Facing::WEST => $minVector->add(0, $this->radius, $this->radius),
			default => throw new AssumptionFailedError("Unhandled facing $this->facing")
		};
		$axisVector = $coneTip->subtractVector(match($this->facing) {
			Facing::UP => new Vector3($this->radius, 0, $this->radius),
			Facing::DOWN => new Vector3($this->radius, $this->height, $this->radius),
			Facing::SOUTH => new Vector3($this->radius, $this->radius, 0),
			Facing::NORTH => new Vector3($this->radius, $this->radius, $this->height),
			Facing::EAST => new Vector3(0, $this->radius, $this->radius),
			Facing::WEST => new Vector3($this->height, $this->radius, $this->radius),
			default => throw new AssumptionFailedError("Unhandled facing $this->facing")
		})->normalize();

		foreach($fullBlocks as $mortonCode => $fullBlockId){
			[$x, $y, $z] = morton3d_decode($mortonCode);
			$ax = (int) floor($minVector->x + $x);
			$ay = (int) floor($minVector->y + $y);
			$az = (int) floor($minVector->z + $z);
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
