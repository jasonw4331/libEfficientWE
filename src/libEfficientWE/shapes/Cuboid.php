<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\CuboidTask;
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
use function array_map;
use function floor;
use function max;
use function microtime;
use function min;
use function morton3d_encode;

/**
 * A representation of a cuboid shape.
 *
 * @phpstan-import-type BlockPosHash from World
 */
class Cuboid extends Shape{

	private function __construct(protected Vector3 $lowCorner, protected Vector3 $highCorner){
		parent::__construct(null);
	}

	public function getLowCorner() : Vector3{
		return $this->lowCorner;
	}

	public function getHighCorner() : Vector3{
		return $this->highCorner;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : self{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		return new self(new Vector3(0, 0, 0), new Vector3($maxX - $minX, $maxY - $minY, $maxZ - $minZ));
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : self{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);
		return new self(new Vector3(0, 0, 0), new Vector3($maxX - $minX, $maxY - $minY, $maxZ - $minZ));
	}

	public function copy(ChunkManager $world, Vector3 $worldPos) : void{
		$worldLowCorner = $this->lowCorner->addVector($worldPos);
		$worldHighCorner = $this->highCorner->addVector($worldPos);

		$minX = $this->lowCorner->x;
		$minY = $this->lowCorner->y;
		$minZ = $this->lowCorner->z;

		$maxX = $this->highCorner->x;
		$maxY = $this->highCorner->y;
		$maxZ = $this->highCorner->z;

		/** @var array<BlockPosHash, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($world);

		for($x = 0; $x <= $maxX; ++$x){
			$ax = (int) floor($minX + $x);
			for($z = 0; $z <= $maxZ; ++$z){
				$az = (int) floor($minZ + $z);
				for($y = 0; $y <= $maxY; ++$y){
					$ay = (int) floor($minY + $y);
					if($subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setWorldVector($worldLowCorner)->setWorldMax($worldHighCorner);
	}

	public function paste(World $world, Vector3 $worldPos, bool $replaceAir = true, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new CuboidTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->clipboard,
			$worldPos,
			$this->lowCorner,
			$this->highCorner,
			true,
			$replaceAir,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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

		$world->getServer()->getAsyncPool()->submitTask(new CuboidTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$setClipboard,
			$setClipboard->getWorldVector(),
			$this->lowCorner,
			$this->highCorner,
			$fill,
			true,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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

		$world->getServer()->getAsyncPool()->submitTask(new CuboidTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$replaceClipboard,
			$replaceClipboard->getWorldVector(),
			$this->lowCorner,
			$this->highCorner,
			true,
			true,
			static function(Chunk $centerChunk, array $adjacentChunks, int $changedBlocks) use ($time, $world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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
