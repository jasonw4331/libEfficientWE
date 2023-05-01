<?php

declare(strict_types=1);

namespace libEfficientWE\task;

use libEfficientWE\shapes\Cone;
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
final class ConeTask extends ChunksChangeTask{

	private string $relativeCenter;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $relativeCenter, protected float $radius, protected float $height, protected int $facing, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->relativeCenter = igbinary_serialize($relativeCenter) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	/**
	 * This method is executed on a worker thread to calculate the changes to the chunk. It is assumed the Clipboard
	 * already contains the blocks to be set in the chunk, indexed by their Morton code in {@link Cone::copy()}
	 */
	protected function setBlocks(SimpleChunkManager $manager, int $chunkX, int $chunkZ, Chunk $chunk, Clipboard $clipboard) : Chunk{
		/** @var Vector3 $relativeCenter */
		$relativeCenter = igbinary_unserialize($this->relativeCenter);

		// use clipboard block ids to set blocks in spherical pattern

		$relativeCenter = $clipboard->getRelativePos()->addVector($relativeCenter);
		$relx = $relativeCenter->x;
		$rely = $relativeCenter->y;
		$relz = $relativeCenter->z;

		$iterator = new SubChunkExplorer($manager);

		foreach($clipboard->getFullBlocks() as $mortonCode => $fullBlockId){
			[$x, $y, $z] = morton3d_decode($mortonCode);
			$ax = (int) floor($relx + $x);
			$ay = (int) floor($rely + $y);
			$az = (int) floor($relz + $z);
			if($fullBlockId !== null){
				// make sure the chunk/block exists on this thread
				if($iterator->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
					// if replaceAir is false, do not set blocks where the clipboard has air
					if($this->replaceAir || $fullBlockId !== VanillaBlocks::AIR()->getFullId()){
						// if fill is false, ignore interior blocks on the clipboard in spherical pattern
						if($this->fill || $y >= $this->height * (1 - ($x ** 2 + $z ** 2) / ($this->radius ** 2))){
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
