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
use function count;
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

		[$temporaryChunkLoader, $chunkPopulationLockId, $chunks] = $this->prepWorld($world);

		$this->clipboard->setWorldMin($worldPos)->setWorldMax($worldPos->add($this->radius * 2, $this->radius * 2, $this->radius * 2));

		$world->getServer()->getAsyncPool()->submitTask(new SphereCopyTask(
			$world->getId(),
			$chunks,
			$this->clipboard,
			$this->radius,
			function(Clipboard $clipboard) use ($time, $world, $chunks, $temporaryChunkLoader, $chunkPopulationLockId, $resolver) : void{
				if(!static::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkPopulationLockId)){
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
		));
		return $resolver->getPromise();
	}

	public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$temporaryChunkLoader, $chunkPopulationLockId, $chunks] = $this->prepWorld($world);

		$fullBlocks = $fill ? $this->clipboard->getFullBlocks() :
			array_filter($this->clipboard->getFullBlocks(), function(int $mortonCode) : bool{
				[$x, $y, $z] = morton3d_decode($mortonCode);
				return $x * $x + $y * $y + $z * $z === $this->radius ** 2;
			}, ARRAY_FILTER_USE_KEY);
		$fullBlocks = array_map(static fn(?int $fullBlock) => $block->getFullId(), $fullBlocks);

		$world->getServer()->getAsyncPool()->submitTask(new ClipboardPasteTask(
			$world->getId(),
			$chunks,
			$this->clipboard->getWorldMin(),
			$fullBlocks,
			true,
			static function(array $chunks, int $changedBlocks) use ($world, $temporaryChunkLoader, $chunkPopulationLockId, $time, $resolver) : void{
				if(!static::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkPopulationLockId)){
					$resolver->reject();
					return;
				}

				$resolver->resolve([
					'chunks' => $chunks,
					'time' => microtime(true) - $time,
					'blockCount' => $changedBlocks,
				]);
			}
		));
		return $resolver->getPromise();
	}
}
