<?php

declare(strict_types=1);

namespace libEfficientWE\task\write;

use libEfficientWE\shapes\Cylinder;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Vector2;
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
final class CylinderTask extends ChunksChangeTask{

	protected string $worldPos;
	protected string $centerOfBase;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $worldPos, protected float $radius, protected float $height, Vector3 $centerOfBase, protected int $axis, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->worldPos = igbinary_serialize($worldPos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
		$this->centerOfBase = igbinary_serialize($centerOfBase) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Cylinder::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, Clipboard $clipboard) : int{
		$changedBlocks = 0;
		/** @var Vector3 $worldPos */
		$worldPos = igbinary_unserialize($this->worldPos);
		/** @var Vector3 $centerOfBase */
		$centerOfBase = igbinary_unserialize($this->centerOfBase);

		$minVector = match ($this->axis) {
			Axis::Y => $centerOfBase->subtract($this->radius, 0, $this->radius),
			Axis::X => $centerOfBase->subtract(0, $this->radius, $this->radius),
			Axis::Z => $centerOfBase->subtract($this->radius, $this->radius, 0),
			default => throw new AssumptionFailedError("Invalid axis $this->axis")
		};

		$worldPos = $minVector->addVector($worldPos);
		$minX = $worldPos->x;
		$minY = $worldPos->y;
		$minZ = $worldPos->z;

		$iterator = new SubChunkExplorer($manager);

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
						// if fill is false, ignore interior blocks on the clipboard
						$edgeOfCylinder = match ($this->axis) {
							Axis::Y => (new Vector2($minX, $minZ))->distanceSquared(new Vector2($ax, $az)) >= $this->radius ** 2 && $y <= $this->height,
							Axis::X => (new Vector2($minY, $minZ))->distanceSquared(new Vector2($ay, $az)) >= $this->radius ** 2 && $x <= $this->height,
							Axis::Z => (new Vector2($minX, $minY))->distanceSquared(new Vector2($ax, $ay)) >= $this->radius ** 2 && $z <= $this->height,
							default => throw new AssumptionFailedError("Invalid axis $this->axis")
						};
						if($this->fill || $edgeOfCylinder){
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
