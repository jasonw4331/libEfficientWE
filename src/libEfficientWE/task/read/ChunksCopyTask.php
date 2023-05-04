<?php

declare(strict_types=1);

namespace libEfficientWE\task\read;

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
 * @phpstan-type OnCompletion \Closure(Clipboard) : void
 */
abstract class ChunksCopyTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "onCompletion";

	protected ?string $chunk;

	protected string $adjacentChunks;

	protected string $worldPos;

	protected string $clipboard;

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
		Clipboard $clipboard,
		\Closure $onCompletion
	){
		$this->chunk = $chunk !== null ? FastChunkSerializer::serializeTerrain($chunk) : null;

		$this->adjacentChunks = igbinary_serialize(array_map(
			fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
			$adjacentChunks
		)) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

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

		$this->prepChunkManager($manager, $this->chunkX, $this->chunkZ, $chunk); // $chunk will always exist after this call
		foreach($chunks as $relativeChunkHash => $c){
			World::getXZ($relativeChunkHash, $relativeX, $relativeZ);
			$this->prepChunkManager($manager, $this->chunkX + $relativeX, $this->chunkZ + $relativeZ, $c);
		}

		/** @var Clipboard $clipboard */
		$clipboard = igbinary_unserialize($this->clipboard);

		/** @var Chunk $chunk */
		$clipboard->setFullBlocks($this->readBlocks($manager, $clipboard->getWorldMin()));

		$this->clipboard = igbinary_serialize($clipboard) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	private function prepChunkManager(SimpleChunkManager $manager, int $chunkX, int $chunkZ, ?Chunk &$chunk) : void{
		$manager->setChunk($chunkX, $chunkZ, $chunk ?? new Chunk([], BiomeArray::fill(BiomeIds::OCEAN), false));
		if($chunk === null){
			$chunk = $manager->getChunk($chunkX, $chunkZ);
			if($chunk === null){
				throw new AssumptionFailedError("We just set this chunk, so it must exist");
			}
		}
	}

	/**
	 * @phpstan-return array<int, int|null>
	 */
	abstract protected function readBlocks(SimpleChunkManager $manager, Vector3 $worldPos) : array;

	public function onCompletion() : void{
		/**
		 * @var \Closure             $onCompletion
		 * @phpstan-var OnCompletion $onCompletion
		 */
		$onCompletion = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);

		/** @var Clipboard $clipboard */
		$clipboard = igbinary_unserialize($this->clipboard);

		$onCompletion($clipboard);
	}
}
