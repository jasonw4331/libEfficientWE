<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use GlobalLogger;
use InvalidArgumentException;
use libEfficientWE\task\ClipboardPasteTask;
use libEfficientWE\task\read\CylinderCopyTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\World;
use PrefixedLogger;
use function abs;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function max;
use function microtime;
use function min;
use function morton3d_decode;
use const ARRAY_FILTER_USE_KEY;

/**
 * A representation of a cylinder shape. The default axis is {@link Axis::Y}, making the cylinder base at its lowest coordinate, but it can be
 * changed to {@link Axis::X} or {@link Axis::Z} to be horizontal instead.
 */
class Cylinder extends Shape{

	protected float $radius;

	/**
	 * @phpstan-param Axis::* $axis
	 */
	private function __construct(protected Vector3 $centerOfBase, float $radius, protected float $height, protected int $axis){
		$this->radius = abs($radius);
		parent::__construct();
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

	/**
	 * Returns the largest {@link Cylinder} object which fits between the given {@link Vector3} objects. The cylinder's
	 * base will be on the lowest coordinate of the given {@link Axis}.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max, int $axis = Axis::Y) : Shape{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		if($axis !== Axis::X && $axis !== Axis::Y && $axis !== Axis::Z){
			throw new InvalidArgumentException("Axis must be one of Axis::X, Axis::Y or Axis::Z");
		}
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		[$radius, $height] = match ($axis) {
			Axis::Y => [min($maxX - $minX, $maxZ - $minZ) / 2, $maxY - $minY],
			Axis::X => [min($maxY - $minY, $maxZ - $minZ) / 2, $maxX - $minX],
			Axis::Z => [min($maxX - $minX, $maxY - $minY) / 2, $maxZ - $minZ]
		};
		$relativeCenterOfBase = match ($axis) {
			Axis::Y => new Vector3($radius, 0, $radius),
			Axis::X => new Vector3(0, $radius, $radius),
			Axis::Z => new Vector3($radius, $radius, 0)
		};

		$shape = new self($relativeCenterOfBase, $radius, $height, $axis);
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	/**
	 * Returns the largest {@link Cylinder} object which fits inside the given {@link AxisAlignedBB}. The cylinder's
	 * base will be on the lowest coordinate of the given {@link Axis}.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB, int $axis = Axis::Y) : Shape{
		$minX = (int) min($alignedBB->minX, $alignedBB->maxX);
		$minY = (int) min($alignedBB->minY, $alignedBB->maxY);
		$minZ = (int) min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = (int) max($alignedBB->minX, $alignedBB->maxX);
		$maxY = (int) max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = (int) max($alignedBB->minZ, $alignedBB->maxZ);
		if($axis !== Axis::X && $axis !== Axis::Y && $axis !== Axis::Z){
			throw new InvalidArgumentException("Axis must be one of Axis::X, Axis::Y or Axis::Z");
		}
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		[$radius, $height] = match ($axis) {
			Axis::Y => [min($maxX - $minX, $maxZ - $minZ) / 2, $maxY - $minY],
			Axis::X => [min($maxY - $minY, $maxZ - $minZ) / 2, $maxX - $minX],
			Axis::Z => [min($maxX - $minX, $maxY - $minY) / 2, $maxZ - $minZ]
		};
		$relativeCenterOfBase = match ($axis) {
			Axis::Y => new Vector3($radius, 0, $radius),
			Axis::X => new Vector3(0, $radius, $radius),
			Axis::Z => new Vector3($radius, $radius, 0)
		};

		$shape = new self($relativeCenterOfBase, $radius, $height, $axis);
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		$resolver->getPromise()->onCompletion(
			static fn(array $value) => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cylinder Copy operation completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
			static fn() => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Failed to complete task')
		);

		if($this->clipboard->getWorldMax()->distance($this->clipboard->getWorldMin()) < 1) {
			$resolver->reject();
			return $resolver->getPromise();
		}

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$maxVector = match ($this->axis) {
			Axis::Y => $this->centerOfBase->add($this->radius, $this->height, $this->radius),
			Axis::X => $this->centerOfBase->add($this->height, $this->radius, $this->radius),
			Axis::Z => $this->centerOfBase->add($this->radius, $this->radius, $this->height)
		};

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($maxVector));

		$workerPool = $world->getServer()->getAsyncPool();
		$workerId = $workerPool->selectWorker();
		$world->registerGeneratorToWorker($workerId);
		$workerPool->submitTaskToWorker(new CylinderCopyTask(
			$world->getId(),
			$chunks,
			$this->clipboard,
			$this->radius,
			$this->height,
			$this->axis,
			function(Clipboard $clipboard) use ($world, $chunks, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!parent::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
					$resolver->reject();
					return;
				}

				$this->clipboard->setFullBlocks($clipboard->getFullBlocks());

				$resolver->resolve([
					'chunks' => $chunks,
					'time' => microtime(true) - $time,
					'blockCount' => count($clipboard->getFullBlocks()),
				]);
			}
		), $workerId);
		return $resolver->getPromise();
	}

	public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		$resolver->getPromise()->onCompletion(
			static fn(array $value) => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cylinder Set operation completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
			static fn() => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cylinder Set operation failed to complete')
		);

		if(count($this->clipboard->getFullBlocks()) < 1){
			$totalledResolver = new PromiseResolver();
			$this->copy($world, $this->clipboard->getWorldMin())->onCompletion(
				fn (array $value) => $this->set($world, $block, $fill, $totalledResolver), // recursive but the clipboard is now set
				static fn() => $resolver->reject()
			);
			$totalledResolver->getPromise()->onCompletion(
				static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time])),
				static fn() => $resolver->reject()
			);
			return $resolver->getPromise();
		}

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$fullBlocks = $fill ? $this->clipboard->getFullBlocks() :
			array_filter($this->clipboard->getFullBlocks(), function(int $mortonCode) : bool{
				[$x, $y, $z] = morton3d_decode($mortonCode);
				return match ($this->axis) {
					Axis::Y => $y === 0 || $y === $this->height || (new Vector2($x + $this->radius, $z + $this->radius))->distanceSquared(new Vector2($x, $z)) <= $this->radius ** 2,
					Axis::X => $x === 0 || $x === $this->height || (new Vector2($y + $this->radius, $z + $this->radius))->distanceSquared(new Vector2($y, $z)) <= $this->radius ** 2,
					Axis::Z => $z === 0 || $z === $this->height || (new Vector2($x + $this->radius, $y + $this->radius))->distanceSquared(new Vector2($x, $y)) <= $this->radius ** 2,
				};
			}, ARRAY_FILTER_USE_KEY);
		$fullBlocks = array_map(static fn(?int $fullBlock) => $block->getFullId(), $fullBlocks);

		$workerPool = $world->getServer()->getAsyncPool();
		$workerId = $workerPool->selectWorker();
		$world->registerGeneratorToWorker($workerId);
		$workerPool->submitTaskToWorker(new ClipboardPasteTask(
			$world->getId(),
			$chunks,
			$this->clipboard->getWorldMin(),
			$fullBlocks,
			true,
			static function(array $chunks, int $changedBlocks) use ($world, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!parent::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => $chunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		), $workerId);
		return $resolver->getPromise();
	}
}
