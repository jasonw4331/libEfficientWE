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
use function floor;
use function igbinary_serialize;
use function igbinary_unserialize;
use function morton3d_decode;

/**
 * @internal
 */
final class SphereTask extends ChunksChangeTask{

	private string $worldPos;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $worldPos, protected float $radius, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->worldPos = igbinary_serialize($worldPos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Sphere::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, Clipboard $clipboard) : int{
		$changedBlocks = 0;
		/** @var Vector3 $worldPos */
		$worldPos = igbinary_unserialize($this->worldPos);

		$worldCenter = $worldPos->add($this->radius, $this->radius, $this->radius);
		$minX = $worldCenter->x - $this->radius;
		$minY = $worldCenter->y - $this->radius;
		$minZ = $worldCenter->z - $this->radius;

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
						// if fill is false, ignore interior blocks on the clipboard in spherical pattern
						if($this->fill || $x * $x + $y * $y + $z * $z === $this->radius ** 2){
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
