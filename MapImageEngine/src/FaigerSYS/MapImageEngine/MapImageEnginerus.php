<?php

namespace FaigerSYS\MapImageEngine;

use pocketmine\plugin\PluginBase;

use pocketmine\utils\TextFormat as CLR;

use pocketmine\item\Item;
use FaigerSYS\MapImageEngine\item\FilledMap as FilledMapItem;

use pocketmine\tile\ItemFrame;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\level\ChunkLoadEvent;

use pocketmine_backtrace\MapInfoRequestPacket;
use pocketmine_backtrace\ClientboundMapItemDataPacket;

use FaigerSYS\MapImageEngine\storage\ImageStorage;

use FaigerSYS\MapImageEngine\command\MapImageEngineCommand;

class MapImageEngine extends PluginBase implements Listener {
	
	/** @var MapImageEngine */
	private static $instance;
	
	/** @var ImageStorage */
	private $storage;
	
	public function onEnable() {
		$is_reload = (self::$instance instanceof MapImageEngine);
		$old_plugin = self::$instance;
		
		$this->getLogger()->info(CLR::GOLD . 'MapImageEngine ' . ($is_reload ? 'пере' : '') . 'загружается...');
		$this->getLogger()->info(CLR::GOLD . 'После добавления новых картинок загрузка может идти несколько секунд, но потом долгой задержки не будет');
		
		self::$instance = $this;
		
		if ($old_plugin) {
			$this->storage = $old_plugin->storage;
		}
		
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		$this->registerCommands();
		$this->registerItems();
		$this->registerPackets();
		
		@mkdir($path = $this->getDataFolder());
		file_put_contents($path . 'README.txt', $this->getResource('README.txt'));
		@mkdir($path . 'images');
		@mkdir($path . 'cache');
		
		$this->loadImages($is_reload);
		
		$this->getLogger()->info(CLR::GOLD . 'MapImageEngine ' . ($is_reload ? 'пере' : '') . 'загружен!');
	}
	
	private function registerCommands() {
		$this->getServer()->getCommandMap()->register('mapimageengine', new MapImageEngineCommand($this));
	}
	
	private function registerItems() {
		foreach (Item::$list as $item) {
			if ($item !== null) {
				Item::$list[Item::FILLED_MAP ?? 358] = is_string($item) ? FilledMapItem::class : new FilledMapItem;
				break;
			}
		}
	}
	
	private function registerPackets() {
		$this->getServer()->getNetwork()->registerPacket(MapInfoRequestPacket::NETWORK_ID, MapInfoRequestPacket::class);
		$this->getServer()->getNetwork()->registerPacket(ClientboundMapItemDataPacket::NETWORK_ID, ClientboundMapItemDataPacket::class);
	}
	
	private function loadImages(bool $is_reload = false) {
		$path = $this->getDataFolder() . 'images/';
		$storage = $this->storage ?? new ImageStorage;
		
		$files = array_filter(
			scandir($path),
			function ($file) {
				return substr($file, -4, 4) === '.mie';
			}
		);
		
		foreach ($files as $file) {
			$state = $storage->addImage($path . $file, substr($file, 0, -4), true);
			switch ($state) {
				case ImageStorage::STATE_NAME_EXISTS:
					!$is_reload && $this->getLogger()->warning('Картинка "' . $file . '": подобное имя уже было зарегестрировано!');
					break;
				
				case ImageStorage::STATE_IMAGE_EXISTS:
					!$is_reload && $this->getLogger()->warning('Картинка "' . $file . '": такая картинка уже существует!');
					break;
				
				case ImageStorage::STATE_CORRUPTED:
					$this->getLogger()->warning('Картинка "' . $file . '": файл поврежден!');
					break;
				
				case ImageStorage::STATE_UNSUPPORTED_API:
					$this->getLogger()->warning('Картинка "' . $file . '": версия API не поддерживается!');
					break;
			}
		}
		
		$this->storage = $storage;
	}
	
	public function getImageStorage() {
		return $this->storage;
	}
	
	/**
	 * @priority LOW
	 */
	public function onRequest(DataPacketReceiveEvent $e) {
		if ($e->getPacket() instanceof MapInfoRequestPacket) {
			$this->getImageStorage()->sendImage($e->getPacket()->mapId, $e->getPlayer());
			$e->setCancelled(true);
		}
	}
	
	/**
	 * @priority LOW
	 */
	public function onChunkLoad(ChunkLoadEvent $e) {
		foreach ($e->getChunk()->getTiles() as $frame) {
			if ($frame instanceof ItemFrame) {
				$item = $frame->getItem();
				if ($item instanceof FilledMapItem) {
					$frame->setItem($item);
				}
			}
		}
	}
	
	public static function getInstance() {
		return self::$instance;
	}
	
}
