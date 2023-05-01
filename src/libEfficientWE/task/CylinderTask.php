<?php
declare(strict_types=1);

namespace libEfficientWE\task;

use libEfficientWE\utils\Clipboard;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\World;

/**
 * @internal
 */
final class CylinderTask extends ChunksChangeTask {

	protected string $relativeCenter;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $relativeCenter, protected float $radius, protected float $height, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->relativeCenter = igbinary_serialize($relativeCenter) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	protected function setBlocks(SimpleChunkManager $manager, int $chunkX, int $chunkZ, Chunk $chunk, Clipboard $clipboard) : Chunk{
		/** @var Vector3 $relativeCenter */
		$relativeCenter = igbinary_unserialize($this->relativeCenter);

		$relativeCenter = $clipboard->getRelativePos()->addVector($relativeCenter);
		$relx = $relativeCenter->x;
		$rely = $relativeCenter->y;
		$relz = $relativeCenter->z;

		$iterator = new SubChunkExplorer($manager);

		foreach($clipboard->getFullBlocks() as $mortonCode => $fullBlockId) {
			[$x, $y, $z] = morton3d_decode($mortonCode);
			$ax = (int) floor($relx + $x);
			$ay = (int) floor($rely + $y);
			$az = (int) floor($relz + $z);
			if($fullBlockId !== null) {
				// make sure the chunk/block exists on this thread
				if($iterator->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID) {
					// if replaceAir is false, do not set blocks where the clipboard has air
					if($this->replaceAir || $fullBlockId !== VanillaBlocks::AIR()->getFullId()) {
						// if fill is false, ignore interior blocks on the clipboard
						$edgeOfCylinder = match ($this->axis) {
							Axis::Y => (new Vector2($relativeCenter->x, $relativeCenter->z))->distanceSquared(new Vector2($x, $z)) == $this->radius ** 2 && $y <= $this->height,
							Axis::X => (new Vector2($relativeCenter->y, $relativeCenter->z))->distanceSquared(new Vector2($y, $z)) == $this->radius ** 2 && $x <= $this->height,
							Axis::Z => (new Vector2($relativeCenter->x, $relativeCenter->y))->distanceSquared(new Vector2($x, $y)) == $this->radius ** 2 && $z <= $this->height,
							default => throw new AssumptionFailedError("Invalid axis $this->axis")
						};
						if($this->fill || $edgeOfCylinder) {
							$iterator->currentSubChunk?->setFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK, $fullBlockId);
							++$this->changedBlocks;
						}
					}
				}
			}
		}

		return $chunk;
	}
}