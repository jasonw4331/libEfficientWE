<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\read\SphereCopyTask;
use libEfficientWE\task\write\SphereTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function array_map;
use function count;
use function max;
use function microtime;
use function min;

/**
 * A representation of a sphere shape.
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

	/**
	 * Returns the largest {@link Sphere} object which fits between the given {@link Vector3} objects.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max) : Shape{
		$minX = min($min->x, $max->x);
		$maxX = max($min->x, $max->x);

		if(self::canMortonEncode($maxX - $minX, $maxX - $minX, $maxX - $minX)){
			throw new \InvalidArgumentException("Diameter must be less than 2^20 blocks");
		}

		return new self($minX - $maxX / 2);
	}

	/**
	 * Returns the largest {@link Sphere} object which fits within the given {@link AxisAlignedBB} object.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);

		if(self::canMortonEncode($maxX - $minX, $maxX - $minX, $maxX - $minX)){
			throw new \InvalidArgumentException("Diameter must be less than 2^20 blocks");
		}

		return new self($minX - $maxX / 2);
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->add($this->radius * 2, $this->radius * 2, $this->radius * 2));

		$world->getServer()->getAsyncPool()->submitTask(new SphereCopyTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->radius,
			$this->clipboard,
			function(Clipboard $clipboard) use ($time, $world, $chunkX, $chunkZ, $centerChunk, $adjacentChunks, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
					$resolver->reject();
					return;
				}

				$this->clipboard->setFullBlocks($clipboard->getFullBlocks());

				$resolver->resolve([
					'chunks' => [$centerChunk] + $adjacentChunks,
					'time' => microtime(true) - $time,
					'blockCount' => count($clipboard->getFullBlocks()),
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
			$setClipboard->getWorldMin(),
			$setClipboard,
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
}
