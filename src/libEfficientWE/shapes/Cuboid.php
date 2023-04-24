<?php

declare(strict_types=1);
namespace libEfficientWE\shapes;

use libEfficientWE\task\AsyncCuboidTask;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\World;
use function max;
use function microtime;
use function min;

/**
 * @phpstan-import-type BlockPosHash from World
 */
class Cuboid extends Shape {

	private function __construct(protected Vector3 $lowCorner, protected Vector3 $highCorner) {
		parent::__construct(null);
	}

	public function getLowCorner() : Vector3 {
		return $this->lowCorner;
	}

	public function getHighCorner() : Vector3 {
		return $this->highCorner;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : self {
		$minX = (int) min($min->x, $max->x);
		$minY = (int) min($min->y, $max->y);
		$minZ = (int) min($min->z, $max->z);
		$maxX = (int) max($min->x, $max->x);
		$maxY = (int) max($min->y, $max->y);
		$maxZ = (int) max($min->z, $max->z);
		return new self(new Vector3($minX, $minY, $minZ), new Vector3($maxX, $maxY, $maxZ));
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : self {
		$minX = (int) min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int) min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int) min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int) max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int) max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int) max($alignedBB->minZ, $alignedBB->maxZ);
		return new self(new Vector3($minX, $minY, $minZ), new Vector3($maxX, $maxY, $maxZ));
	}

	public function copy(ChunkManager $world, Vector3 $relativePos) : void {
		$subtractedVector = $this->lowCorner->subtractVector($relativePos);

		$cap = $this->highCorner->subtractVector($this->lowCorner);
		$xCap = $cap->x;
		$yCap = $cap->y;
		$zCap = $cap->z;

		$minX = $this->lowCorner->x;
		$minY = $this->lowCorner->y;
		$minZ = $this->lowCorner->z;

		/** @var array<BlockPosHash, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($world);

		for($x = 0; $x <= $xCap; ++$x) {
			$ax = $minX + $x;
			for($z = 0; $z <= $zCap; ++$z) {
				$az = $minZ + $z;
				for($y = 0; $y <= $yCap; ++$y) {
					$ay = $minY + $y;
					if($subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID) {
						$blocks[World::blockHash($ax, $ay, $az)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setRelativePos($subtractedVector)->setCapVector($cap);
	}

	public function paste(World $world, Vector3 $relativePos, bool $replaceAir = true, ?PromiseResolver $resolver = null) : Promise {
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new AsyncCuboidTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->clipboard,
			$relativePos,
			true,
			$replaceAir,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)) {
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => [$centerChunk] + $adjacentChunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		));
		return $resolver->getPromise();
	}

	public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		// edit all clipboard block ids to be $block->getFullId()
		$setClipboard = clone $this->clipboard;
		$setClipboard->setFullBlocks(array_map(static fn(?int $fullBlock) => $block->getFullId(), $setClipboard->getFullBlocks()));

		$world->getServer()->getAsyncPool()->submitTask(new AsyncCuboidTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$setClipboard,
			$setClipboard->getRelativePos(),
			$fill,
			true,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)) {
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => [$centerChunk] + $adjacentChunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		));
		return $resolver->getPromise();
	}

	public function replace(World $world, Block $find, Block $replace, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		// edit all clipboard block ids to be $block->getFullId()
		$replaceClipboard = clone $this->clipboard;
		$replaceClipboard->setFullBlocks(array_map(static fn(?int $fullBlock) => $fullBlock === $find->getFullId() ? $replace->getFullId() : $fullBlock, $replaceClipboard->getFullBlocks()));

		$world->getServer()->getAsyncPool()->submitTask(new AsyncCuboidTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$replaceClipboard,
			$replaceClipboard->getRelativePos(),
			true,
			true,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)) {
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => [$centerChunk] + $adjacentChunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		));
		return $resolver->getPromise();
	}
}
