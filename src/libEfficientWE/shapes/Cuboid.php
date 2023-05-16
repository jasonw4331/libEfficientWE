<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use GlobalLogger;
use libEfficientWE\task\ClipboardPasteTask;
use libEfficientWE\task\read\CuboidCopyTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\World;
use PrefixedLogger;
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
 * A representation of a cuboid shape.
 * @phpstan-import-type promiseReturn from Shape
 */
class Cuboid extends Shape{

	private function __construct(protected Vector3 $highCorner){
		parent::__construct();
	}

	public function getLowCorner() : Vector3{
		return Vector3::zero();
	}

	public function getHighCorner() : Vector3{
		return $this->highCorner;
	}

	/**
	 * Returns the largest {@link Cuboid} object which fits between the given {@link Vector3} objects.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max) : self{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		$shape = new self(new Vector3($maxX - $minX, $maxY - $minY, $maxZ - $minZ));
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	/**
	 * Returns the largest {@link Cuboid} object which fits within the given {@link AxisAlignedBB} object.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB) : self{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		$shape = new self(new Vector3($maxX - $minX, $maxY - $minY, $maxZ - $minZ));
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		$resolver->getPromise()->onCompletion(
			static fn(array $value) => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cuboid Copy operation completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
			static fn() => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cuboid Copy operation failed to complete')
		);

		if($this->clipboard->getWorldMax()->distance($this->clipboard->getWorldMin()) < 1) {
			$resolver->reject();
			return $resolver->getPromise();
		}

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->addVector($this->highCorner));

		$workerPool = $world->getServer()->getAsyncPool();
		$workerId = $workerPool->selectWorker();
		$world->registerGeneratorToWorker($workerId);
		$workerPool->submitTaskToWorker(new CuboidCopyTask(
			$world->getId(),
			$chunks,
			$this->clipboard,
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
			static fn(array $value) => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cuboid Set operation completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
			static fn() => (new PrefixedLogger(GlobalLogger::get(), "libEfficientWE"))->debug('Cuboid Set operation failed to complete')
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

		$fullBlocks = $fill ? $this->clipboard->getFullBlocks() :
			array_filter($this->clipboard->getFullBlocks(), function(int $mortonCode) : bool{
				[$x, $y, $z] = morton3d_decode($mortonCode);
				return $x === 0 || $x === $this->highCorner->x ||
					$y === 0 || $y === $this->highCorner->y ||
					$z === 0 || $z === $this->highCorner->z;
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
