<?php

declare(strict_types=1);

namespace libEfficientWE\shapes;

use libEfficientWE\task\ClipboardPasteTask;
use libEfficientWE\task\read\ChunksCopyTask;
use libEfficientWE\utils\Clipboard;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\ChunkLoader;
use pocketmine\world\ChunkLockId;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function array_keys;
use function array_map;
use function array_merge;
use function cos;
use function deg2rad;
use function microtime;
use function morton2d_decode;
use function morton2d_encode;
use function morton3d_decode;
use function morton3d_encode;
use function sin;

/**
 * An abstract class for polygonal shapes to interact with the world using {@link ChunksCopyTask} classes and {@link ClipboardPasteTask}.
 *
 * @internal
 * @phpstan-type promiseReturn array{"chunks": Chunk[], "time": float, "blockCount": int}
 */
abstract class Shape{

	private const MORTON_BITS = 2 ** 20; // 2 ^ 20 or 1048576

	protected Clipboard $clipboard;

	protected function __construct(?Clipboard $clipboard = null){
		$this->clipboard = $clipboard ?? new Clipboard();
	}

	/**
	 * @phpstan-return array<int, int|null>
	 */
	public function getClipboardBlocks() : array{
		return $this->clipboard->getFullBlocks();
	}

	abstract public static function fromVector3(Vector3 $min, Vector3 $max) : self;

	abstract public static function fromAABB(AxisAlignedBB $alignedBB) : self;

