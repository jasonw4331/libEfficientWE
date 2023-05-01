<?php

declare(strict_types=1);

namespace libEfficientWE\task;

use libEfficientWE\shapes\Cuboid;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\VanillaBlocks;
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
final class CuboidTask extends ChunksChangeTask{

	protected string $worldPos;
	protected string $lowCorner;
	protected string $highCorner;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $worldPos, Vector3 $lowCorner, Vector3 $highCorner, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->worldPos = igbinary_serialize($worldPos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
		$this->lowCorner = igbinary_serialize($lowCorner) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
		$this->highCorner = igbinary_serialize($highCorner) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Cuboid::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, int $chunkX, int $chunkZ, Chunk $chunk, Clipboard $clipboard) : Chunk{
		/** @var Vector3 $worldPos */
		$worldPos = igbinary_unserialize($this->worldPos);
		/** @var Vector3 $lowCorner */
		$lowCorner = igbinary_unserialize($this->lowCorner);
		/** @var Vector3 $highCorner */
		$highCorner = igbinary_unserialize($this->highCorner);

		$worldLowCorner = $lowCorner->addVector($worldPos);

		$minX = $lowCorner->x;
		$minY = $lowCorner->y;
		$minZ = $lowCorner->z;

		$maxX = $highCorner->x;
		$maxY = $highCorner->y;
		$maxZ = $highCorner->z;

		$iterator = new SubChunkExplorer($manager);

		foreach($clipboard->getFullBlocks() as $mortonCode => $fullBlockId){
			[$x, $y, $z] = morton3d_decode($mortonCode);
			$ax = (int) floor($worldLowCorner->x + $x);
			$ay = (int) floor($worldLowCorner->y + $y);
			$az = (int) floor($worldLowCorner->z + $z);
			if($fullBlockId !== null){
				// make sure the chunk/block exists on this thread
				if($iterator->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
					// if replaceAir is false, do not set blocks where the clipboard has air
					if($this->replaceAir || $fullBlockId !== VanillaBlocks::AIR()->getFullId()){
						// if fill is false, ignore interior blocks on the clipboard
						if($this->fill || $x === $minX || $x === $maxX || $y === $minY || $y === $maxY || $z === $minZ || $z === $maxZ){
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
