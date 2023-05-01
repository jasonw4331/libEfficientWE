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
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * @internal
 */
final class CuboidTask extends ChunksChangeTask {

	protected string $relativePos;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $relativePos, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->relativePos = igbinary_serialize($relativePos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Cuboid::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, int $chunkX, int $chunkZ, Chunk $chunk, Clipboard $clipboard) : Chunk{
		/** @var Vector3 $relativePos */
		$relativePos = igbinary_unserialize($this->relativePos);

		// use clipboard block ids to set blocks in cuboid pattern

		$relativePos = $clipboard->getRelativePos()->addVector($relativePos);
		$relx = $relativePos->x;
		$rely = $relativePos->y;
		$relz = $relativePos->z;

		$caps = $clipboard->getCapVector();
		$xCap = $caps->x;
		$yCap = $caps->y;
		$zCap = $caps->z;

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
						if($this->fill || $x === 0 || $x === $xCap || $y === 0 || $y === $yCap || $z === 0 || $z === $zCap) {
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
