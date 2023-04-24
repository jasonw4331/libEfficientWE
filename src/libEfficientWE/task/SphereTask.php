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
final class SphereTask extends ChunksChangeTask {

	protected string $relativeCenter;

	public function __construct(int $worldId, int $chunkX, int $chunkZ, ?Chunk $chunk, array $adjacentChunks, Clipboard $clipboard, Vector3 $relativeCenter, protected float $radius, bool $fill, bool $replaceAir, \Closure $onCompletion){
		parent::__construct($worldId, $chunkX, $chunkZ, $chunk, $adjacentChunks, $clipboard, $fill, $replaceAir, $onCompletion);
		$this->relativeCenter = igbinary_serialize($relativeCenter) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	protected function setBlocks(SimpleChunkManager $manager, int $chunkX, int $chunkZ, Chunk $chunk, Clipboard $clipboard) : Chunk{
		/** @var Vector3 $relativeCenter */
		$relativeCenter = igbinary_unserialize($this->relativeCenter);

		// use clipboard block ids to set blocks in spherical pattern

		$relativeCenter = $clipboard->getRelativePos()->addVector($relativeCenter);
		$relx = $relativeCenter->x;
		$rely = $relativeCenter->y;
		$relz = $relativeCenter->z;

		$caps = $clipboard->getCapVector();
		$xCap = $caps->x;
		$yCap = $caps->y;
		$zCap = $caps->z;

		$iterator = new SubChunkExplorer($manager);

		for($x = 0; $x <= $xCap; ++$x) {
			$xPos = $relx + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$zPos = $relz + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$yPos = $rely + $y;
					$clipboardFullBlock = $clipboard->getFullBlocks()[World::blockHash($xPos, $yPos, $zPos)] ?? null;
					if($clipboardFullBlock !== null) {
						// if fill is false, ignore interior blocks on the clipboard
						if($this->fill || $x === 0 || $x === $xCap || $y === 0 || $y === $yCap || $z === 0 || $z === $zCap) {
							// if replaceAir is false, do not set blocks where the clipboard has air
							if($this->replaceAir || $clipboardFullBlock !== VanillaBlocks::AIR()->getFullId()) {
								if($iterator->moveTo($xPos, $yPos, $zPos) !== SubChunkExplorerStatus::INVALID) {
									$iterator->currentSubChunk?->setFullBlock($xPos & SubChunk::COORD_MASK, $yPos & SubChunk::COORD_MASK, $zPos & SubChunk::COORD_MASK, $clipboardFullBlock);
									++$this->changedBlocks;
								}
							}
						}
					}
				}
			}
		}

		return $chunk;
	}
}