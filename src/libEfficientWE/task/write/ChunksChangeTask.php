<?php

declare(strict_types=1);

namespace libEfficientWE\task\write;

use libEfficientWE\utils\Clipboard;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\BiomeArray;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\generator\ThreadLocalGeneratorContext;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function array_map;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * @internal
 * @phpstan-import-type ChunkPosHash from World
 * @phpstan-type OnCompletion \Closure(Chunk $centerChunk, array<int, Chunk> $adjacentChunks, int $changedBlocks) : void
 */
abstract class ChunksChangeTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "onCompletion";

	protected ?string $chunk;

	protected string $adjacentChunks;

	protected string $worldPos;

	protected string $clipboard;

	private int $changedBlocks = 0;

	/**
	 * @param Chunk[]|null[] $adjacentChunks
	 *
	 * @phpstan-param array<ChunkPosHash, Chunk|null> $adjacentChunks
	 * @phpstan-param OnCompletion                    $onCompletion
	 */
	public function __construct(
		protected int $worldId,
		protected int $chunkX,
		protected int $chunkZ,
		?Chunk $chunk,
		array $adjacentChunks,
		Vector3 $worldPos,
		Clipboard $clipboard,
		protected bool $fill,
		protected bool $replaceAir,
		\Closure $onCompletion
	){
		$this->chunk = $chunk !== null ? FastChunkSerializer::serializeTerrain($chunk) : null;

		$this->adjacentChunks = igbinary_serialize(array_map(
			fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
			$adjacentChunks
		)) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->worldPos = igbinary_serialize($worldPos) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->clipboard = igbinary_serialize($clipboard) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->storeLocal(self::TLS_KEY_ON_COMPLETION, $onCompletion);
	}

	public function onRun() : void{
		$context = ThreadLocalGeneratorContext::fetch($this->worldId);
		if($context === null){
			throw new AssumptionFailedError("Generator context should have been initialized before any PopulationTask execution");
		}
		$manager = new SimpleChunkManager($context->getWorldMinY(), $context->getWorldMaxY());

		$chunk = $this->chunk !== null ? FastChunkSerializer::deserializeTerrain($this->chunk) : null;

		/** @var string[] $serialChunks */
		$serialChunks = igbinary_unserialize($this->adjacentChunks);
		/** @var array<ChunkPosHash, Chunk> $chunks */
		$chunks = array_map(
			fn(?string $serialized) => $serialized !== null ? FastChunkSerializer::deserializeTerrain($serialized) : null,
			$serialChunks
		);

		/** @var Vector3 $worldPos */
		$worldPos = igbinary_unserialize($this->worldPos);

		/** @var Clipboard $clipboard */
		$clipboard = igbinary_unserialize($this->clipboard);

		$this->prepChunkManager($manager, $this->chunkX, $this->chunkZ, $chunk); // $chunk will always exist after this call
		foreach($chunks as $relativeChunkHash => $c){
			World::getXZ($relativeChunkHash, $relativeX, $relativeZ);
			$this->prepChunkManager($manager, $this->chunkX + $relativeX, $this->chunkZ + $relativeZ, $c);
		}

		$this->changedBlocks = $this->setBlocks($manager, $clipboard->getFullBlocks(), $worldPos, $worldPos->addVector($clipboard->getWorldMax()->subtractVector($clipboard->getWorldMin())));

		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);

		$serialChunks = [];
		foreach($chunks as $relativeChunkHash => $c){
			World::getXZ($relativeChunkHash, $relativeX, $relativeZ);
			$chunk = $manager->getChunk($this->chunkX + $relativeX, $this->chunkZ + $relativeZ) ?? throw new AssumptionFailedError("Chunk should exist");
			$serialChunks[$relativeChunkHash] = $c->isTerrainDirty() ? FastChunkSerializer::serializeTerrain($chunk) : null;
		}
		$this->adjacentChunks = igbinary_serialize($serialChunks) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	private function prepChunkManager(SimpleChunkManager $manager, int $chunkX, int $chunkZ, ?Chunk &$chunk) : void{
		$manager->setChunk($chunkX, $chunkZ, $chunk ?? new Chunk([], BiomeArray::fill(BiomeIds::OCEAN), false));
		if($chunk === null){
			$chunk = $manager->getChunk($chunkX, $chunkZ);
			if($chunk === null){
				throw new AssumptionFailedError("We just set this chunk, so it must exist");
			}
			$chunk->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
		}
	}

	/**
	 * @phpstan-param array<int, int|null> $fullBlocks
	 */
	abstract protected function setBlocks(SimpleChunkManager $manager, array $fullBlocks, Vector3 $minVector, Vector3 $maxVector) : int;

	public function onCompletion() : void{
		/**
		 * @var \Closure             $onCompletion
		 * @phpstan-var OnCompletion $onCompletion
		 */
		$onCompletion = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);

		$chunk = $this->chunk !== null ?
			FastChunkSerializer::deserializeTerrain($this->chunk) :
			throw new AssumptionFailedError("Center chunk should never be null");

		/**
		 * @var string[]|null[]                          $serialAdjacentChunks
		 * @phpstan-var array<ChunkPosHash, string|null> $serialAdjacentChunks
		 */
		$serialAdjacentChunks = igbinary_unserialize($this->adjacentChunks);
		$adjacentChunks = [];
		foreach($serialAdjacentChunks as $relativeChunkHash => $c){
			if($c !== null){
				$adjacentChunks[$relativeChunkHash] = FastChunkSerializer::deserializeTerrain($c);
			}
		}

		$onCompletion($chunk, $adjacentChunks, $this->changedBlocks);
	}
}