	public static function validateMortonEncode(float $xDiff, float $yDiff, float $zDiff) : void{
		if($xDiff < self::MORTON_BITS && $yDiff < self::MORTON_BITS && $zDiff < self::MORTON_BITS)
			throw new \InvalidArgumentException("All axis lengths must be less than 2^20 blocks");
	}

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	final public function cut(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		/** @phpstan-var PromiseResolver<promiseReturn> $totalledResolver */
		$totalledResolver = new PromiseResolver();

		$this->copy($world, $worldPos)->onCompletion(
			function(array $value) use ($world, $totalledResolver) : void{
				$this->set($world, VanillaBlocks::AIR(), true, $totalledResolver);
			},
			static fn() => $resolver->reject()
		);
		$totalledResolver->getPromise()->onCompletion(
			static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time])),
			static fn() => $resolver->reject()
		);

		return $resolver->getPromise();
	}

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	abstract public function copy(World $world, Vector3 $worldPos, ?PromiseResolver $resolver = null) : Promise;

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	final public function paste(World $world, Vector3 $worldPos, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new ClipboardPasteTask(
			$world->getId(),
			$chunks,
			$worldPos,
			$this->clipboard->getFullBlocks(),
			$replaceAir,
			static function(array $chunks, int $changedBlocks) use ($world, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!self::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
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

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	abstract public function set(World $world, Block $block, bool $fill, ?PromiseResolver $resolver = null) : Promise;

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	final public function replace(World $world, Block $find, Block $replace, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		[$temporaryChunkLoader, $chunkLockId, $chunks] = $this->prepWorld($world);

		$world->getServer()->getAsyncPool()->submitTask(new ClipboardPasteTask(
			$world->getId(),
			$chunks,
			$this->clipboard->getWorldMin(),
			array_map(static fn(?int $fullBlock) => $fullBlock === $find->getFullId() ? $replace->getFullId() : $fullBlock, $this->clipboard->getFullBlocks()),
			true,
			static function(array $chunks, int $changedBlocks) use ($world, $temporaryChunkLoader, $chunkLockId, $time, $resolver) : void{
				if(!self::resolveWorld($world, array_keys($chunks), $temporaryChunkLoader, $chunkLockId)){
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

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	final public function rotate(World $world, Vector3 $worldPos, Vector3 $relativeCenter, float $roll, float $yaw, float $pitch, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		/** @phpstan-var PromiseResolver<promiseReturn> $totalledResolver */
		$totalledResolver = new PromiseResolver();
		$changedBlocks = 0;

		$this->cut($world, $worldPos)->onCompletion(
			function(array $value) use ($world, $worldPos, $relativeCenter, $roll, $yaw, $pitch, $replaceAir, $totalledResolver, &$changedBlocks) : void{
				['blockCount' => $changedBlocks] = $value;
				$cosYaw = cos(deg2rad($yaw));
				$sinYaw = sin(deg2rad($yaw));
				$cosRoll = cos(deg2rad($roll));
				$sinRoll = sin(deg2rad($roll));
				$cosPitch = cos(deg2rad($pitch));
				$sinPitch = sin(deg2rad($pitch));
				$pos = Vector3::zero();

				$newBlocks = [];
				foreach($this->clipboard->getFullBlocks() as $mortonCode => $block){
					[$x, $y, $z] = morton3d_decode($mortonCode);

					$pos->x = $x - $relativeCenter->x;
					$pos->y = $y - $relativeCenter->y;
					$pos->z = $z - $relativeCenter->z;

					// Apply rotations
					$pos->x *= $cosYaw;
					$pos->x -= $pos->y * $sinYaw;
					$pos->y *= $cosYaw;
					$pos->y += $pos->x * $sinYaw;
					$pos->x *= $cosRoll;
					$pos->x -= $pos->z * $sinRoll;
					$pos->z *= $cosRoll;
					$pos->z += $pos->x * $sinRoll;
					$pos->y *= $cosPitch;
					$pos->y -= $pos->z * $sinPitch;
					$pos->z *= $cosPitch;
					$pos->z += $pos->y * $sinPitch;

					$pos->x += $relativeCenter->x;
					$pos->y += $relativeCenter->y;
					$pos->z += $relativeCenter->z;

					$newBlocks[morton3d_encode((int) $pos->x, (int) $pos->y, (int) $pos->z)] = $block;
				}
				$this->clipboard->setFullBlocks($newBlocks);

				$this->paste($world, $worldPos, $replaceAir, $totalledResolver);
			},
			static fn() => $resolver->reject()
		);
		$totalledResolver->getPromise()->onCompletion(
			static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time, 'blockCount' => $changedBlocks + $value['blockCount']])),
			static fn() => $resolver->reject()
		);

		return $resolver->getPromise();
	}

	/**
	 * @phpstan-param PromiseResolver<promiseReturn>|null $resolver
	 * @phpstan-return Promise<promiseReturn>
	 */
	final public function translate(World $world, Vector3 $worldPos, int $direction, int $offset, bool $replaceAir, ?PromiseResolver $resolver = null) : Promise{
		$time = microtime(true);
		$resolver ??= new PromiseResolver();

		/** @phpstan-var PromiseResolver<promiseReturn> $totalledResolver */
		$totalledResolver = new PromiseResolver();
		$changedBlocks = 0;

		$this->cut($world, $worldPos)->onCompletion(
			function(array $value) use ($world, $worldPos, $direction, $offset, $replaceAir, $totalledResolver, &$changedBlocks) : void{
				['blockCount' => $changedBlocks] = $value;
				$this->paste($world, $worldPos->getSide($direction, $offset), $replaceAir, $totalledResolver);
			},
			static fn() => $resolver->reject()
		);
		$totalledResolver->getPromise()->onCompletion(
			static fn(array $value) => $resolver->resolve(array_merge($value, ['time' => microtime(true) - $time, 'blockCount' => $changedBlocks + $value['blockCount']])),
			static fn() => $resolver->reject()
		);

		return $resolver->getPromise();
	}

	/**
	 * @return array{ChunkLoader, ChunkLockId, array<int, Chunk|null>}
	 */
	final protected function prepWorld(World $world) : array{
		$chunkLockId = new ChunkLockId();

		$temporaryChunkLoader = new class implements ChunkLoader{
		};

		$worldMax = $this->clipboard->getWorldMax();
		$worldMin = $this->clipboard->getWorldMin();
		$minChunkX = $worldMin->x >> 4;
		$minChunkZ = $worldMin->z >> 4;
		$maxChunkX = ($worldMin->x + $worldMax->x) >> 4;
		$maxChunkZ = ($worldMin->z + $worldMax->z) >> 4;

		$chunks = [];
		for($xx = $minChunkX; $xx <= $maxChunkX; ++$xx){
			for($zz = $minChunkZ; $zz <= $maxChunkZ; ++$zz){
				$world->registerChunkLoader($temporaryChunkLoader, $xx, $zz);
				$world->lockChunk($xx, $zz, $chunkLockId);
				$chunks[morton2d_encode($xx, $zz)] = $world->loadChunk($xx, $zz);
			}
		}

		return [$temporaryChunkLoader, $chunkLockId, $chunks];
	}

	/**
	 * @param int[] $chunks Morton 2d encoded chunk coordinates
	 */
	final protected static function resolveWorld(World $world, array $chunks, ChunkLoader $temporaryChunkLoader, ChunkLockId $chunkPopulationLockId) : bool{
		if(!$world->isLoaded()){
			return false;
		}

		foreach($chunks as $chunkHash){
			[$chunkX, $chunkZ] = morton2d_decode($chunkHash);
			$world->unlockChunk($chunkX, $chunkZ, $chunkPopulationLockId);
			$world->unregisterChunkLoader($temporaryChunkLoader, $chunkX, $chunkZ);
		}

		return true;
	}
}
