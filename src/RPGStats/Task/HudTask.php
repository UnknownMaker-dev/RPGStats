<?php

namespace RPGStats\Task;

use pocketmine\scheduler\Task;
use RPGStats\RPGStatsPlugin;

/** Atualiza a HUD de quem está online. O que não mudou não vira pacote. */
final class HudTask extends Task
{
    public function __construct(private RPGStatsPlugin $plugin) {}

    public function onRun(): void
    {
        $hud = $this->plugin->getHudManager();

        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            $hud->update($player);
        }
    }
}
