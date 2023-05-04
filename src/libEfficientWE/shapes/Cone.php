<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\read\ConeCopyTask;
use libEfficientWE\task\write\ConeTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function array_map;
use function max;
use function microtime;
use function min;

/**
 * A representation of a cone shape. The facing direction determines the face of the cone's tip. The cone's base is
 * always the opposite face of passed {@link Facing} direction.
 */
class Cone extends Shape{

	protected float $radius;

	private function __construct(protected Vector3 $centerOfBase, float $radius, protected float $height, protected int $facing){
		$this->radius = abs($radius);
		parent::__construct(null);
	}

	public function getCenterOfBase() : Vector3{
		return $this->centerOfBase;
	}

	public function getRadius() : float{
		return $this->radius;
	}

	public function getHeight() : float{
		return $this->height;
	}

	public function getDirection() : int{
		return $this->facing;
	}

	/**
	 * Returns the largest {@link Cone} object which fits between of the given {@link Vector3} objects. The cone's tip
	 * will be the difference between the two given {@link Vector3} objects for a given {@link Facing} direction.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max, int $facing = Facing::UP) : Shape{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		Facing::validate($facing);

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("Sphere diameter must be less than 2^20 blocks");
		}

		$axis = Facing::axis($facing);
		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, Facing::isPositive($facing) ? $maxY : $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3(Facing::isPositive($facing) ? $maxX : $minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, Facing::isPositive($facing) ? $maxZ : $minZ),
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		})->subtract($minX, $minY, $minZ);
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};

		return new self($relativeCenterOfBase, $radius, $height, $facing);
	}

	/**
	 * Returns the largest {@link Cone} object which fits inside of the given {@link AxisAlignedBB} object. The cone's
	 * tip will be at the center of the given {@link AxisAlignedBB} object for a given {@link Facing} direction.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB, int $facing = Facing::UP) : Shape{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);
		Facing::validate($facing);

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("Sphere diameter must be less than 2^20 blocks");
		}

		$axis = Facing::axis($facing);
		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, Facing::isPositive($facing) ? $maxY : $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3(Facing::isPositive($facing) ? $maxX : $minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, Facing::isPositive($facing) ? $maxZ : $minZ),
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		})->subtract($minX, $minY, $minZ);
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2,
			default => throw new AssumptionFailedError("Unhandled axis $axis")
		};

		return new self($relativeCenterOfBase, $radius, $height, $facing);
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$maxVector = match ($this->facing) {
			Facing::UP => $this->centerOfBase->add($this->radius, $this->height, $this->radius),
			Facing::DOWN => $this->centerOfBase->add($this->radius, 0, $this->radius),
			Facing::SOUTH => $this->centerOfBase->add($this->radius, $this->radius, $this->height),
			Facing::NORTH => $this->centerOfBase->add($this->radius, $this->radius, 0),
			Facing::EAST => $this->centerOfBase->add($this->height, $this->radius, $this->radius),
			Facing::WEST => $this->centerOfBase->add(0, $this->radius, $this->radius),
		};

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($maxVector));

		$world->getServer()->getAsyncPool()->submitTask(new ConeCopyTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->radius,
			$this->height,
			$this->facing,
			$this->clipboard,
			static function(Clipboard $clipboard) use ($time, $world, $chunkX, $chunkZ, $centerChunk, $adjacentChunks, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!parent::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => [$centerChunk] + $adjacentChunks,
					'time' => microtime(true) - $time,
					'blockCount' => count($clipboard->getFullBlocks()),
				]);
			}
		));
		return $resolver->getPromise();
	}

	public function paste(World $world, Vector3 $worldPos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new ConeTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$worldPos,
			$this->clipboard,
			$this->radius,
			$this->height,
			$this->facing,
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

		$world->getServer()->getAsyncPool()->submitTask(new ConeTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$setClipboard->getWorldMin(),
			$setClipboard,
			$this->radius,
			$this->height,
			$this->facing,
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

		$world->getServer()->getAsyncPool()->submitTask(new ConeTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$replaceClipboard->getWorldMin(),
			$replaceClipboard,
			$this->radius,
			$this->height,
			$this->facing,
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
