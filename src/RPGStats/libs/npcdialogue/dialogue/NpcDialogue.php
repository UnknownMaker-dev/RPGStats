<?php

declare(strict_types=1);

namespace RPGStats\libs\npcdialogue\dialogue;

use RPGStats\libs\npcdialogue\dialogue\texture\NpcDialogueTexture;
use pocketmine\player\Player;

interface NpcDialogue{

	public function getName() : string;

	public function getText() : string;

	public function getTexture() : NpcDialogueTexture;

	/**
	 * @return NpcDialogueButton[]
	 */
	public function getButtons() : array;

	public function onPlayerRespond(Player $player, int $button) : void;

	public function onPlayerRespondInvalid(Player $player, int $invalid_response) : void;

	public function onPlayerClose(Player $player) : void;

	public function onPlayerDisconnect(Player $player) : void;
}