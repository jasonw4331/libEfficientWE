<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\SphereTask;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\World;
use function abs;
use function array_map;
use function floor;
use function max;
use function microtime;
use function min;
use function morton3d_encode;

/**
 * A representation of a sphere shape.
 *
 * @phpstan-import-type BlockPosHash from World
 */
class Sphere extends Shape{

	protected float $radius;

	private function __construct(float $radius){
		$this->radius = abs($radius);
		parent::__construct(null);
	}

	public function getRadius() : float{
		return $this->radius;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max) : Shape{
		$minX = min($min->x, $max->x);
		$maxX = max($min->x, $max->x);

		if(self::canMortonEncode($maxX - $minX, $maxX - $minX, $maxX - $minX)){
			throw new \InvalidArgumentException("Diameter must be less than 2^20 blocks");
		}

		return new self($minX - $maxX / 2);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);

		if(self::canMortonEncode($maxX - $minX, $maxX - $minX, $maxX - $minX)){
			throw new \InvalidArgumentException("Diameter must be less than 2^20 blocks");
		}

		return new self($minX - $maxX / 2);
	}

	public function copy(World $world, Vector3 $worldPos) : void{
		$maxVector = new Vector3($this->radius * 2, $this->radius * 2, $this->radius * 2);
		$minVector = Vector3::zero();

		$maxX = $maxVector->x;
		$maxY = $maxVector->y;
		$maxZ = $maxVector->z;

		$minX = $minVector->x;
		$minY = $minVector->y;
		$minZ = $minVector->z;

		/** @var array<BlockPosHash, int|null> $blocks */
		$blocks = [];
		$subChunkExplorer = new SubChunkExplorer($world);

		$center = new Vector3($this->radius, $this->radius, $this->radius);

		// loop from 0 to max. if coordinate is in sphere, save fullblockId
		for($x = 0; $x <= $maxX; ++$x){
			$ax = (int) floor($minX + $x);
			for($z = 0; $z <= $maxZ; ++$z){
				$az = (int) floor($minZ + $z);
				for($y = 0; $y <= $maxY; ++$y){
					$ay = (int) floor($minY + $y);
					if($center->distanceSquared(new Vector3($x, $y, $z)) <= $this->radius ** 2 && $subChunkExplorer->moveTo($ax, $ay, $az) !== SubChunkExplorerStatus::INVALID){
						$blocks[morton3d_encode($x, $y, $z)] = $subChunkExplorer->currentSubChunk?->getFullBlock($ax & SubChunk::COORD_MASK, $ay & SubChunk::COORD_MASK, $az & SubChunk::COORD_MASK);
					}
				}
			}
		}

		$this->clipboard->setFullBlocks($blocks)->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($maxVector));
	}

	public function paste(World $world, Vector3 $worldPos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new SphereTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->clipboard,
			$worldPos,
			$this->radius,
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

		$world->getServer()->getAsyncPool()->submitTask(new SphereTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$setClipboard,
			$setClipboard->getWorldMin(),
			$this->radius,
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

		$world->getServer()->getAsyncPool()->submitTask(new SphereTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$replaceClipboard,
			$replaceClipboard->getWorldMin(),
			$this->radius,
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
