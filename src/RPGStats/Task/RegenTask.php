<?php

namespace RPGStats\Task;

use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

/**
 * Recupera mana e vida dos jogadores online, em ciclos.
 *
 * Quem está morto, em modo espectador ou acabou de levar dano fica de fora.
 */
final class RegenTask extends Task
{
    private float $manaPercent;
    private int $manaMinimum;
    private float $healthBase;
    private float $healthPerPoint;
    private int $combatDelayTicks;

    public function __construct(private RPGStatsPlugin $plugin)
    {
        $config = $plugin->getConfig();

        $this->manaPercent = (float) $config->getNested("regen.mana.percentual-por-ciclo", 0.02);
        $this->manaMinimum = (int) $config->getNested("regen.mana.minimo-por-ciclo", 1);
        $this->healthBase = (float) $config->getNested("regen.vida.base-por-ciclo", 0.5);
        $this->healthPerPoint = (float) $config->getNested("regen.vida.por-ponto-de-hp", 0.05);
        $this->combatDelayTicks = (int) $config->getNested("regen.segundos-fora-de-combate", 5) * 20;
    }

    public function onRun(): void
    {
        $manager = $this->plugin->getDataManager();
        $combat = $this->plugin->getCombatTracker();
        $tick = $this->plugin->getServer()->getTick();

        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            $name = $player->getName();

            if (!$player->isAlive() || $player->isSpectator()) {
                continue;
            }

            if ($manager->getStats($name) === null) {
                continue;
            }

            if ($combat->isInCombat($name, $tick, $this->combatDelayTicks)) {
                continue;
            }

            $this->regenerateKi($manager, $name);
            $this->regenerateHealth($manager, $player, $name);
        }
    }

    private function regenerateKi(PlayerDataManager $manager, string $name): void
    {
        $maxKi = $manager->getMaxMana($name);

        if ($manager->getMana($name) >= $maxKi) {
            return;
        }

        $amount = max($this->kiMinimum, $maxKi * $this->kiPercent)
            * $manager->getMultiplier($name, "mana_regen");

        $amount = (int) round($amount);

        if ($amount > 0) {
            $manager->addMana($name, $amount);
        }
    }

    private function regenerateHealth(
        PlayerDataManager $manager,
        Player $player,
        string $name,
    ): void {
        $maxHealth = $player->getMaxHealth();

        if ($player->getHealth() >= $maxHealth) {
            return;
        }

        $amount = ($this->healthBase + $manager->getStat($name, "hp") * $this->healthPerPoint)
            * $manager->getMultiplier($name, "health_regen");

        if ($amount <= 0) {
            return;
        }

        $player->setHealth(min($maxHealth, $player->getHealth() + $amount));
    }
}
