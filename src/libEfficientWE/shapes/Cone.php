<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use GlobalLogger;
use libEfficientWE\task\ClipboardPasteTask;
use libEfficientWE\task\read\ConeCopyTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
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
 * A representation of a cone shape. The facing direction determines the face of the cone's tip. The cone's base is
 * always the opposite face of passed {@link Facing} direction.
 * @phpstan-import-type promiseReturn from Shape
 */
class Cone extends Shape{

	protected float $radius;

	/**
	 * @phpstan-param Facing::UP|Facing::DOWN|Facing::NORTH|Facing::SOUTH|Facing::EAST|Facing::WEST $facing
	 */
	private function __construct(protected Vector3 $centerOfBase, float $radius, protected float $height, protected int $facing){
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

	public function getDirection() : int{
		return $this->facing;
	}

	/**
	 * Returns the largest {@link Cone} object which fits between the given {@link Vector3} objects. The cone's tip
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
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		/**
		 * @var Facing::UP|Facing::DOWN|Facing::NORTH|Facing::SOUTH|Facing::EAST|Facing::WEST $facing
		 * @var Axis::*                                                                       $axis
		 */
		$axis = Facing::axis($facing);
		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, Facing::isPositive($facing) ? $maxY : $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3(Facing::isPositive($facing) ? $maxX : $minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, Facing::isPositive($facing) ? $maxZ : $minZ),
		})->subtract($minX, $minY, $minZ);
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ,
		};
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2,
		};

		$shape = new self($relativeCenterOfBase, $radius, $height, $facing);
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	/**
	 * Returns the largest {@link Cone} object which fits within the given {@link AxisAlignedBB} object. The cone's
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
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		/**
		 * @var Facing::UP|Facing::DOWN|Facing::NORTH|Facing::SOUTH|Facing::EAST|Facing::WEST $facing
		 * @var Axis::*                                                                       $axis
		 */
		$axis = Facing::axis($facing);
		$relativeCenterOfBase = (match ($axis) {
			Axis::Y => new Vector3($minX + $maxX / 2, Facing::isPositive($facing) ? $maxY : $minY, $minZ + $maxZ / 2),
			Axis::X => new Vector3(Facing::isPositive($facing) ? $maxX : $minX, $minY + $maxY / 2, $minZ + $maxZ / 2),
			Axis::Z => new Vector3($minX + $maxX / 2, $minY + $maxY / 2, Facing::isPositive($facing) ? $maxZ : $minZ),
		})->subtract($minX, $minY, $minZ);
		$height = match ($axis) {
			Axis::Y => $maxY - $minY,
			Axis::X => $maxX - $minX,
			Axis::Z => $maxZ - $minZ,
		};
		$radius = match ($axis) {
			Axis::Y => min($maxX - $minX, $maxZ - $minZ) / 2,
			Axis::X => min($maxY - $minY, $maxZ - $minZ) / 2,
			Axis::Z => min($maxX - $minX, $maxY - $minY) / 2,
		};

		$shape = new self($relativeCenterOfBase, $radius, $height, $facing);
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		$resolver->getPromise()->onCompletion(
			static fn(array $value) => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cone Copy operation completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
			static fn() => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cone Copy operation failed to complete')
		);

		if($this->clipboard->getWorldMax()->distance($this->clipboard->getWorldMin()) < 1) {
			$resolver->reject();
			return $resolver->getPromise();
		}

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$maxVector = match ($this->facing) {
			Facing::UP => $this->centerOfBase->add($this->radius, $this->height, $this->radius),
			Facing::DOWN => $this->centerOfBase->add($this->radius, 0, $this->radius),
			Facing::SOUTH => $this->centerOfBase->add($this->radius, $this->radius, $this->height),
			Facing::NORTH => $this->centerOfBase->add($this->radius, $this->radius, 0),
			Facing::EAST => $this->centerOfBase->add($this->height, $this->radius, $this->radius),
			Facing::WEST => $this->centerOfBase->add(0, $this->radius, $this->radius),
		};

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($maxVector));

		$workerPool = $world->getServer()->getAsyncPool();
		$workerId = $workerPool->selectWorker();
		$world->registerGeneratorToWorker($workerId);
		$workerPool->submitTaskToWorker(new ConeCopyTask(
			$world->getId(),
			$chunks,
			$this->clipboard,
			$this->radius,
			$this->height,
			$this->facing,
			function(Clipboard $clipboard) use ($world, $chunks, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!parent::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
					$resolver->reject();
					return;
				}

				$this->clipboard->setBlockStateIds($clipboard->getBlockStateIds());

				$resolver->resolve([
					'chunks' => $chunks,
					'time' => microtime(true) - $time,
					'blockCount' => count($clipboard->getBlockStateIds()),
				]);
			}
		), $workerId);
		return $resolver->getPromise();
	}

	public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		$resolver->getPromise()->onCompletion(
			static fn(array $value) => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cone Set operation completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
			static fn() => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cone Set operation failed to complete')
		);

		if(count($this->clipboard->getFullBlocks()) < 1){
			/** @phpstan-var PromiseResolver<promiseReturn> $totalledResolver */
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

		$coneTip = match ($this->facing) {
			Facing::UP => new Vector3($this->radius, $this->height, $this->radius),
			Facing::DOWN => new Vector3($this->radius, 0, $this->radius),
			Facing::SOUTH => new Vector3($this->radius, $this->radius, $this->height),
			Facing::NORTH => new Vector3($this->radius, $this->radius, 0),
			Facing::EAST => new Vector3($this->height, $this->radius, $this->radius),
			Facing::WEST => new Vector3(0, $this->radius, $this->radius)
		};
		$axisVector = $coneTip->subtractVector(match ($this->facing) {
			Facing::UP => new Vector3($this->radius, 0, $this->radius),
			Facing::DOWN => new Vector3($this->radius, $this->height, $this->radius),
			Facing::SOUTH => new Vector3($this->radius, $this->radius, 0),
			Facing::NORTH => new Vector3($this->radius, $this->radius, $this->height),
			Facing::EAST => new Vector3(0, $this->radius, $this->radius),
			Facing::WEST => new Vector3($this->height, $this->radius, $this->radius)
		})->normalize();

		$blockStateIds = $fill ? $this->clipboard->getBlockStateIds() :
			array_filter($this->clipboard->getBlockStateIds(), function(int $mortonCode) use ($coneTip, $axisVector) : bool{
				[$x, $y, $z] = morton3d_decode($mortonCode);
				$relativeVector = (new Vector3($x, $y, $z))->subtractVector($coneTip);
				$projectionLength = $axisVector->dot($relativeVector);
				$projection = $axisVector->multiply($projectionLength);
				$orthogonalVector = $relativeVector->subtractVector($projection);
				$orthogonalDistance = $orthogonalVector->length();
				$maxRadiusAtHeight = $projectionLength / $this->height * $this->radius;
				return $orthogonalDistance >= $maxRadiusAtHeight;
			}, ARRAY_FILTER_USE_KEY);
		$blockStateIds = array_map(static fn(?int $blockStateId) => $blockStateId === null ? null : $block->getStateId(), $blockStateIds);

		$workerPool = $world->getServer()->getAsyncPool();
		$workerId = $workerPool->selectWorker();
		$world->registerGeneratorToWorker($workerId);
		$workerPool->submitTaskToWorker(new ClipboardPasteTask(
			$world->getId(),
			$chunks,
			$this->clipboard->getWorldMin(),
			$blockStateIds,
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
