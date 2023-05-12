<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\ClipboardPasteTask;
use libEfficientWE\task\read\SphereCopyTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\World;
use function abs;
use function array_filter;
use function array_keys;
use function array_map;
use function constant;
use function count;
use function defined;
use function max;
use function microtime;
use function min;
use function morton3d_decode;
use const ARRAY_FILTER_USE_KEY;

/**
 * A representation of a sphere shape.
 */
class Sphere extends Shape{

	protected float $radius;

	private function __construct(float $radius){
		$this->radius = abs($radius);
		parent::__construct();
	}

	public function getRadius() : float{
		return $this->radius;
	}

	/**
	 * Returns the largest {@link Sphere} object which fits between the given {@link Vector3} objects.
	 */
	public static function fromVector3(Vector3 $min, Vector3 $max) : Shape{
		$minX = min($min->x, $max->x);
		$minY = min($min->y, $max->y);
		$minZ = min($min->z, $max->z);
		$maxX = max($min->x, $max->x);
		$maxY = max($min->y, $max->y);
		$maxZ = max($min->z, $max->z);
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		$shape = new self($minX - $maxX / 2);
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	/**
	 * Returns the largest {@link Sphere} object which fits within the given {@link AxisAlignedBB} object.
	 */
	public static function fromAABB(AxisAlignedBB $alignedBB) : Shape{
		$minX = min($alignedBB->minX, $alignedBB->maxX);
		$minY = min($alignedBB->minY, $alignedBB->maxY);
		$minZ = min($alignedBB->minZ, $alignedBB->maxZ);
		$maxX = max($alignedBB->minX, $alignedBB->maxX);
		$maxY = max($alignedBB->minY, $alignedBB->maxY);
		$maxZ = max($alignedBB->minZ, $alignedBB->maxZ);
		self::validateMortonEncode($maxX - $minX, $maxY - $minY, $maxZ - $minZ);

		$shape = new self($minX - $maxX / 2);
		$shape->clipboard->setWorldMin(new Vector3($minX, $minY, $minZ))->setWorldMax(new Vector3($maxX, $maxY, $maxZ));
		return $shape;
	}

	public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		if(defined('libEfficientWE\LOGGING') && constant('libEfficientWE\LOGGING') === true){
			$resolver->getPromise()->onCompletion(
				static fn(array $value) => (new \PrefixedLogger(\GlobalLogger::get(), "libEfficientWE"))->debug('Completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
				static fn() => (new \PrefixedLogger(\GlobalLogger::get(), "libEfficientWE"))->debug('Failed to complete task')
			);
		}

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->add($this->radius * 2, $this->radius * 2, $this->radius * 2));

		$workerPool = $world->getServer()->getAsyncPool();
		$workerId = $workerPool->selectWorker();
		$world->registerGeneratorToWorker($workerId);
		$workerPool->submitTaskToWorker(new SphereCopyTask(
			$world->getId(),
			$chunks,
			$this->clipboard,
			$this->radius,
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

		if(defined('libEfficientWE\LOGGING') && constant('libEfficientWE\LOGGING') === true){
			$resolver->getPromise()->onCompletion(
				static fn(array $value) => (new \PrefixedLogger(\GlobalLogger::get(), "libEfficientWE"))->debug('Completed in ' . $value['time'] . 'ms with ' . $value['blockCount'] . ' blocks changed'),
				static fn() => (new \PrefixedLogger(\GlobalLogger::get(), "libEfficientWE"))->debug('Failed to complete task')
			);
		}

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$fullBlocks = $fill ? $this->clipboard->getFullBlocks() :
			array_filter($this->clipboard->getFullBlocks(), function(int $mortonCode) : bool{
				[$x, $y, $z] = morton3d_decode($mortonCode);
				return $x * $x + $y * $y + $z * $z === $this->radius ** 2;
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
