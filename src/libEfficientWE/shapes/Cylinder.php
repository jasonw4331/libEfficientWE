<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\read\SphereCopyTask;
use libEfficientWE\task\write\CylinderTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function array_map;
use function max;
use function microtime;
use function min;

/**
 * A representation of a cylinder shape. The default axis is {@link Axis::Y}, making the cylinder base at its lowest coordinate, but it can be
 * changed to {@link Axis::X} or {@link Axis::Z} to be horizontal instead.
 */
class Cylinder extends Shape{

	protected float $radius;

	private function __construct(protected Vector3 $centerOfBase, float $radius, protected float $height, protected int $axis){
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

	public function getAxis() : int{
		return $this->axis;
	}

	public static function fromVector3(Vector3 $min, Vector3 $max, int $axis = Axis::Y) : Shape{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		if($axis !== Axis::X && $axis !== Axis::Y && $axis !== Axis::Z){
			throw new \InvalidArgumentException("Axis must be one of Axis::X, Axis::Y or Axis::Z");
		}

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("Diameter and axis lengths must be less than 2^20 blocks");
		}

		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3($minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, $minZ)
		})->subtract($minX, $minY, $minZ);
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2
		};
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ
		};
		return new self($relativeCenterOfBase, $radius, $height, $axis);
	}

	public static function fromAABB(AxisAlignedBB $alignedBB, int $axis = Axis::Y) : Shape{
		$minX = (int) min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int) min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int) min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int) max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int) max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int) max($alignedBB->minZ, $alignedBB->maxZ);
		if($axis !== Axis::X && $axis !== Axis::Y && $axis !== Axis::Z){
			throw new \InvalidArgumentException("Axis must be one of Axis::X, Axis::Y or Axis::Z");
		}

		if(self::canMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ)){
			throw new \InvalidArgumentException("Diameter and axis lengths must be less than 2^20 blocks");
		}

		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3($minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, $minZ)
		})->subtract($minX, $minY, $minZ);
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2
		};
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ
		};
		return new self($relativeCenterOfBase, $radius, $height, $axis);
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId, $centerChunk, $adjacentChunks] = $this->prepWorld($world);

		$maxVector = match ($this->axis) {
			Axis::Y => $this->centerOfBase->add($this->radius, $this->height, $this->radius),
			Axis::X => $this->centerOfBase->add($this->height, $this->radius, $this->radius),
			Axis::Z => $this->centerOfBase->add($this->radius, $this->radius, $this->height)
		};

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($maxVector));

		$world->getServer()->getAsyncPool()->submitTask(new SphereCopyTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$this->radius,
			$this->clipboard,
			static function(Clipboard $clipboard) use ($time, $world, $chunkX, $chunkZ, $centerChunk, $adjacentChunks, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, $chunkX, $chunkZ, $temporaryChunkLoader, $chunkPopulationLockId)){
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

		$world->getServer()->getAsyncPool()->submitTask(new CylinderTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$worldPos,
			$this->clipboard,
			$this->radius,
			$this->height,
			$this->axis,
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

		$world->getServer()->getAsyncPool()->submitTask(new CylinderTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$setClipboard->getWorldMin(),
			$setClipboard,
			$this->radius,
			$this->height,
			$this->axis,
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

		$world->getServer()->getAsyncPool()->submitTask(new CylinderTask(
			$world->getId(),
			$chunkX,
			$chunkZ,
			$centerChunk,
			$adjacentChunks,
			$replaceClipboard->getWorldMin(),
			$replaceClipboard,
			$this->radius,
			$this->height,
			$this->axis,
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
